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
     * @return list<array{post_id:int,sku:string,upc:string,hash:string}>
     */
    public static function current_products(): array
    {
        global $wpdb;

        // SKU and UPC come from WooCommerce's lookup table, where both are
        // indexed columns; only the import hash needs a postmeta join.
        $lookup = $wpdb->prefix . 'wc_product_meta_lookup';

        $rows = $wpdb->get_results(
            "SELECT p.ID AS post_id,
                    COALESCE(l.sku, '')              AS sku,
                    COALESCE(l.global_unique_id, '') AS upc,
                    COALESCE(hash.meta_value, '')    AS hash
               FROM {$wpdb->posts} p
          LEFT JOIN {$lookup} l            ON l.product_id = p.ID
          LEFT JOIN {$wpdb->postmeta} hash ON hash.post_id = p.ID AND hash.meta_key = '_cadco_import_hash'
              WHERE p.post_type = 'product'
                AND p.post_status NOT IN ('trash', 'auto-draft')",
            ARRAY_A
        );

        return array_map(
            static fn (array $row): array => [
                'post_id' => (int) $row['post_id'],
                'sku'     => (string) $row['sku'],
                'upc'     => (string) $row['upc'],
                'hash'    => (string) $row['hash'],
            ],
            $rows ?: []
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
