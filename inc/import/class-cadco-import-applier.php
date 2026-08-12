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
     * @param list<array<string, mixed>> $rows
     * @return array{categories:int,tags:int,brands:int}
     */
    public static function prepare_terms(array $rows): array
    {
        self::reset_taxonomy_once();

        $terms  = CADCO_Import_Plan::all_terms($rows);
        $counts = ['categories' => 0, 'tags' => 0, 'brands' => 0];

        foreach ($terms['categories'] as $pair) {
            $parent_id = self::ensure_term($pair['parent'], 'product_cat', 0);

            if ($parent_id > 0) {
                $counts['categories']++;
                self::ensure_term($pair['child'], 'product_cat', $parent_id);
                $counts['categories']++;
            }
        }

        foreach ($terms['tags'] as $tag) {
            if (self::ensure_term($tag, 'product_tag', 0) > 0) {
                $counts['tags']++;
            }
        }

        foreach ($terms['brands'] as $brand) {
            if (taxonomy_exists('product_brand') && self::ensure_term($brand, 'product_brand', 0) > 0) {
                $counts['brands']++;
            }
        }

        return $counts;
    }

    /**
     * One slice of the plan.
     *
     * The work is flattened into a single ordered list so that a batch can
     * span operation types and the progress bar advances evenly. Renames that
     * were not approved are dropped here rather than earlier, so the plan the
     * operator saw stays intact.
     *
     * @return array{done:int,total:int,complete:bool,log:list<string>}
     */
    public static function apply_batch(CADCO_Import_Plan $plan, int $offset, int $size): array
    {
        $queue = self::queue($plan);
        $total = count($queue);
        $log   = [];

        foreach (array_slice($queue, $offset, $size) as $job) {
            $log[] = self::run_job($job);
        }

        $done = min($offset + $size, $total);

        return [
            'done'     => $done,
            'total'    => $total,
            'complete' => $done >= $total,
            'log'      => $log,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function queue(CADCO_Import_Plan $plan): array
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

    private static function run_job(array $job): string
    {
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

        $product->set_name(trim((string) ($row['Product Name'] ?? '')));
        $product->set_sku($sku);
        $product->set_slug(sanitize_title($sku));
        $product->set_status('publish');
        $product->set_catalog_visibility('visible');
        $product->set_short_description(trim((string) ($row['Primary Description'] ?? '')));

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

        wp_set_object_terms($post_id, [self::ensure_term($brand, 'product_brand', 0)], 'product_brand');
    }

    /**
     * Find or create a term, returning its ID (0 on failure).
     */
    private static function ensure_term(string $name, string $taxonomy, int $parent): int
    {
        $name = trim($name);

        if ($name === '' || !taxonomy_exists($taxonomy)) {
            return 0;
        }

        $existing = get_term_by('name', $name, $taxonomy);

        if ($existing instanceof WP_Term && (int) $existing->parent === $parent) {
            return $existing->term_id;
        }

        $created = wp_insert_term($name, $taxonomy, ['parent' => $parent]);

        if (is_wp_error($created)) {
            // term_exists is the expected error when the slug is already taken
            // by a term with a different parent.
            $data = $created->get_error_data();

            return is_array($data) && isset($data['term_id']) ? (int) $data['term_id'] : 0;
        }

        return (int) $created['term_id'];
    }

    /**
     * Remember that a product used to live at a different model number.
     */
    private static function record_redirect(string $old_sku, int $post_id): void
    {
        $map               = (array) get_option('cadco_import_redirects', []);
        $map[$old_sku]     = get_permalink($post_id);

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

        $wpdb->delete($wpdb->postmeta, ['meta_key' => '_cadco_related_children']);

        $children = $wpdb->get_results(
            "SELECT post_id, meta_value AS parent_sku
               FROM {$wpdb->postmeta}
              WHERE meta_key = '_cadco_parent_model'
                AND meta_value <> ''",
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
 */
add_filter('woocommerce_related_products', static function ($related, $product_id) {
    $children = get_post_meta((int) $product_id, '_cadco_related_children', true);

    if (is_array($children) && $children !== []) {
        $related = array_values(array_unique(array_merge((array) $related, array_map('intval', $children))));
    }

    return $related;
}, 10, 2);
