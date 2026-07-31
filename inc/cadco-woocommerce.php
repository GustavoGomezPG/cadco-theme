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
 * Turn off the WooCommerce Admin features that only exist to serve selling.
 *
 * This is the surgical lever. The blunt one, `woocommerce_admin_disabled`,
 * switches off the whole WooCommerce Admin app — including the block-based
 * product editor, which is the single feature this site actually wants. So the
 * features list is filtered instead, and anything product-editing related is
 * deliberately kept.
 *
 * Feature names are WooCommerce's own, read from
 * Automattic\WooCommerce\Admin\Features\Features::get_features().
 */
function cadco_disabled_wc_admin_features(): array
{
    return (array) apply_filters('cadco_disabled_wc_admin_features', [
        // Sales reporting for a store with no sales.
        'analytics',
        'analytics-scheduled-import',
        // Commerce objects that cannot exist here.
        'coupons',
        'subscriptions',
        // Storefront marketing surfaces.
        'marketing',
        'homescreen',
        'store-alerts',
        'remote-inbox-notifications',
        'remote-free-extensions',
        // Payment and shipping upsells.
        'payment-gateway-suggestions',
        'wc-pay-promotion',
        'wc-pay-welcome-page',
        'shipping-label-banner',
        'shipping-smart-defaults',
        'shipping-setting-tour',
        'printful',
        // Store setup flows. Note 'launch-your-store' is deliberately NOT in
        // this list: WooCommerce only registers the Site visibility settings
        // tab when that feature is enabled (class-wc-admin-settings.php:62),
        // and removing it takes a legitimate setting away as a side effect.
        'onboarding',
        'onboarding-tasks',
        'core-profiler',
        'customize-store',
        'import-products-task',
        'experimental-fashion-sample-products',
        // App promos.
        'mobile-app-banner',
        'woo-mobile-welcome',
    ]);
}

add_filter('woocommerce_admin_features', function ($features) {
    if (!cadco_commerce_disabled()) {
        return $features;
    }

    return array_values(array_diff((array) $features, cadco_disabled_wc_admin_features()));
});

/**
 * The settings tabs that configure selling.
 *
 * "Payments" is the tab whose id is `checkout`.
 *
 * Kept: General (currency and price display), Products, Site visibility,
 * Advanced (page setup, REST API) and any Integration tabs a plugin adds.
 */
function cadco_disabled_wc_settings_tabs(): array
{
    return (array) apply_filters('cadco_disabled_wc_settings_tabs', [
        'checkout',      // Payments
        'shipping',
        'tax',
        'account',       // Accounts & Privacy
        'email',
        'point-of-sale',
    ]);
}

/**
 * Remove those tabs from the settings navigation.
 *
 * This is the filter that actually decides what renders. Each settings page
 * registers its own tab into `woocommerce_settings_tabs_array` from its
 * constructor, so filtering `woocommerce_get_settings_pages` (below) does NOT
 * take the tab out of the nav — the object has already registered by then.
 */
add_filter('woocommerce_settings_tabs_array', function ($tabs) {
    if (!cadco_commerce_disabled()) {
        return $tabs;
    }

    foreach (cadco_disabled_wc_settings_tabs() as $id) {
        unset($tabs[$id]);
    }

    return $tabs;
}, 100);

/**
 * Also drop the page objects, so the tab bodies stop rendering and saving.
 *
 * Caveat worth knowing: WC_Admin_Settings::get_settings_pages() memoises into a
 * static and only builds the list once (`if ( empty( self::$settings ) )`,
 * class-wc-admin-settings.php:48). If anything asks for the settings pages
 * before this theme's functions.php has loaded, the list is already built and
 * this filter never runs. That is why the nav filter above — which fires on
 * every render — is the load-bearing one, and this is the belt to its braces.
 */
add_filter('woocommerce_get_settings_pages', function ($pages) {
    if (!cadco_commerce_disabled()) {
        return $pages;
    }

    $drop = cadco_disabled_wc_settings_tabs();

    return array_values(array_filter($pages, static function ($page) use ($drop) {
        return !in_array($page->get_id(), $drop, true);
    }));
});

/**
 * Remove the Extensions marketplace entry.
 *
 * Its menu slug is `wc-admin` with a `path=/extensions` query arg rather than a
 * plain page slug, so remove_submenu_page() cannot address it. WooCommerce
 * exposes the menu definition itself, which can.
 */
add_filter('woocommerce_marketplace_menu_items', function ($items) {
    return cadco_commerce_disabled() ? [] : $items;
});

/**
 * Screens that are blocked outright, not merely hidden.
 *
 * Deliberately expressed as narrow, explicit matches rather than a capability
 * change. Stripping `manage_woocommerce` would be the blunt route and would
 * take WooCommerce → Settings and Status with it, which this site keeps.
 *
 * `wc-admin` paths are matched by prefix rather than blocking the `wc-admin`
 * page wholesale: it is the root of the WooCommerce Admin app, and newer
 * WooCommerce serves the block-based product editor from
 * `wc-admin&path=/add-product`. Blocking the page would break product editing
 * the moment that feature is switched on.
 */
function cadco_blocked_admin_screens(): array
{
    return (array) apply_filters('cadco_blocked_admin_screens', [
        'pages'          => ['wc-orders', 'wc-reports', 'wc-addons'],
        'wc_admin_paths' => ['/analytics', '/extensions', '/marketing', '/customers'],
        'post_types'     => ['shop_coupon'],
    ]);
}

/**
 * Refuse the commerce-only screens rather than just hiding them from the menu.
 *
 * Removing a menu item is cosmetic — WordPress still serves the screen to
 * anyone who types the URL. This closes that gap for the screens that manage
 * things this site does not have.
 *
 * Removed settings tabs redirect to the settings root instead of dying, because
 * the tab is a query arg on a page that is legitimately available, and bouncing
 * to General is friendlier than an error for a stale bookmark.
 */
add_action('admin_init', function () {
    if (!cadco_commerce_disabled() || wp_doing_ajax()) {
        return;
    }

    $blocked = cadco_blocked_admin_screens();
    $page    = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';

    $deny = static function () {
        wp_die(
            esc_html__('This site does not use WooCommerce selling features, so this screen is unavailable.', 'cadco-theme'),
            esc_html__('Screen unavailable', 'cadco-theme'),
            ['response' => 403, 'back_link' => true]
        );
    };

    // Whole pages.
    if ($page && in_array($page, $blocked['pages'], true)) {
        $deny();
    }

    // WooCommerce Admin app routes, matched by path prefix.
    if ('wc-admin' === $page) {
        $path = isset($_GET['path']) ? wp_unslash($_GET['path']) : '';

        foreach ($blocked['wc_admin_paths'] as $prefix) {
            if ($path && 0 === strpos($path, $prefix)) {
                $deny();
            }
        }
    }

    // Post-type screens for objects that cannot exist here.
    $post_type = isset($_GET['post_type']) ? sanitize_key(wp_unslash($_GET['post_type'])) : '';
    if ($post_type && in_array($post_type, $blocked['post_types'], true)) {
        $deny();
    }

    // Removed settings tabs.
    if ('wc-settings' === $page) {
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';

        if ($tab && in_array($tab, cadco_disabled_wc_settings_tabs(), true)) {
            wp_safe_redirect(admin_url('admin.php?page=wc-settings'), 302);
            exit;
        }
    }
});

/**
 * Take the remaining commerce-only entries off the admin menu.
 *
 * Note this hides rather than forbids: WordPress still serves these screens to
 * anyone who types the URL. That is the right trade-off for tidying an admin
 * whose users are trusted — it is not an access control, and it is not
 * pretending to be one.
 *
 * Kept: Products and its taxonomies, WooCommerce → Settings, and
 * WooCommerce → Status, which stays useful for debugging.
 */
add_action('admin_menu', function () {
    if (!cadco_commerce_disabled()) {
        return;
    }

    // Submenu entries under the WooCommerce top-level.
    remove_submenu_page('woocommerce', 'wc-orders');                        // Orders
    remove_submenu_page('woocommerce', 'wc-reports');                       // legacy sales reports
    remove_submenu_page('woocommerce', 'wc-admin');                         // WooCommerce → Home
    remove_submenu_page('woocommerce', 'edit.php?post_type=shop_coupon');   // Coupons
    remove_submenu_page('woocommerce', 'wc-addons');                        // legacy hidden marketplace node

    // Top-level menus. The features filter above should already have removed
    // these, so this is a backstop for WooCommerce registering them anyway.
    remove_menu_page('woocommerce-marketing');
    remove_menu_page('wc-admin&path=/analytics/overview');

    // The standalone Payments menu. Its slug is a full admin.php query string,
    // not a bare page slug -- read off the rendered menu item's element id,
    // toplevel_page_admin-page-wc-settings-tab-checkout-from-PAYMENTS_MENU_ITEM.
    remove_menu_page('admin.php?page=wc-settings&tab=checkout&from=PAYMENTS_MENU_ITEM');
}, 999);
