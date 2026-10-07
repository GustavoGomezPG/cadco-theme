<?php
/**
 * Import throwaway catalogue content so the product archive has something real to lay out.
 *
 * Everything this writes — products, the categories it had to create, and the
 * images it sideloaded — is tagged, so remove-test-products.php can take all of
 * it back out again without touching anything hand-made.
 *
 *   wp eval-file scripts/test-data/import-test-products.php <products.json> [sync]
 *
 * Re-running is safe: a product whose SKU is already in the catalogue is left
 * alone, so an interrupted run can simply be repeated. Pass `sync` to push a
 * changed JSON onto the products already imported -- it rewrites the name,
 * price and descriptions and touches nothing else.
 *
 * Source data is scraped from the live cadco-ltd.com catalogue; each product
 * keeps the URL it came from in _cadco_test_source.
 */

if (!class_exists('WooCommerce')) {
    WP_CLI::error('WooCommerce is not active.');
}

$args = $args ?? [];
$file = $args[0] ?? '';
$sync = in_array('sync', $args, true);

if (!$file || !is_readable($file)) {
    WP_CLI::error('Pass a readable products JSON file.');
}

$rows = json_decode(file_get_contents($file), true);

if (!is_array($rows) || !$rows) {
    WP_CLI::error('No products in ' . $file);
}

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

const CADCO_TEST_FLAG = '_cadco_test_product';
const CADCO_TEST_TERM = '_cadco_test_term';

/**
 * Find or create a product category, remembering the ones we had to create.
 *
 * Terms that already existed are returned untouched and unflagged, so the
 * cleanup never deletes a category someone set up by hand.
 */
function cadco_test_term(string $slug, ?string $name, ?int $parent): int
{
    $existing = get_term_by('slug', $slug, 'product_cat');

    if ($existing instanceof WP_Term) {
        return (int) $existing->term_id;
    }

    $created = wp_insert_term($name ?: ucwords(str_replace('-', ' ', $slug)), 'product_cat', [
        'slug'   => $slug,
        'parent' => $parent ?: 0,
    ]);

    if (is_wp_error($created)) {
        WP_CLI::warning("term {$slug}: " . $created->get_error_message());
        return 0;
    }

    add_term_meta((int) $created['term_id'], CADCO_TEST_TERM, '1', true);

    return (int) $created['term_id'];
}

/**
 * Build the long description from the specifications table and feature list.
 *
 * Kept as simple markup rather than the source page's HTML: that HTML carries
 * editor cruft (redactor spans, PDF links, YouTube embeds) that would only have
 * to be stripped again.
 */
function cadco_test_description(array $row): string
{
    $out = '';

    /* Deliberately no opening paragraph. It would repeat the short description
       word for word, and the product hero treats the description's leading
       prose as the product's intro -- so a repeat would print the same line
       twice, once as bullets and once as a paragraph above them. */

    if (!empty($row['specs'])) {
        $out .= '<h3>Specifications</h3><ul>';
        foreach ($row['specs'] as [$k, $v]) {
            $out .= '<li><strong>' . esc_html($k) . ':</strong> ' . esc_html($v) . '</li>';
        }
        $out .= '</ul>';
    }

    if (!empty($row['features'])) {
        $out .= '<h3>Features</h3><ul>';
        foreach ($row['features'] as $f) {
            $out .= '<li>' . esc_html($f) . '</li>';
        }
        $out .= '</ul>';
    }

    return $out;
}

$made = 0;
$skipped = 0;
$synced = 0;
$images = 0;

foreach ($rows as $row) {
    $sku = (string) $row['sku'];

    $existing = wc_get_product_id_by_sku($sku);

    if ($existing) {
        if (!$sync) {
            $skipped++;
            continue;
        }

        /* Sync rewrites only what this file is the source of -- name, price and
           the two descriptions. Categories, images and the Size attribute are
           left alone: they are set elsewhere and a sync must not undo them. */
        $product = wc_get_product($existing);
        $product->set_name($row['title']);
        $product->set_regular_price($row['price']);
        $product->set_short_description($row['short']);
        $product->set_description(cadco_test_description($row));
        $product->save();

        $synced++;
        continue;
    }

    // Categories, parents before children, so a child can be hung off its parent.
    $term_ids = [];
    $by_slug  = [];

    foreach ($row['terms'] as $t) {
        $parent_id = $t['parent'] ? ($by_slug[$t['parent']] ?? 0) : 0;
        $id = cadco_test_term($t['slug'], $t['name'], $parent_id);

        if ($id) {
            $by_slug[$t['slug']] = $id;
            $term_ids[] = $id;
        }
    }

    $product = new WC_Product_Simple();
    $product->set_name($row['title']);
    $product->set_sku($sku);
    $product->set_status('publish');
    $product->set_catalog_visibility('visible');
    $product->set_regular_price($row['price']);
    $product->set_short_description($row['short']);
    $product->set_description(cadco_test_description($row));
    $product->set_category_ids($term_ids);
    $id = $product->save();

    if (!$id) {
        WP_CLI::warning("could not save {$sku}");
        continue;
    }

    update_post_meta($id, CADCO_TEST_FLAG, '1');
    update_post_meta($id, '_cadco_test_source', esc_url_raw($row['source']));

    if (!empty($row['image'])) {
        $attachment = media_sideload_image($row['image'], $id, $row['title'], 'id');

        if (is_wp_error($attachment)) {
            WP_CLI::warning("{$sku} image: " . $attachment->get_error_message());
        } else {
            update_post_meta((int) $attachment, CADCO_TEST_FLAG, '1');
            set_post_thumbnail($id, (int) $attachment);
            $images++;
        }
    }

    $made++;
    WP_CLI::log(sprintf('  %-18s %s', $sku, $row['title']));
}

wc_delete_product_transients();
delete_transient('wc_term_counts');

WP_CLI::success("created {$made}, synced {$synced}, skipped {$skipped} already present, {$images} images");
