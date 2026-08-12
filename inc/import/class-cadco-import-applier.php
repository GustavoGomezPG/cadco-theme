<?php

/**
 * Carries out an approved plan.
 *
 * Two ordering rules are load-bearing.
 *
 * First, every term is created before any product is written, so that
 * assigning a product to its categories never has to create one mid-flight.
 *
 * Second, the rewrite rules are flushed exactly once, at the very end.
 * inc/cadco-woocommerce.php registers one literal rewrite rule per category
 * term and sets a flush flag on created_product_cat, edited_product_cat and
 * delete_product_cat. A run that touches 30 terms would otherwise rebuild the
 * whole rule set 30 times.
 */

declare(strict_types=1);

final class CADCO_Import_Applier
{
    private const META_PREFIX = '_cadco_';

    /**
     * Create every term the workbook implies, parents before children.
     *
     * Counts are distinct term IDs actually created or found, not the number
     * of ensure_term() calls — a pair contributes a parent and a child, and
     * the same child name can legitimately exist under two different parents
     * as two distinct terms. Task 11 shows these numbers to the operator, so
     * they must be true rather than an artefact of how many times this loop
     * happened to call ensure_term().
     *
     * @param list<array<string, mixed>> $rows
     * @return array{categories:int,tags:int,brands:int}
     */
    public static function prepare_terms(array $rows): array
    {
        self::reset_taxonomy_once();

        $terms      = CADCO_Import_Plan::all_terms($rows);
        $categories = [];
        $tags       = [];
        $brands     = [];

        foreach ($terms['categories'] as $pair) {
            $parent_id = self::ensure_term($pair['parent'], 'product_cat', 0);

            if ($parent_id > 0) {
                $categories[$parent_id] = true;

                $child_id = self::ensure_term($pair['child'], 'product_cat', $parent_id);

                if ($child_id > 0) {
                    $categories[$child_id] = true;
                }
            }
        }

        foreach ($terms['tags'] as $tag) {
            $tag_id = self::ensure_term($tag, 'product_tag', 0);

            if ($tag_id > 0) {
                $tags[$tag_id] = true;
            }
        }

        foreach ($terms['brands'] as $brand) {
            if (!taxonomy_exists('product_brand')) {
                continue;
            }

            $brand_id = self::ensure_term($brand, 'product_brand', 0);

            if ($brand_id > 0) {
                $brands[$brand_id] = true;
            }
        }

        // A term-creation-heavy request must not let the theme's own
        // shutdown hook (inc/cadco-woocommerce.php) sneak in a flush here —
        // that would make finalise()'s flush the second one for the run
        // rather than the only one. See the class docblock.
        delete_option('cadco_flush_category_rules');

        return [
            'categories' => count($categories),
            'tags'       => count($tags),
            'brands'     => count($brands),
        ];
    }

    /**
     * Run one slice of a previously built queue.
     *
     * The queue is passed in rather than derived from a plan, and that is
     * load-bearing. Re-planning per request would be wrong: once the first
     * batch has written its products, a fresh plan sees them as unchanged and
     * files them under skips, so the queue shrinks — and a fixed offset into a
     * shrinking list silently steps over rows that were never applied. The
     * caller builds the queue once and persists it.
     *
     * @param list<array<string, mixed>> $queue
     * @return array{done:int,total:int,complete:bool,log:list<string>}
     */
    public static function apply_jobs(array $queue, int $offset, int $size): array
    {
        $total = count($queue);
        $log   = [];

        foreach (array_slice($queue, $offset, $size) as $job) {
            $log[] = self::run_job($job);
        }

        $done = min($offset + $size, $total);

        // Mirrors prepare_terms(): a batch that created or edited categories
        // fires the theme's own shutdown-flush; clearing the flag here keeps
        // that flush deferred to finalise(), the only place it should happen.
        delete_option('cadco_flush_category_rules');

        return [
            'done'     => $done,
            'total'    => $total,
            'complete' => $done >= $total,
            'log'      => $log,
        ];
    }

    /**
     * Flatten an approved plan into one ordered list of jobs.
     *
     * Flat so a batch can span operation types and the progress bar advances
     * evenly. Renames that were not approved are dropped here rather than
     * earlier, so the plan the operator reviewed stays intact.
     *
     * Every value in the returned structure is a plain scalar or a nested
     * array of them — no objects, closures or resources — because Task 11
     * persists this queue in a WordPress transient between AJAX requests,
     * and only plain data survives that serialize()/unserialize() round trip
     * intact.
     *
     * @return list<array<string, mixed>>
     */
    public static function build_queue(CADCO_Import_Plan $plan): array
    {
        $queue = [];

        foreach ($plan->creates() as $item) {
            $queue[] = ['op' => 'create', 'row' => $item['row'], 'post_id' => null];
        }

        foreach ($plan->updates() as $item) {
            $queue[] = ['op' => 'update', 'row' => $item['row'], 'post_id' => (int) $item['post_id']];
        }

        foreach ($plan->renames() as $item) {
            if (!empty($item['approved'])) {
                $queue[] = [
                    'op'      => 'rename',
                    'row'     => $item['row'],
                    'post_id' => (int) $item['post_id'],
                    'old_sku' => (string) $item['old_sku'],
                ];
            }
        }

        foreach ($plan->trashes() as $item) {
            $queue[] = ['op' => 'trash', 'post_id' => (int) $item['post_id'], 'sku' => (string) $item['sku']];
        }

        return $queue;
    }

    /**
     * Run one job, never letting it take the batch down with it.
     *
     * set_sku() and set_global_unique_id() can throw WC_Data_Exception — a
     * duplicate SKU, or a GTIN that fails WooCommerce 11's format check
     * (/^[0-9\-]*[0-9Xx]?$/, an 'X' anywhere but the last character throws
     * regardless of uniqueness). One bad row must not abort the AJAX handler
     * mid-batch: whatever was already written stays written, and the
     * operator sees which row failed and why instead of an opaque 500.
     */
    private static function run_job(array $job): string
    {
        try {
            if ($job['op'] === 'trash') {
                wp_trash_post((int) $job['post_id']);

                return sprintf('Trashed %s (#%d)', $job['sku'], (int) $job['post_id']);
            }

            if ($job['op'] === 'rename') {
                $id = self::write_product($job['row'], (int) $job['post_id']);
                self::record_redirect((string) $job['old_sku'], $id);

                return sprintf('Renamed %s to %s (#%d)', $job['old_sku'], $job['row']['Model #'], $id);
            }

            $id = self::write_product($job['row'], $job['post_id'] === null ? null : (int) $job['post_id']);

            return sprintf('%s %s (#%d)', $job['op'] === 'create' ? 'Created' : 'Updated', $job['row']['Model #'], $id);
        } catch (\Throwable $e) {
            $model = (string) ($job['row']['Model #'] ?? $job['sku'] ?? '?');

            return sprintf('FAILED %s: %s', $model, $e->getMessage());
        }
    }

    /**
     * Write one product. Returns its post ID.
     */
    public static function write_product(array $row, ?int $post_id): int
    {
        $sku     = trim((string) ($row['Model #'] ?? ''));
        $product = $post_id === null ? new WC_Product_Simple() : wc_get_product($post_id);

        if (!$product instanceof WC_Product) {
            $product = new WC_Product_Simple();
        }

        // The workbook is third-party data, so it is sanitised here rather than
        // relying on WordPress's kses filters: those only run when the current
        // user lacks unfiltered_html, which makes whether a product name is safe
        // depend on who happened to run the import. A product name is never
        // legitimately HTML, and the_title() does not escape on output.
        $product->set_name(wp_strip_all_tags(trim((string) ($row['Product Name'] ?? ''))));
        $product->set_sku($sku);
        $product->set_slug(sanitize_title($sku));
        $product->set_status('publish');
        $product->set_catalog_visibility('visible');

        // Primary Description is plain prose in the sheet — WooCommerce runs
        // the short description through wpautop on output, not through
        // esc_html, so the same untrusted-input reasoning applies here.
        $product->set_short_description(wp_strip_all_tags(trim((string) ($row['Primary Description'] ?? ''))));

        // WooCommerce strips anything that is not a digit, hyphen or X from
        // this field. CADCO's format (654796-52113-5) passes through intact.
        $product->set_global_unique_id(trim((string) ($row['UPC#'] ?? '')));
        $product->set_description(self::description($row));

        foreach (['Height' => 'height', 'Width' => 'width', 'Depth' => 'length', 'Weight' => 'weight'] as $column => $setter) {
            $value = trim((string) ($row[$column] ?? ''));

            if (is_numeric($value)) {
                $product->{'set_' . $setter}($value);
            }
        }

        $product->set_category_ids(self::category_ids($row));
        $product->set_tag_ids(self::tag_ids($row));
        $product->set_attributes(self::attributes($row));

        $id = $product->save();

        self::write_meta($id, $row);
        self::write_brand($id, $row);

        return (int) $id;
    }

    /**
     * Bullet columns become real lists rather than a wall of text.
     */
    private static function description(array $row): string
    {
        return self::bullet_list((string) ($row['Supplier Specifications - Bullet Points'] ?? ''))
            . self::bullet_list(
                (string) ($row['Secondary Description (Optional)'] ?? ''),
                __('Additional information', 'cadco-theme')
            );
    }

    /**
     * A bullet block as a heading plus a real list, or '' when there is nothing.
     */
    private static function bullet_list(string $raw, string $heading = ''): string
    {
        $lines = CADCO_Import_Normaliser::bullets($raw);

        if ($lines === []) {
            return '';
        }

        $out = $heading === '' ? '' : '<h3>' . esc_html($heading) . "</h3>\n";
        $out .= "<ul>\n";

        foreach ($lines as $line) {
            $out .= '<li>' . esc_html($line) . "</li>\n";
        }

        return $out . "</ul>\n";
    }

    /**
     * @return list<int>
     */
    private static function category_ids(array $row): array
    {
        $ids = [];

        foreach (CADCO_Import_Plan::categories_for($row) as $pair) {
            $parent_id = self::ensure_term($pair['parent'], 'product_cat', 0);
            $child_id  = self::ensure_term($pair['child'], 'product_cat', $parent_id);

            // Only the deepest term is assigned; WooCommerce walks the ancestor
            // chain itself when it builds the URL.
            if ($child_id > 0) {
                $ids[] = $child_id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return list<int>
     */
    private static function tag_ids(array $row): array
    {
        $ids = [];

        foreach (CADCO_Import_Plan::tags_for($row) as $tag) {
            $id = self::ensure_term($tag, 'product_tag', 0);

            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return array<string, WC_Product_Attribute>
     */
    private static function attributes(array $row): array
    {
        $attributes = [];
        $position   = 0;
        $multi      = cadco_import_multi_value_attributes();

        foreach (cadco_import_attribute_columns() as $column => $slug) {
            $raw = trim((string) ($row[$column] ?? ''));

            if ($raw === '' || strcasecmp($raw, 'n/a') === 0) {
                continue;
            }

            $values = in_array($column, $multi, true)
                ? array_filter(array_map('trim', explode(',', $raw)))
                : [$raw];

            $taxonomy = wc_attribute_taxonomy_name($slug);

            if (!taxonomy_exists($taxonomy)) {
                self::ensure_attribute_taxonomy($slug, $column);
            }

            $term_ids = [];

            foreach ($values as $value) {
                $term = term_exists($value, $taxonomy);

                if ($term === null || $term === 0) {
                    $term = wp_insert_term($value, $taxonomy);
                }

                if (!is_wp_error($term)) {
                    $term_ids[] = (int) $term['term_id'];
                }
            }

            if ($term_ids === []) {
                continue;
            }

            $attribute = new WC_Product_Attribute();
            $attribute->set_id(wc_attribute_taxonomy_id_by_name($slug));
            $attribute->set_name($taxonomy);
            $attribute->set_options($term_ids);
            $attribute->set_position($position++);
            $attribute->set_visible(true);
            $attribute->set_variation(false);

            $attributes[$taxonomy] = $attribute;
        }

        return $attributes;
    }

    private static function ensure_attribute_taxonomy(string $slug, string $label): void
    {
        if (wc_attribute_taxonomy_id_by_name($slug) > 0) {
            return;
        }

        wc_create_attribute([
            'name'         => $label,
            'slug'         => $slug,
            'type'         => 'select',
            'order_by'     => 'menu_order',
            'has_archives' => false,
        ]);

        delete_transient('wc_attribute_taxonomies');
        WC_Cache_Helper::invalidate_cache_group('woocommerce-attributes');

        register_taxonomy(
            wc_attribute_taxonomy_name($slug),
            ['product'],
            ['hierarchical' => false, 'show_ui' => false, 'query_var' => false, 'rewrite' => false]
        );
    }

    private static function write_meta(int $post_id, array $row): void
    {
        foreach (cadco_import_meta_columns() as $column => $key) {
            $value = trim((string) ($row[$column] ?? ''));

            if ($value === '') {
                delete_post_meta($post_id, self::META_PREFIX . $key);
                continue;
            }

            update_post_meta($post_id, self::META_PREFIX . $key, $value);
        }

        update_post_meta($post_id, self::META_PREFIX . 'source_sheet', (string) ($row['__sheet'] ?? ''));
        update_post_meta($post_id, self::META_PREFIX . 'source_row', (int) ($row['__row'] ?? 0));
        update_post_meta($post_id, self::META_PREFIX . 'import_hash', CADCO_Import_Planner::hash($row));
    }

    private static function write_brand(int $post_id, array $row): void
    {
        $brand = trim((string) ($row['Brand Name'] ?? ''));

        if ($brand === '' || !taxonomy_exists('product_brand')) {
            return;
        }

        $term_id = self::ensure_term($brand, 'product_brand', 0);

        // A 0 here means ensure_term() failed to find or create the term.
        // Passing it through to wp_set_object_terms() would silently clear
        // whatever brand the product already had rather than leaving it be.
        if ($term_id > 0) {
            wp_set_object_terms($post_id, [$term_id], 'product_brand');
        }
    }

    /**
     * Find or create a term, returning its ID (0 on failure).
     *
     * The lookup is parent-scoped rather than a plain get_term_by('name', ...)
     * — the workbook legitimately reuses a child category name under two
     * different parents (e.g. 'Accessories for Demo / Sampling Carts' under
     * both Countertop Equipment and Foodservice Carts in the corrected
     * sheet), and get_term_by('name', ...) picks arbitrarily among same-name
     * terms across every parent. For whichever homonym it does not pick, the
     * old code fell through to wp_insert_term(), hit a term_exists error,
     * misread that error's data shape, and returned 0 — silently dropping
     * the product's category.
     */
    private static function ensure_term(string $name, string $taxonomy, int $parent): int
    {
        $name = trim($name);

        if ($name === '' || !taxonomy_exists($taxonomy)) {
            return 0;
        }

        $existing = get_terms([
            'taxonomy'   => $taxonomy,
            'name'       => $name,
            'parent'     => $parent,
            'hide_empty' => false,
            'number'     => 1,
        ]);

        if (!is_wp_error($existing) && $existing !== []) {
            return (int) $existing[0]->term_id;
        }

        $created = wp_insert_term($name, $taxonomy, ['parent' => $parent]);

        if (is_wp_error($created)) {
            // term_exists is the expected error when the slug is already
            // taken by a term with a different parent. Its $data is the
            // existing term_id as a plain int, never an array — confirmed
            // against wp-includes/taxonomy.php, where both term_exists
            // returns pass $existing_term->term_id as the WP_Error's $data
            // argument. Any other error code (empty_term_name,
            // invalid_taxonomy, ...) carries no usable term id.
            $data = $created->get_error_data();

            return $created->get_error_code() === 'term_exists' && is_int($data) ? $data : 0;
        }

        return (int) $created['term_id'];
    }

    /**
     * Remember that a product used to live at a different model number.
     */
    private static function record_redirect(string $old_sku, int $post_id): void
    {
        $map = (array) get_option('cadco_import_redirects', []);

        // A canonical-integer SKU ('12345') used as an array key is silently
        // coerced to int by PHP. Casting here keeps the key's type explicit
        // and stops the option's shape from drifting depending on whether a
        // given SKU happens to look numeric.
        $map[(string) $old_sku] = get_permalink($post_id);

        update_option('cadco_import_redirects', $map, false);
    }

    /**
     * Delete the hand-built category tree, exactly once, ever.
     *
     * The catalogue is derived wholly from the workbook, so the 26 terms that
     * predate the importer are removed rather than reused.
     *
     * Guarded by a one-shot option, and that guard is load-bearing. The 26 are
     * *pre-existing* — after the first import there are none left to delete,
     * only derived ones. Re-wiping on every run would churn term IDs, and
     * products skipped as unchanged (§6.1) are never re-assigned, so their
     * term relations would be left pointing at terms that no longer exist.
     *
     * Terms the workbook stops implying are still cleaned up on every run —
     * that is finalise()'s orphan pass, which is a different job.
     */
    private static function reset_taxonomy_once(): void
    {
        if (get_option('cadco_import_taxonomy_reset') === 'done') {
            return;
        }

        foreach (['product_cat', 'product_tag'] as $taxonomy) {
            $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);

            if (is_wp_error($terms)) {
                continue;
            }

            foreach ($terms as $term) {
                // WooCommerce recreates 'uncategorized' as the default term,
                // so deleting it achieves nothing but churn.
                if ($term->slug !== 'uncategorized') {
                    wp_delete_term($term->term_id, $taxonomy);
                }
            }
        }

        update_option('cadco_import_taxonomy_reset', 'done', false);
    }

    /**
     * Everything that must happen once, after all batches.
     */
    public static function finalise(): void
    {
        self::link_related_products();

        foreach (['product_cat', 'product_tag', 'product_brand'] as $taxonomy) {
            foreach (CADCO_Import_Repository::orphan_terms($taxonomy) as $orphan) {
                wp_delete_term($orphan['term_id'], $taxonomy);
            }
        }

        // Exactly one flush per run — see the class docblock.
        delete_option('cadco_flush_category_rules');
        flush_rewrite_rules(false);
    }

    /**
     * Resolve Parent Product references into related-product links.
     *
     * Both products stay simple. The link is stored on the parent as a list of
     * child post IDs and surfaced through the woocommerce_related_products
     * filter, which templates/single-product.html already renders.
     *
     * Rebuilt from scratch every run so a reference removed from the workbook
     * disappears rather than lingering.
     */
    private static function link_related_products(): void
    {
        global $wpdb;

        // update_post_meta() below invalidates the cache for a parent that
        // keeps or gains children, but a parent whose reference was *removed*
        // from the workbook never gets an update_post_meta() call at all —
        // going straight at $wpdb->delete() would leave its cached value
        // stale in the (persistent, on WP Engine's Redis) post_meta object
        // cache group, serving a link that no longer exists in the database.
        // Recording every previously-linked parent up front and clearing its
        // cache after the delete is what makes a removed reference actually
        // disappear.
        $previously_linked = $wpdb->get_col(
            "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_cadco_related_children'"
        );

        $wpdb->delete($wpdb->postmeta, ['meta_key' => '_cadco_related_children']);

        foreach ($previously_linked as $post_id) {
            wp_cache_delete((int) $post_id, 'post_meta');
        }

        $children = $wpdb->get_results(
            "SELECT pm.post_id, pm.meta_value AS parent_sku
               FROM {$wpdb->postmeta} pm
         INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
              WHERE pm.meta_key = '_cadco_parent_model'
                AND pm.meta_value <> ''
                AND p.post_type = 'product'
                AND p.post_status NOT IN ('trash', 'auto-draft')",
            ARRAY_A
        ) ?: [];

        $grouped = [];

        foreach ($children as $child) {
            $parent_id = CADCO_Import_Repository::find_by_sku((string) $child['parent_sku']);

            if ($parent_id !== null) {
                $grouped[$parent_id][] = (int) $child['post_id'];
            }
        }

        foreach ($grouped as $parent_id => $child_ids) {
            update_post_meta($parent_id, '_cadco_related_children', array_values(array_unique($child_ids)));
        }
    }
}

/**
 * Surface imported parent/child links in the Related Products block.
 *
 * WooCommerce generates related products from shared categories and tags, so
 * there is no field to write to — this filter is the supported way in.
 *
 * wc_get_related_products() shuffles this filter's return value and slices it
 * down to $args['limit'] afterwards, unconditionally. Appending children onto
 * whatever the core query already found — a pool of roughly limit + 10 —
 * gave each child only about limit / (limit + 10) odds of surviving that
 * slice. Replacing the pool with just the children when an explicit link
 * exists is what actually guarantees they appear, because a shuffle-then-
 * slice of a pool no larger than the limit keeps every element.
 */
add_filter('woocommerce_related_products', static function ($related, $product_id, $args) {
    $children = get_post_meta((int) $product_id, '_cadco_related_children', true);

    if (!is_array($children) || $children === []) {
        return $related;
    }

    $excluded = array_map('intval', (array) ($args['excluded_ids'] ?? []));
    $excluded[] = (int) $product_id;

    $children = array_values(array_diff(array_unique(array_map('intval', $children)), $excluded));

    return $children !== [] ? $children : $related;
}, 10, 3);
