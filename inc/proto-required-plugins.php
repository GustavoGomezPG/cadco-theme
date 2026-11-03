<?php
/**
 * Required / recommended plugins via TGM Plugin Activation.
 *
 * - Required (wp.org): Safe SVG, Yoast SEO.
 * - Required (GitHub): Proto-Blocks — the theme's reason for existing.
 * - Recommended (deactivatable while developing): Wordfence.
 *
 * Proto-Blocks is installed from a GitHub release asset, and TGMPA installs
 * and activates whatever URL it is handed. So the resolver below only accepts
 * an asset hosted on the official repo whose name is `proto-blocks-<ver>.zip`,
 * and the downloaded archive is checked against the release's sha256 digest
 * (`upgrader_pre_download`) before WordPress unpacks it.
 */

defined('ABSPATH') || exit;

require_once get_stylesheet_directory() . '/inc/class-tgm-plugin-activation.php';

defined('PROTO_PROTOBLOCKS_RELEASE_TRANSIENT') || define('PROTO_PROTOBLOCKS_RELEASE_TRANSIENT', 'proto_protoblocks_release');
defined('PROTO_PROTOBLOCKS_RELEASE_API') || define('PROTO_PROTOBLOCKS_RELEASE_API', 'https://api.github.com/repos/GustavoGomez092/Proto-Blocks/releases/latest');
defined('PROTO_TGMPA_MENU') || define('PROTO_TGMPA_MENU', 'proto-install-plugins');

/**
 * Pinned fallback release — used when the GitHub API is unreachable, rate-
 * limited, or returns nothing acceptable. Refresh occasionally (see README
 * "Updating Proto-Blocks"); keep the sha256 in step with the URL so offline
 * installs are verified too (the value is the release asset's `digest`).
 *
 * @return array{version:string,url:string,sha256:string,sidecar:string,source:string}
 */
if (!function_exists('proto_protoblocks_fallback_release')) {
function proto_protoblocks_fallback_release(): array {
    return [
        'version' => '2.14.5',
        'url'     => 'https://github.com/GustavoGomez092/Proto-Blocks/releases/download/v2.14.5/proto-blocks-2.14.5.zip',
        'sha256'  => '3c4f353e2d7625428d0a6d38910644d8a1d9c18f735d7907073e52fb72594412',
        'sidecar' => '',
        'source'  => 'fallback',
    ];
}
}

/**
 * The version a release-asset URL points at, or '' if the URL is not an
 * asset we are willing to install.
 *
 * Accepted shape, nothing else:
 *   https://github.com/GustavoGomez092/Proto-Blocks/releases/download/v<ver>/proto-blocks-<ver>.zip<suffix>
 * with the same <ver> in the tag and the file name.
 */
if (!function_exists('proto_protoblocks_asset_version')) {
function proto_protoblocks_asset_version(string $url, string $suffix = '.zip'): string {
    $pattern = '#^https://github\.com/GustavoGomez092/Proto-Blocks/releases/download/v?(\d+\.\d+\.\d+)/proto-blocks-(\d+\.\d+\.\d+)'
        . preg_quote($suffix, '#') . '$#';
    if (!preg_match($pattern, $url, $m) || $m[1] !== $m[2]) {
        return '';
    }

    return $m[1];
}
}

/**
 * Lower-case hex from a GitHub asset `digest` ("sha256:<hex>"), or ''.
 */
if (!function_exists('proto_protoblocks_parse_digest')) {
function proto_protoblocks_parse_digest($digest): string {
    if (is_string($digest) && preg_match('/^sha256:([a-f0-9]{64})$/i', $digest, $m)) {
        return strtolower($m[1]);
    }

    return '';
}
}

/**
 * Pick the installable asset out of a `/releases/latest` payload.
 *
 * Returns null when no asset passes validation. When the zip carries no
 * `digest` but a `proto-blocks-<ver>.zip.sha256` sidecar is listed, its URL
 * is recorded so the download step can fetch it.
 */
if (!function_exists('proto_protoblocks_pick_asset')) {
function proto_protoblocks_pick_asset(array $data): ?array {
    $assets   = isset($data['assets']) && is_array($data['assets']) ? $data['assets'] : [];
    $zip      = null;
    $sidecars = [];

    foreach ($assets as $asset) {
        if (!is_array($asset)) {
            continue;
        }
        $name = isset($asset['name']) && is_string($asset['name']) ? $asset['name'] : '';
        $url  = isset($asset['browser_download_url']) && is_string($asset['browser_download_url']) ? $asset['browser_download_url'] : '';

        $ver = proto_protoblocks_asset_version($url);
        if ($ver !== '' && $name === 'proto-blocks-' . $ver . '.zip') {
            if ($zip === null) {
                $zip = [
                    'version' => $ver,
                    'url'     => $url,
                    'sha256'  => proto_protoblocks_parse_digest($asset['digest'] ?? ''),
                    'sidecar' => '',
                    'source'  => 'github',
                ];
            }
            continue;
        }

        $ver = proto_protoblocks_asset_version($url, '.zip.sha256');
        if ($ver !== '' && $name === 'proto-blocks-' . $ver . '.zip.sha256') {
            $sidecars[$ver] = $url;
        }
    }

    if ($zip === null) {
        return null;
    }
    if ($zip['sha256'] === '' && isset($sidecars[$zip['version']])) {
        $zip['sidecar'] = $sidecars[$zip['version']];
    }

    return $zip;
}
}

/**
 * Whether this admin request can actually install/update plugins — the only
 * requests worth a live GitHub round-trip. TGMPA fires `tgmpa_register` on
 * `init` for every admin page, and `get_current_screen()` does not exist
 * yet, so route on `pagenow` and the TGMPA menu slug.
 */
if (!function_exists('proto_protoblocks_is_install_context')) {
function proto_protoblocks_is_install_context(): bool {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing, no state change.
    $page = isset($_GET['page']) && is_string($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
    if ($page === PROTO_TGMPA_MENU) {
        return true;
    }

    $pagenow = isset($GLOBALS['pagenow']) ? (string) $GLOBALS['pagenow'] : '';

    return in_array($pagenow, ['plugins.php', 'plugin-install.php', 'update.php', 'update-core.php'], true);
}
}

/**
 * Normalise a cached/decoded release record; null if it is not trustworthy.
 */
if (!function_exists('proto_protoblocks_normalise_release')) {
function proto_protoblocks_normalise_release($release): ?array {
    if (!is_array($release) || !isset($release['url']) || !is_string($release['url'])) {
        return null;
    }
    $ver = proto_protoblocks_asset_version($release['url']);
    if ($ver === '') {
        return null;
    }
    $sha = isset($release['sha256']) && is_string($release['sha256']) && preg_match('/^[a-f0-9]{64}$/', $release['sha256'])
        ? $release['sha256'] : '';
    $sidecar = isset($release['sidecar']) && is_string($release['sidecar'])
        && proto_protoblocks_asset_version($release['sidecar'], '.zip.sha256') === $ver
        ? $release['sidecar'] : '';

    return [
        'version' => $ver,
        'url'     => $release['url'],
        'sha256'  => $sha,
        'sidecar' => $sidecar,
        'source'  => (isset($release['source']) && $release['source'] === 'github') ? 'github' : 'fallback',
    ];
}
}

/**
 * Resolve the LATEST Proto-Blocks release: download URL plus integrity data.
 *
 * - Cached in the `proto_protoblocks_release` transient: 12 h after a
 *   successful lookup, 1 h after a failure (the pinned fallback is cached so
 *   a dead or rate-limited GitHub is not re-tried on every request).
 * - With an empty cache the API is only queried on screens that can install
 *   plugins (TGMPA page, plugins.php, update*.php); every other admin page
 *   gets the fallback without a network call. Timeout 5 s.
 * - Only an asset that passes proto_protoblocks_asset_version() is accepted.
 *
 * @return array{version:string,url:string,sha256:string,sidecar:string,source:string}
 */
if (!function_exists('proto_protoblocks_release')) {
function proto_protoblocks_release(): array {
    static $resolved = null;
    if (is_array($resolved)) {
        return $resolved;
    }

    $fallback = proto_protoblocks_fallback_release();

    $cached = proto_protoblocks_normalise_release(get_transient(PROTO_PROTOBLOCKS_RELEASE_TRANSIENT));
    if ($cached !== null) {
        return $resolved = $cached;
    }

    if (!proto_protoblocks_is_install_context()) {
        return $resolved = $fallback; // Not cached: the install screen will look it up.
    }

    $res = wp_remote_get(PROTO_PROTOBLOCKS_RELEASE_API, [
        'timeout' => 5,
        'headers' => [
            'Accept'     => 'application/vnd.github+json',
            'User-Agent' => 'cadco-theme',
        ],
    ]);
    if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) {
        set_transient(PROTO_PROTOBLOCKS_RELEASE_TRANSIENT, $fallback, HOUR_IN_SECONDS);
        return $resolved = $fallback;
    }

    $data    = json_decode((string) wp_remote_retrieve_body($res), true);
    $release = is_array($data) ? proto_protoblocks_pick_asset($data) : null;
    if ($release === null) {
        set_transient(PROTO_PROTOBLOCKS_RELEASE_TRANSIENT, $fallback, HOUR_IN_SECONDS);
        return $resolved = $fallback;
    }

    if ($release['sha256'] === '' && $release['sidecar'] === '') {
        // Logged once per cache period: the archive will install unverified.
        error_log(sprintf('proto-theme: Proto-Blocks release %s publishes no sha256 digest or .sha256 asset; its download cannot be integrity-checked.', $release['version']));
    }

    set_transient(PROTO_PROTOBLOCKS_RELEASE_TRANSIENT, $release, 12 * HOUR_IN_SECONDS);
    return $resolved = $release;
}
}

/**
 * Download URL of the latest Proto-Blocks release (TGMPA `source`).
 */
if (!function_exists('proto_protoblocks_zip_url')) {
function proto_protoblocks_zip_url(): string {
    return proto_protoblocks_release()['url'];
}
}

/**
 * Fetch a `.sha256` sidecar asset and return the hex digest it holds, or ''.
 */
if (!function_exists('proto_protoblocks_fetch_sidecar_digest')) {
function proto_protoblocks_fetch_sidecar_digest(string $url): string {
    if (proto_protoblocks_asset_version($url, '.zip.sha256') === '') {
        return '';
    }
    $res = wp_safe_remote_get($url, ['timeout' => 5, 'headers' => ['User-Agent' => 'cadco-theme']]);
    if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) {
        return '';
    }
    $body = substr((string) wp_remote_retrieve_body($res), 0, 512);
    if (preg_match('/\b([a-f0-9]{64})\b/i', $body, $m)) {
        return strtolower($m[1]);
    }

    return '';
}
}

/**
 * `upgrader_pre_download`: verify the Proto-Blocks archive before WordPress
 * unpacks it. Scoped to the exact URL the resolver handed TGMPA; any other
 * package (including a hand-pinned `source`) is left to the normal download.
 *
 * @param false|string|WP_Error $reply
 * @param string                $package
 * @return false|string|WP_Error Path to the verified file, or an error.
 */
if (!function_exists('proto_protoblocks_verify_package')) {
function proto_protoblocks_verify_package($reply, $package) {
    if ($reply !== false || !is_string($package) || proto_protoblocks_asset_version($package) === '') {
        return $reply;
    }

    $release = proto_protoblocks_release();
    if ($package !== $release['url']) {
        return $reply;
    }

    $expected = $release['sha256'];
    if ($expected === '' && $release['sidecar'] !== '') {
        $expected = proto_protoblocks_fetch_sidecar_digest($release['sidecar']);
        if ($expected === '') {
            return new WP_Error(
                'proto_protoblocks_no_digest',
                sprintf(
                    /* translators: %s: URL of the .sha256 file. */
                    __('Could not read the Proto-Blocks checksum file (%s); the package was not installed. Try again later.', 'cadco-theme'),
                    $release['sidecar']
                )
            );
        }
    }

    if (!function_exists('download_url')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    $file = download_url($package);
    if (is_wp_error($file)) {
        return $file;
    }

    if ($expected === '') {
        return $file; // No digest published for this release (already logged).
    }

    $actual = hash_file('sha256', $file);
    if (!is_string($actual) || !hash_equals($expected, strtolower($actual))) {
        @unlink($file);
        return new WP_Error(
            'proto_protoblocks_bad_digest',
            sprintf(
                /* translators: %s: package URL. */
                __('The Proto-Blocks package (%s) failed its sha256 integrity check and was discarded.', 'cadco-theme'),
                $package
            )
        );
    }

    return $file;
}
}

add_filter('upgrader_pre_download', 'proto_protoblocks_verify_package', 10, 2);

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
            // Not on wp.org — fetched from the latest GitHub release (URL
            // validated, cached and digest-verified by proto_protoblocks_release()).
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
        'menu'         => PROTO_TGMPA_MENU,
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
