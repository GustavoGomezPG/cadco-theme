<?php
/**
 * Required / recommended plugins via TGM Plugin Activation.
 *
 * - Required (wp.org): Safe SVG, Yoast SEO, Duplicate Post.
 * - Required (GitHub): Proto-Blocks — the theme's reason for existing.
 * - Recommended (deactivatable while developing): Wordfence.
 */

defined('ABSPATH') || exit;

require_once get_stylesheet_directory() . '/inc/class-tgm-plugin-activation.php';

/**
 * Resolve the download URL of the LATEST Proto-Blocks release.
 *
 * Queries the GitHub Releases API for the newest published, non-prerelease
 * release (`/releases/latest`) and returns its first `.zip` asset's
 * download URL, so the theme always pulls the current version without anyone
 * hand-editing a version-pinned URL. The result is cached in a 12h transient
 * (GitHub's unauthenticated limit is 60 req/hr). On any failure it returns a
 * pinned fallback so installation still works offline / when rate-limited.
 */
if (!function_exists('proto_protoblocks_zip_url')) {
function proto_protoblocks_zip_url(): string {
    $cached = get_transient('proto_protoblocks_zip_url');
    if (is_string($cached) && $cached !== '') {
        return $cached;
    }

    // Pinned fallback — only used if the API is unreachable. Refresh
    // occasionally (see README "Updating Proto-Blocks"); not urgent.
    $fallback = 'https://github.com/GustavoGomez092/Proto-Blocks/releases/download/v2.3.1/proto-blocks-2.3.1.zip';

    $res = wp_remote_get('https://api.github.com/repos/GustavoGomez092/Proto-Blocks/releases/latest', [
        'timeout' => 8,
        'headers' => [
            'Accept'     => 'application/vnd.github+json',
            'User-Agent' => 'cadco-theme',
        ],
    ]);
    if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) {
        return $fallback;
    }

    $data = json_decode(wp_remote_retrieve_body($res), true);
    $url  = '';
    if (!empty($data['assets']) && is_array($data['assets'])) {
        foreach ($data['assets'] as $asset) {
            if (!empty($asset['name']) && substr($asset['name'], -4) === '.zip' && !empty($asset['browser_download_url'])) {
                $url = $asset['browser_download_url'];
                break;
            }
        }
    }
    if ($url === '') {
        return $fallback;
    }

    set_transient('proto_protoblocks_zip_url', $url, 12 * HOUR_IN_SECONDS);
    return $url;
}
}

add_action('tgmpa_register', 'proto_register_required_plugins');

if (!function_exists('proto_register_required_plugins')) {
function proto_register_required_plugins() {
    $plugins = [
        [
            'name'     => 'Safe SVG',
            'slug'     => 'safe-svg',
            'required' => true,
        ],
        [
            'name'     => 'Yoast SEO',
            'slug'     => 'wordpress-seo',
            'required' => true,
        ],
        [
            'name'     => 'Yoast Duplicate Post',
            'slug'     => 'duplicate-post',
            'required' => true,
        ],
        [
            // Not on wp.org — always fetched from the latest GitHub release
            // (URL resolved + cached by proto_protoblocks_zip_url()).
            'name'         => 'Proto-Blocks',
            'slug'         => 'proto-blocks',
            'source'       => proto_protoblocks_zip_url(),
            'required'     => true,
            'external_url' => 'https://github.com/GustavoGomez092/Proto-Blocks',
        ],
        [
            'name'     => 'Wordfence Security',
            'slug'     => 'wordfence',
            'required' => false, // suggested; can be deactivated while developing
        ],
    ];

    $config = [
        'id'           => 'cadco-theme',
        'default_path' => '',
        'menu'         => 'proto-install-plugins',
        'parent_slug'  => 'themes.php',
        'capability'   => 'edit_theme_options',
        'has_notices'  => true,
        'dismissable'  => true,
        'is_automatic' => true, // activate after install
        'message'      => '',
    ];

    tgmpa($plugins, $config);
}
}
