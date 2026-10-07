<?php
/**
 * Give the catalogue a Size attribute, so the archive's refinement select has
 * something real to refine by.
 *
 *   wp eval-file scripts/test-data/assign-size-attribute.php           # dry run
 *   wp eval-file scripts/test-data/assign-size-attribute.php go   # write
 *
 * Cadco's own product pages carry the size in two places: a "Size: Half" row in
 * the specification table, and the descriptive line under the model number
 * ("BAKERLUX Half Size, 3 shelf, ..."). Both end up in the imported product, so
 * the size is read back out of them rather than being invented here.
 *
 * Scoped to products the importer flagged. Nothing hand-made is touched, and
 * the terms it creates are flagged too, so remove-test-products.php takes them
 * away again.
 */

if (!function_exists('wc_create_attribute')) {
    WP_CLI::error('WooCommerce is not active.');
}

$args = $args ?? [];
$go   = in_array('go', $args, true);

const CADCO_TEST_FLAG = '_cadco_test_product';
const CADCO_TEST_TERM = '_cadco_test_term';

const CADCO_SIZES = ['Quarter', 'Half', 'Full'];

/**
 * The size this product is, or '' when it does not say.
 *
 * Only the title and the short description are read as free text — they are
 * what the archive card shows, so a card reading "Half Size" should be findable
 * under Half. The body is read only through its "Size:" specification row.
 * Matching the body as free text tagged every full-size oven as half, because
 * their feature lists say "Handles standard half size sheet pans".
 */
function cadco_product_size(WP_Post $post): string
{
    $card = $post->post_title . ' ' . $post->post_excerpt;

    foreach (CADCO_SIZES as $size) {
        if (preg_match('/\b' . $size . '[\s-]+Size\b/i', $card)) {
            return $size;
        }
    }

    // "<strong>Size:</strong> Half" in the specification list.
    if (preg_match('~<strong>\s*Size:?\s*</strong>:?\s*([A-Za-z]+)~i', $post->post_content, $m)) {
        $found = ucfirst(strtolower($m[1]));

        if (in_array($found, CADCO_SIZES, true)) {
            return $found;
        }
    }

    return '';
}

/**
 * The pa_size taxonomy, created on first run.
 *
 * WooCommerce registers attribute taxonomies on `init`, which has already run
 * by the time this script does, so a freshly created attribute has no
 * taxonomy in this process. It is registered by hand for the rest of the run;
 * the next request picks it up from WooCommerce as normal.
 */
function cadco_size_taxonomy(bool $go): string
{
    $taxonomy = wc_attribute_taxonomy_name('size');

    if (taxonomy_exists($taxonomy)) {
        return $taxonomy;
    }

    if (!wc_attribute_taxonomy_id_by_name('size')) {
        if (!$go) {
            return $taxonomy;
        }

        $created = wc_create_attribute([
            'name'         => 'Size',
            'slug'         => 'size',
            'type'         => 'select',
            'order_by'     => 'menu_order',
            'has_archives' => false,
        ]);

        if (is_wp_error($created)) {
            WP_CLI::error('could not create the Size attribute: ' . $created->get_error_message());
        }

        delete_transient('wc_attribute_taxonomies');
        WP_CLI::log('created the Size product attribute');
    }

    register_taxonomy($taxonomy, ['product'], [
        'hierarchical' => false,
        'public'       => true,
        'show_ui'      => false,
        'query_var'    => true,
        'rewrite'      => false,
    ]);

    return $taxonomy;
}

$taxonomy = cadco_size_taxonomy($go);

$products = get_posts([
    'post_type'      => 'product',
    'post_status'    => 'any',
    'posts_per_page' => -1,
    'meta_key'       => CADCO_TEST_FLAG,
    'meta_value'     => '1',
]);

$plan = [];

foreach ($products as $post) {
    $size = cadco_product_size($post);

    if ('' !== $size) {
        $plan[$post->ID] = $size;
    }
}

WP_CLI::log(sprintf('%d of %d flagged products state a size.', count($plan), count($products)));

foreach (array_count_values($plan) as $size => $n) {
    WP_CLI::log(sprintf('  %-8s %d', $size, $n));
}

if (!$go) {
    WP_CLI::success('Dry run. Re-run with go to write.');
    return;
}

$attributeId = wc_attribute_taxonomy_id_by_name('size');
$written     = 0;

foreach ($plan as $id => $size) {
    $term = get_term_by('name', $size, $taxonomy);

    if (!$term instanceof WP_Term) {
        $created = wp_insert_term($size, $taxonomy);

        if (is_wp_error($created)) {
            WP_CLI::warning("term {$size}: " . $created->get_error_message());
            continue;
        }

        add_term_meta((int) $created['term_id'], CADCO_TEST_TERM, '1', true);
        $term = get_term((int) $created['term_id'], $taxonomy);
    }

    wp_set_object_terms($id, [(int) $term->term_id], $taxonomy);

    /*
     * The term alone is not enough. WooCommerce reads a product's attributes
     * from _product_attributes, and a taxonomy term that is not listed there is
     * invisible in the admin and in the product's own Additional information
     * table — the archive would filter by something the product never admits to.
     */
    $product   = wc_get_product($id);
    $attribute = new WC_Product_Attribute();
    $attribute->set_id($attributeId);
    $attribute->set_name($taxonomy);
    $attribute->set_options([(int) $term->term_id]);
    $attribute->set_visible(true);
    $attribute->set_variation(false);

    $existing            = $product->get_attributes();
    $existing[$taxonomy] = $attribute;
    $product->set_attributes($existing);
    $product->save();

    $written++;
}

wc_delete_product_transients();
delete_transient('wc_attribute_taxonomies');

WP_CLI::success("set Size on {$written} products");
