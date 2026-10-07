<?php
/**
 * Take back out everything the importers put in -- products, the resources
 * attached to them, the images sideloaded for either, and the terms they had
 * to create.
 *
 *   wp eval-file scripts/test-data/remove-test-products.php          # dry run
 *   wp eval-file scripts/test-data/remove-test-products.php go  # delete
 *
 * Only objects carrying the importer's own flags are touched, so the six
 * hand-made demo products and the four top-level categories that predate the
 * import survive it. Deletion is permanent (no trash): this is throwaway
 * content and leaving 50 products in the trash would only be a second cleanup.
 */

$args = $args ?? [];
$go   = in_array('go', $args, true);

const CADCO_TEST_FLAG = '_cadco_test_product';
const CADCO_TEST_TERM = '_cadco_test_term';

$products = get_posts([
    'post_type'      => ['product', 'resource'],
    'post_status'    => 'any',
    'posts_per_page' => -1,
    'fields'         => 'ids',
    'meta_key'       => CADCO_TEST_FLAG,
    'meta_value'     => '1',
]);

$attachments = get_posts([
    'post_type'      => 'attachment',
    'post_status'    => 'any',
    'posts_per_page' => -1,
    'fields'         => 'ids',
    'meta_key'       => CADCO_TEST_FLAG,
    'meta_value'     => '1',
]);

/* Every taxonomy the importers write to: the catalogue tree, the Size
   attribute's terms, and the two the resource importer tags with. */
$taxonomies = array_values(array_filter(
    ['product_cat', 'pa_size', 'resource_media', 'resource_product'],
    'taxonomy_exists'
));

$terms = get_terms([
    'taxonomy'   => $taxonomies,
    'hide_empty' => false,
    'meta_key'   => CADCO_TEST_TERM,
    'meta_value' => '1',
]);
$terms = is_wp_error($terms) ? [] : $terms;

WP_CLI::log(sprintf(
    '%d posts, %d images, %d terms carry the test flag.',
    count($products),
    count($attachments),
    count($terms)
));

if (!$go) {
    foreach ($products as $id) {
        WP_CLI::log(sprintf('  %-8s %s', get_post_type($id), get_the_title($id)));
    }
    foreach ($terms as $t) {
        WP_CLI::log('  term     ' . $t->taxonomy . ' / ' . $t->name . ' (' . $t->slug . ')');
    }
    WP_CLI::success('Dry run. Re-run with go to delete.');
    return;
}

foreach ($products as $id) {
    wp_delete_post($id, true);
}

foreach ($attachments as $id) {
    wp_delete_attachment($id, true);
}

// Children first, so a parent is never deleted out from under one.
usort($terms, static function ($a, $b) {
    return count(get_ancestors($b->term_id, $b->taxonomy, 'taxonomy'))
         - count(get_ancestors($a->term_id, $a->taxonomy, 'taxonomy'));
});

foreach ($terms as $t) {
    wp_delete_term($t->term_id, $t->taxonomy);
}

wc_delete_product_transients();
delete_transient('wc_term_counts');

WP_CLI::success(sprintf(
    'deleted %d posts, %d images, %d terms',
    count($products),
    count($attachments),
    count($terms)
));
