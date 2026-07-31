<?php
/**
 * Proto-theme functions.
 */

require_once get_stylesheet_directory() . '/inc/proto-required-plugins.php';
require_once get_stylesheet_directory() . '/inc/proto-taxi.php';
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

// SVG favicon (theme-owned, survives Customizer changes).
add_action('wp_head', function () {
    $path = get_stylesheet_directory() . '/assets/img/favicon.svg';
    if (!file_exists($path)) { return; }
    printf(
        '<link rel="icon" type="image/svg+xml" href="%s?v=%s">' . "\n",
        esc_url(get_stylesheet_directory_uri() . '/assets/img/favicon.svg'),
        esc_attr(filemtime($path))
    );
}, 1);

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
