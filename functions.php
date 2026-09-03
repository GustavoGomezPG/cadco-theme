<?php
/**
 * Proto-theme functions.
 */

require_once get_stylesheet_directory() . '/inc/proto-required-plugins.php';
require_once get_stylesheet_directory() . '/inc/proto-taxi.php';
require_once get_stylesheet_directory() . '/inc/cadco-nav.php';
require_once get_stylesheet_directory() . '/inc/cadco-woocommerce.php';

add_action('after_setup_theme', function () {
    // Navigation is managed via the block-editor Navigation block in the Site
    // Editor (not classic register_nav_menus locations, which a block theme's
    // header ignores).
    add_editor_style('style.css');
});

// Proto-Blocks inserter category.
add_filter('proto_blocks_category_slug', fn() => 'proto');
add_filter('proto_blocks_category_title', fn() => __('Proto Blocks', 'cadco-theme'));

// Site icons (theme-owned, survives Customizer changes).
add_action('wp_head', function () {
    $dir = get_stylesheet_directory() . '/assets/img';
    $uri = get_stylesheet_directory_uri() . '/assets/img';
    $icons = [
        ['favicon.svg',          '<link rel="icon" type="image/svg+xml" href="%s?v=%s">'],
        ['favicon-32.png',       '<link rel="icon" type="image/png" sizes="32x32" href="%s?v=%s">'],
        ['favicon-16.png',       '<link rel="icon" type="image/png" sizes="16x16" href="%s?v=%s">'],
        ['apple-touch-icon.png', '<link rel="apple-touch-icon" sizes="180x180" href="%s?v=%s">'],
    ];
    foreach ($icons as [$file, $tag]) {
        $path = $dir . '/' . $file;
        if (!file_exists($path)) { continue; }
        printf($tag . "\n", esc_url($uri . '/' . $file), esc_attr(filemtime($path)));
    }
}, 1);

/**
 * Web fonts — front end AND the block-editor canvas iframe.
 *
 * Inter carries body/UI, Barlow is the display face the Figma file uses for
 * headings, eyebrows and buttons (Tailwind `font-display`).
 *
 * Every weight in the family list is *declared*, but the browser only downloads
 * the faces a page actually renders — a requested-but-unused weight costs one
 * @font-face rule and no bandwidth. Listing the range up front therefore keeps
 * later blocks from needing a change here.
 */
add_action('enqueue_block_assets', function () {
    wp_enqueue_style(
        'cadco-fonts',
        'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Barlow:wght@400;500;600;700;800&display=swap',
        [],
        null
    );
});

// Open the TLS connection to the font host while the HTML is still parsing;
// without it the woff2 fetch waits on a cold connection after the CSS lands.
add_filter('wp_resource_hints', function (array $urls, string $relation): array {
    if ($relation === 'preconnect') {
        $urls[] = ['href' => 'https://fonts.gstatic.com', 'crossorigin' => 'anonymous'];
    }
    return $urls;
}, 10, 2);

// Theme stylesheet for front end AND the block-editor canvas iframe.
add_action('enqueue_block_assets', function () {
    $style = get_stylesheet_directory() . '/style.css';
    wp_enqueue_style('cadco-theme', get_stylesheet_uri(), [], file_exists($style) ? filemtime($style) : false);
});

// Builder-canvas editor CSS (inside the iframe) — admin only.
add_action('enqueue_block_assets', function () {
    if (!is_admin()) { return; }
    $css = get_stylesheet_directory() . '/assets/editor/builder-canvas.css';
    if (!file_exists($css)) { return; }
    wp_enqueue_style('proto-builder-canvas', get_stylesheet_directory_uri() . '/assets/editor/builder-canvas.css', [], filemtime($css));
});

// Builder-canvas editor JS (outer chrome — sidebar Page Title panel).
add_action('enqueue_block_editor_assets', function () {
    $js = get_stylesheet_directory() . '/assets/editor/builder-canvas.js';
    if (!file_exists($js)) { return; }
    wp_enqueue_script(
        'proto-builder-canvas',
        get_stylesheet_directory_uri() . '/assets/editor/builder-canvas.js',
        ['wp-plugins', 'wp-edit-post', 'wp-editor', 'wp-components', 'wp-data', 'wp-element', 'wp-i18n'],
        filemtime($js),
        true
    );
});

// Front-end animation libraries (self-hosted; expose window globals).
add_action('wp_enqueue_scripts', function () {
    $dir = get_stylesheet_directory() . '/scripts';
    $url = get_stylesheet_directory_uri() . '/scripts';
    $libs = [
        'gsap'           => ['file' => 'gsap.min.js',          'version' => '3.15.0', 'deps' => []],
        'split-text'     => ['file' => 'SplitText.min.js',     'version' => '3.15.0', 'deps' => ['proto-gsap']],
        'scroll-trigger' => ['file' => 'ScrollTrigger.min.js', 'version' => '3.15.0', 'deps' => ['proto-gsap']],
        'lottie'         => ['file' => 'lottie_light.min.js',  'version' => '5.13.0', 'deps' => []],
        'lenis'          => ['file' => 'lenis.min.js',         'version' => '1.1.13', 'deps' => []],
        'taxi-e'         => ['file' => 'e.umd.js',      'version' => '2.5.0',  'deps' => []],
        'taxi'           => ['file' => 'taxi.umd.js',   'version' => '1.9.1',  'deps' => ['proto-taxi-e']],
        'init'           => ['file' => 'proto-init.js',        'version' => '1.0.0',  'deps' => ['proto-lenis']],
        'intro'          => ['file' => 'proto-intro.js',       'version' => '1.0.0',  'deps' => ['proto-init', 'proto-lottie']],
        'taxi-init'      => ['file' => 'proto-taxi.js', 'version' => '1.0.0',  'deps' => ['proto-taxi', 'proto-init']],
    ];
    if (!proto_taxi_is_enabled()) {
        unset($libs['taxi-e'], $libs['taxi'], $libs['taxi-init']);
    }
    $enqueued = [];
    foreach ($libs as $handle => $lib) {
        $path = $dir . '/' . $lib['file'];
        if (!file_exists($path)) { continue; }
        wp_enqueue_script('proto-' . $handle, $url . '/' . $lib['file'], $lib['deps'], $lib['version'] . '.' . filemtime($path), true);
        $enqueued[$handle] = true;
    }
    // Unwrap the @unseenco/e default export so window.E is the emitter directly.
    // Gated on the handle actually being enqueued: attaching inline script to a
    // handle that was skipped (file missing, or Taxi disabled) is a silent no-op
    // that would leave window.E wrapped and proto-taxi.js's E.on() throwing.
    if (!empty($enqueued['taxi-e'])) {
        wp_add_inline_script('proto-taxi-e', "if(window.E&&window.E.__esModule&&window.E.default){window.E=window.E.default}");
    }
});

/**
 * Section reveal animations, shared by every block that has one.
 *
 * A theme-level script rather than a per-block view.js: the sections
 * deliberately share one motion vocabulary, and four copies of the same
 * timeline would drift apart the first time one of them was tuned. The blocks
 * declare what they want in markup (`data-cadco-reveal`); this owns how it
 * moves.
 *
 * Depends on the vendored GSAP trio. Registered after them in this file, so
 * the handles exist to depend on -- and the script degrades to showing every
 * section outright if any of them is missing.
 */
add_action('wp_enqueue_scripts', function () {
    $path = get_stylesheet_directory() . '/assets/js/cadco-reveal.js';

    if (! file_exists($path)) {
        return;
    }

    // Only depend on what actually exists. A dependency on an unregistered
    // handle makes WordPress drop the script silently, so a missing vendored
    // library would take the reveals down with it rather than degrading to
    // "every section simply shows", which is what the script is written to do.
    $deps = array_values(array_filter(
        ['proto-gsap', 'proto-scroll-trigger', 'proto-split-text'],
        static fn(string $handle): bool => wp_script_is($handle, 'registered')
    ));

    wp_enqueue_script(
        'cadco-reveal',
        get_stylesheet_directory_uri() . '/assets/js/cadco-reveal.js',
        $deps,
        filemtime($path),
        true
    );
}, 20);

/**
 * Block view.js files that animate must not run before their libraries exist.
 *
 * Proto-Blocks registers a block's view.js with no dependencies, and both it and
 * the vendored libraries print in the footer, so the order is otherwise down to
 * enqueue timing. Dependencies are appended to the already-registered handle
 * rather than re-registering the script, which would fight the plugin over
 * ownership of the URL and version.
 *
 * Each view.js still degrades to instant show/hide when a library is absent —
 * this makes the animated path the one that actually runs.
 */
add_action('wp_enqueue_scripts', function () {
    global $wp_scripts;

    $needs = [
        'proto-blocks-cadco-header'         => ['proto-gsap'],
        'proto-blocks-cadco-hero'           => ['proto-gsap', 'proto-split-text'],
        'proto-blocks-cadco-image-carousel' => ['proto-gsap', 'proto-scroll-trigger'],
    ];

    foreach ($needs as $handle => $deps) {
        if (!isset($wp_scripts->registered[$handle])) {
            continue;
        }
        foreach ($deps as $dep) {
            if (!wp_script_is($dep, 'registered')) {
                continue;
            }
            if (!in_array($dep, $wp_scripts->registered[$handle]->deps, true)) {
                $wp_scripts->registered[$handle]->deps[] = $dep;
            }
        }
    }
}, 99);

// Once-per-session intro overlay.
add_action('wp_head', function () {
    if (is_admin()) { return; }
    ?>
    <script>(function(){try{if(sessionStorage.getItem('protoIntroShown')==='true'){document.documentElement.classList.add('proto-intro-skip');}}catch(e){}})();</script>
    <noscript><style>.proto-intro{display:none !important;}</style></noscript>
    <?php
}, 1);

add_action('wp_body_open', function () {
    if (is_admin()) { return; }
    $path = get_stylesheet_directory() . '/assets/lottie/intro.json';
    $url  = get_stylesheet_directory_uri() . '/assets/lottie/intro.json';
    $attr = file_exists($path) ? ' data-lottie-url="' . esc_url($url) . '"' : '';
    ?>
    <div class="proto-intro"<?php echo $attr; ?> aria-hidden="true" role="presentation">
        <div class="proto-intro__lottie"></div>
    </div>
    <?php
});

/**
 * Name the block inserter's category after the client, not the tool.
 *
 * Proto-Blocks labels its inserter panel "Proto Blocks". Editors here are
 * Cadco's staff, to whom the plugin's name means nothing.
 *
 * Done through the plugin's filter rather than its Settings → Block Category
 * Name field so the label lives in the theme, under version control, and
 * arrives with a deploy instead of having to be re-typed into the database of
 * every environment. Priority 20 puts it after the plugin's own handler, which
 * runs at 10 and would otherwise overwrite this with that stored option.
 *
 * Only the title changes. The category SLUG is deliberately left alone: every
 * block.json in this theme declares `"category": "proto"`, and renaming the
 * slug would orphan all of them out of the panel.
 */
add_filter('proto_blocks_category_title', function () {
    return __('Cadco Blocks', 'cadco-theme');
}, 20);
