<?php

/**
 * Share images for product category pages.
 *
 * Pages get their Open Graph image from the SEO step, per page. A category is
 * an archive rather than a page, so it never gets one, and link previews of
 * /products/<category>/ showed an empty tile. A category already carries its
 * own picture -- the lifestyle photo on its home page card -- so that is the
 * one it shares. A sub-category without one borrows the nearest ancestor's.
 *
 * Only when the category has no social image set in Yoast itself: an image
 * chosen there by hand always wins.
 */

declare(strict_types=1);

/**
 * The attachment a category shares: its own thumbnail, else the nearest
 * ancestor's. 0 when the whole branch has none.
 */
function cadco_category_share_image_id(int $term_id): int
{
    $chain = array_merge([$term_id], get_ancestors($term_id, 'product_cat', 'taxonomy'));

    foreach ($chain as $id) {
        $thumb = (int) get_term_meta((int) $id, 'thumbnail_id', true);

        if ($thumb > 0 && wp_attachment_is_image($thumb)) {
            return $thumb;
        }
    }

    return 0;
}

add_action('wpseo_add_opengraph_images', static function ($images): void {
    if (! function_exists('is_product_category') || ! is_product_category()) {
        return;
    }

    $term = get_queried_object();

    if (! $term instanceof WP_Term) {
        return;
    }

    // An image chosen for this category in Yoast is left alone.
    $own = WPSEO_Taxonomy_Meta::get_term_meta($term, 'product_cat', 'opengraph-image');

    if (! empty($own)) {
        return;
    }

    $id = cadco_category_share_image_id((int) $term->term_id);

    if ($id > 0 && is_object($images) && method_exists($images, 'add_image_by_id')) {
        $images->add_image_by_id($id);
    }
});

/* Twitter reads its own tag; give it the same picture. */
add_filter('wpseo_twitter_image', static function ($image) {
    if ($image || ! function_exists('is_product_category') || ! is_product_category()) {
        return $image;
    }

    $term = get_queried_object();
    $id   = $term instanceof WP_Term ? cadco_category_share_image_id((int) $term->term_id) : 0;

    return $id > 0 ? (string) wp_get_attachment_image_url($id, 'large') : $image;
});
