<?php

/**
 * Reads the current catalogue out of WordPress.
 *
 * Deliberately the only unit that queries the database for product state. The
 * planner takes its "current state" as an argument instead of calling this,
 * which is what allows the rename and zero-write cases to be unit-tested with
 * no WordPress at all.
 */

declare(strict_types=1);

final class CADCO_Import_Repository
{
    /**
     * Every product the importer is allowed to consider, with the three facts
     * the planner needs about each.
     *
     * Trashed products are excluded: a product removed by a previous run must
     * not be resurrected as an "update" by the next one, and a genuinely
     * returning product is correctly treated as a create.
     *
     * @return list<array{post_id:int,sku:string,upc:string,hash:string,snapshot:array<string,string>,snapshot_unreadable:bool}>
     */
    public static function current_products(): array
    {
        global $wpdb;

        // SKU and UPC come from WooCommerce's lookup table, where both are
        // indexed columns; only the import hash and snapshot need a postmeta
        // join.
        $lookup       = $wpdb->prefix . 'wc_product_meta_lookup';
        $snapshot_key = '_cadco_' . cadco_import_snapshot_meta_key();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.ID AS post_id,
                        COALESCE(l.sku, '')              AS sku,
                        COALESCE(l.global_unique_id, '') AS upc,
                        COALESCE(hash.meta_value, '')    AS hash,
                        snap.meta_value                  AS snapshot
                   FROM {$wpdb->posts} p
              LEFT JOIN {$lookup} l            ON l.product_id = p.ID
              LEFT JOIN {$wpdb->postmeta} hash ON hash.post_id = p.ID AND hash.meta_key = '_cadco_import_hash'
              LEFT JOIN {$wpdb->postmeta} snap ON snap.post_id = p.ID AND snap.meta_key = %s
                  WHERE p.post_type = 'product'
                    AND p.post_status NOT IN ('trash', 'auto-draft')",
                $snapshot_key
            ),
            ARRAY_A
        );

        return array_map(
            static function (array $row): array {
                // Unlike sku/upc/hash above, snap.meta_value is deliberately
                // not wrapped in COALESCE(..., ''): a genuinely missing
                // postmeta row must stay distinguishable, as PHP null, from
                // one that exists but holds something json_decode() cannot
                // parse. Both end up presented to the planner as an empty
                // 'snapshot' array — CADCO_Import_Planner::diff() is typed
                // to take an array, and either way there is no usable
                // payload to diff against — but they are not the same
                // situation, and 'snapshot_unreadable' is how the two stay
                // told apart for the admin screen (see render_update_table()):
                // "this product predates diff tracking" is true of the
                // first and false, misleadingly so, of the second.
                $raw     = $row['snapshot'] ?? null;
                $decoded = $raw === null ? null : json_decode((string) $raw, true);

                return [
                    'post_id'             => (int) $row['post_id'],
                    'sku'                 => (string) $row['sku'],
                    'upc'                 => (string) $row['upc'],
                    'hash'                => (string) $row['hash'],
                    'snapshot'            => is_array($decoded) ? $decoded : [],
                    'snapshot_unreadable' => $raw !== null && !is_array($decoded),
                ];
            },
            $rows ?: []
        );
    }

    /**
     * Every product carrying a legacy `_cadco_legacy_url`, with its current
     * permalink alongside.
     *
     * Deliberately a live query rather than anything cached or persisted —
     * CADCO_Import_Admin::redirect_map() derives the legacy half of the
     * redirect map from this at export time (design rationale in the class
     * docblock there), specifically so a product recategorised after import
     * exports its *current* URL, not whatever it was on the run that first
     * wrote the meta.
     *
     * Same non-trash restriction as current_products(): a trashed product's
     * old-site URL should 404, not redirect to a page that no longer exists.
     * Blank _cadco_legacy_url is excluded at the SQL level — write_meta()
     * deletes the meta entirely for a blank 'Website URL' cell rather than
     * storing '', but the exclusion costs nothing and does not depend on
     * that.
     *
     * Ordered by SKU ascending (post_id as a tie-breaker, which duplicate
     * SKUs would need but the validator already forbids). This is load-
     * bearing, not cosmetic: several rows in the real workbook carry the
     * exact same legacy path for two different products (e.g.
     * '/product/xhc-020-p1' claimed by both XHC-020-P1 and XHC-020-S1), and
     * CADCO_Import_Admin::redirect_map() keys its map by that path, so the
     * *last* row this method returns for a contested path is the one whose
     * permalink wins. MySQL gives no ordering guarantee at all without an
     * explicit ORDER BY — the previous version of this query had none, so
     * the winner for a contested path could silently change between two
     * exports of an unchanged catalogue (a different query plan, a
     * different row order after a re-import), and CADCO would have no way
     * to tell a real recategorisation from an arbitrary re-shuffle. Sorting
     * by SKU makes the winner a stable, statable fact ("the
     * alphabetically-last SKU wins") rather than an accident of MySQL's
     * internals.
     *
     * @return list<array{post_id:int,legacy:string,permalink:string}>
     */
    public static function legacy_urls(): array
    {
        global $wpdb;

        // Same lookup table current_products() already joins for SKU.
        $lookup = $wpdb->prefix . 'wc_product_meta_lookup';

        $rows = $wpdb->get_results(
            "SELECT p.ID AS post_id, pm.meta_value AS legacy
               FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_cadco_legacy_url'
          LEFT JOIN {$lookup} l           ON l.product_id = p.ID
              WHERE p.post_type = 'product'
                AND p.post_status NOT IN ('trash', 'auto-draft')
                AND pm.meta_value <> ''
           ORDER BY l.sku ASC, p.ID ASC",
            ARRAY_A
        ) ?: [];

        return array_map(
            static fn (array $row): array => [
                'post_id'   => (int) $row['post_id'],
                'legacy'    => (string) $row['legacy'],
                'permalink' => (string) get_permalink((int) $row['post_id']),
            ],
            $rows
        );
    }

    public static function find_by_sku(string $sku): ?int
    {
        $id = wc_get_product_id_by_sku($sku);

        return $id > 0 ? $id : null;
    }

    /**
     * @return list<array{term_id:int,name:string,slug:string,parent:int}>
     */
    public static function category_terms(): array
    {
        $terms = get_terms([
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
        ]);

        if (is_wp_error($terms)) {
            return [];
        }

        return array_map(
            static fn (WP_Term $t): array => [
                'term_id' => $t->term_id,
                'name'    => $t->name,
                'slug'    => $t->slug,
                'parent'  => $t->parent,
            ],
            $terms
        );
    }

    /**
     * Terms holding no products, so a run can clean up after itself.
     *
     * 'uncategorized' is excluded: WooCommerce treats it as the default term
     * and recreates it, so deleting it achieves nothing but churn.
     *
     * @return list<array{term_id:int,name:string}>
     */
    public static function orphan_terms(string $taxonomy): array
    {
        $terms = get_terms([
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
        ]);

        if (is_wp_error($terms)) {
            return [];
        }

        $orphans = [];

        foreach ($terms as $term) {
            if ($term->count > 0 || $term->slug === 'uncategorized') {
                continue;
            }

            // A parent whose children still hold products is not an orphan.
            $children = get_term_children($term->term_id, $taxonomy);

            if (!is_wp_error($children) && $children !== []) {
                continue;
            }

            $orphans[] = ['term_id' => $term->term_id, 'name' => $term->name];
        }

        return $orphans;
    }
}
