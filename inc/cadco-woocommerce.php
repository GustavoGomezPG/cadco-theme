<?php
/**
 * WooCommerce: catalogue only, no commerce.
 *
 * This site uses WooCommerce for its product post type and admin editing
 * experience — variations, attributes, galleries, categories — and for the
 * product URL structure. It does not sell anything: there is no cart, no
 * checkout, no payment.
 *
 * WooCommerce core has no setting for this. Verified against 10.9.4: there is
 * no catalog-mode option, and the official Cart and Checkout FAQ documents no
 * supported way to disable purchasing. So it is done with hooks, each one
 * targeted at a specific surface.
 *
 * Products deliberately stay PUBLIC. Making the post type non-public would
 * destroy the /%product_cat%/product URLs this site is built around.
 */

/**
 * Whether the commerce-off layer is active.
 *
 * Filterable so a staging or future build can switch selling back on without
 * unpicking this file.
 */
function cadco_commerce_disabled(): bool
{
    return (bool) apply_filters('cadco_commerce_disabled', true);
}

/**
 * Nothing is purchasable.
 *
 * This is the single gate WooCommerce checks before anything can be bought
 * (WC_Product::is_purchasable, abstract-wc-product.php). With it false, the
 * add-to-cart form and loop buttons render nothing purchasable, and direct
 * ?add-to-cart= requests are refused — so this is the backstop that makes the
 * template changes below cosmetic rather than load-bearing.
 */
add_filter('woocommerce_is_purchasable', function ($purchasable, $product) {
    return cadco_commerce_disabled() ? false : $purchasable;
}, 10, 2);

/**
 * Strip the add-to-cart templates from the classic template hooks.
 *
 * The block templates in templates/ already omit these blocks, but WooCommerce
 * still renders classic template parts in places the block templates do not
 * cover (related-products patterns, shortcodes, any classic fallback), so the
 * actions are removed at the source too.
 */
add_action('init', function () {
    if (!cadco_commerce_disabled()) {
        return;
    }

    remove_action('woocommerce_after_shop_loop_item', 'woocommerce_template_loop_add_to_cart', 10);
    remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30);
}, 20);

/**
 * Load no payment gateways at all.
 */
add_filter('woocommerce_payment_gateways', function ($gateways) {
    return cadco_commerce_disabled() ? [] : $gateways;
});

/**
 * Send anyone who reaches cart, checkout or my-account to the product catalogue.
 *
 * Redirect rather than trashing the pages: WooCommerce reads those page IDs in
 * a number of admin checks, and keeping them means selling can be switched back
 * on with the filter above rather than by rebuilding pages.
 */
add_action('template_redirect', function () {
    if (!cadco_commerce_disabled() || is_admin()) {
        return;
    }

    if (!function_exists('is_cart')) {
        return;
    }

    if (is_cart() || is_checkout() || is_account_page()) {
        $shop = function_exists('wc_get_page_id') ? wc_get_page_id('shop') : 0;
        $target = ($shop && $shop > 0) ? get_permalink($shop) : home_url('/');

        wp_safe_redirect($target, 302);
        exit;
    }
});

/**
 * Drop the cart-fragments script.
 *
 * It polls wp-admin/admin-ajax.php on every front-end page view to keep a cart
 * total in sync. With no cart, that is pure request overhead.
 */
add_action('wp_enqueue_scripts', function () {
    if (cadco_commerce_disabled()) {
        wp_dequeue_script('wc-cart-fragments');
    }
}, 20);

/**
 * Let the product catalogue take part in Taxi navigation.
 *
 * proto_taxi_ignore_urls() marks the shop page as a full page load because the
 * stock WooCommerce block templates carry no [data-taxi-view] wrapper for Taxi
 * to swap. This theme overrides single-product.html and archive-product.html
 * with wrapped versions, so that reasoning no longer applies here and the
 * catalogue should transition like every other route.
 *
 * Cart, checkout and my-account stay in the list: they redirect anyway, and a
 * redirect is cleaner as a real navigation than as a swapped view.
 */
add_filter('proto_taxi_ignore_urls', function ($urls) {
    if (!cadco_commerce_disabled() || !function_exists('wc_get_page_id')) {
        return $urls;
    }

    $shop_id = wc_get_page_id('shop');
    if (!$shop_id || $shop_id <= 0) {
        return $urls;
    }

    $shop_url = get_permalink($shop_id);

    return array_values(array_filter($urls, static function ($url) use ($shop_url) {
        return $url !== $shop_url;
    }));
});

/**
 * Hide the commerce-only admin menu entries that have nothing to manage.
 *
 * Orders, Coupons and Marketing are left off the menu; Products, Categories,
 * Attributes and WooCommerce settings all stay, because the product editing
 * experience is the reason WooCommerce is installed.
 */
add_action('admin_menu', function () {
    if (!cadco_commerce_disabled()) {
        return;
    }

    remove_submenu_page('woocommerce', 'edit.php?post_type=shop_coupon');
    remove_menu_page('woocommerce-marketing');
}, 99);
