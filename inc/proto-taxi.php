<?php
/**
 * Taxi.js page-transition integration.
 *
 * Taxi's default reloadJsFilter only re-runs scripts carrying a
 * `data-taxi-reload` attribute. WordPress has no way to express that in
 * block markup, so the attribute is stamped onto the tag here, per handle.
 */

/**
 * Whether page transitions are active for this request.
 */
function proto_taxi_is_enabled(): bool
{
    if (is_admin() || wp_is_json_request()) {
        return false;
    }

    return (bool) apply_filters('proto_taxi_enabled', true);
}

/**
 * Script handles whose tags get `data-taxi-reload`.
 *
 * Proto-Blocks registers every block's view.js as `proto-blocks-{name}`
 * (Registrar.php:185), so the prefix covers all blocks. The deny list is
 * applied after, and wins.
 */
function proto_taxi_reload_handles(): array
{
    return (array) apply_filters('proto_taxi_reload_handles', []);
}

/**
 * Handles that must never be re-executed — re-running these would create a
 * second Lenis instance, a second RAF loop, or a second Taxi Core.
 */
function proto_taxi_denied_handles(): array
{
    return (array) apply_filters('proto_taxi_denied_handles', [
        'proto-gsap',
        'proto-split-text',
        'proto-scroll-trigger',
        'proto-lottie',
        'proto-lenis',
        'proto-taxi-e',
        'proto-taxi',
        'proto-taxi-init',
        'proto-init',
        'proto-intro',
    ]);
}

/**
 * URLs whose links opt out of Taxi navigation.
 */
function proto_taxi_ignore_urls(): array
{
    $urls = [];

    if (function_exists('wc_get_page_id')) {
        // 'shop' renders through WooCommerce's own archive-product block
        // template, which this theme never wraps in [data-taxi-view] (see
        // the README's Page Transitions section) — it has no wrapper for
        // Taxi to swap, so its links must stay full page loads. It's also
        // the one plugin-supplied route linked from the default navigation,
        // which is why it's handled here instead of only documented.
        foreach (['cart', 'checkout', 'myaccount', 'shop'] as $page) {
            $id = wc_get_page_id($page);
            if ($id && $id > 0) {
                $urls[] = get_permalink($id);
            }
        }
    }

    return array_values(array_filter((array) apply_filters('proto_taxi_ignore_urls', $urls)));
}

/**
 * Stamp `data-taxi-reload` on block view scripts.
 */
add_filter('script_loader_tag', function ($tag, $handle) {
    if (!proto_taxi_is_enabled()) {
        return $tag;
    }

    if (in_array($handle, proto_taxi_denied_handles(), true)) {
        return $tag;
    }

    $should = str_starts_with($handle, 'proto-blocks-')
        || in_array($handle, proto_taxi_reload_handles(), true);

    if (!$should || str_contains($tag, 'data-taxi-reload')) {
        return $tag;
    }

    // ES modules evaluate once per URL — re-appending will not re-run them.
    // Those blocks must use the proto:page-ready event instead.
    if (str_contains($tag, 'type="module"')) {
        return $tag;
    }

    // $tag can contain more than one <script> element — translations,
    // wp_add_inline_script(), and wp_localize_script() all prepend/append
    // their own <script id="..."> tags around the one with the src
    // attribute, and every one of them matches a plain '<script '. Mark
    // only the tag that actually carries the src, and only once.
    $result = preg_replace('#<script(?=[^>]*\ssrc=)#', '<script data-taxi-reload', $tag, 1);

    // preg_replace() returns null on a PCRE failure (e.g. backtrack limit) —
    // fall back to the untouched tag rather than dropping the script.
    return $result ?? $tag;
}, 10, 2);

/**
 * Mark links to stateful WooCommerce pages so Taxi leaves them alone.
 *
 * render_block covers both post content and template-level blocks (the
 * navigation in the header), which is everything a block theme renders.
 */
add_filter('render_block', function ($content, $block) {
    return proto_taxi_mark_ignored_links($content);
}, 20, 2);

function proto_taxi_mark_ignored_links($content)
{
    if (!proto_taxi_is_enabled() || !is_string($content) || $content === '' || !str_contains($content, '<a')) {
        return $content;
    }

    $urls = proto_taxi_ignore_urls();
    if (empty($urls)) {
        return $content;
    }

    foreach ($urls as $url) {
        $path = wp_parse_url($url, PHP_URL_PATH);
        // Plain permalinks resolve every WooCommerce page to "/" (the real
        // identity lives in the query string), and a bare "/" would
        // substring-match every internal href on the site. Skip it.
        $path = $path ? rtrim($path, '/') : '';
        if ($path === '') {
            continue;
        }

        // Anchor the right edge of the path so "/cart" cannot match
        // "/cart-accessories/" — after the path, only an optional trailing
        // slash followed by the closing quote, a query string, or a
        // fragment is allowed.
        $result = preg_replace_callback(
            '#<a\s+([^>]*href=["\'][^"\']*' . preg_quote($path, '#') . '(?:/)?(?=["\'?\#])[^"\']*["\'][^>]*)>#i',
            static function ($m) {
                return str_contains($m[1], 'data-taxi-ignore')
                    ? $m[0]
                    : '<a ' . $m[1] . ' data-taxi-ignore>';
            },
            $content
        );

        // preg_replace_callback() returns null on a PCRE failure (e.g.
        // backtrack limit) — fall back to the content as it stood before
        // this URL's pass rather than blanking the whole block.
        $content = $result ?? $content;
    }

    return $content;
}
