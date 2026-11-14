<?php
/**
 * The media stage against a real WordPress: downloads, product images,
 * resources, and every rule about what may be replaced or removed.
 *
 *   tests/integration/run-media.sh      (or: wp eval-file <this file>)
 *
 * Runs inside one database transaction that is never committed. The stage's
 * clean-up is catalogue-wide by design (a resource the workbook stops citing
 * is trashed), so this suite, which imports a small catalogue of its own,
 * would otherwise retire the site's real resources. Rolling back — at the
 * end, or automatically if the process dies, since nothing is ever committed
 * — is what keeps the site exactly as it was. The files the suite writes to
 * uploads are deleted by the suite before the rollback.
 *
 * No network is used. Every request is answered by a pre_http_request
 * filter from the $files table below, so the suite is deterministic and
 * can make a file change, lock, or vanish between runs.
 */

if (!class_exists('CADCO_Import_Media_Applier')) {
    fwrite(STDERR, "The importer is not loaded.\n");
    exit(1);
}

require_once ABSPATH . 'wp-admin/includes/image.php';

/* -------------------------------------------------------------------------
   Harness
   ------------------------------------------------------------------------- */

// wp eval-file includes this file inside a function, so its top-level
// variables are not globals; the counters live in $GLOBALS explicitly.
$GLOBALS['cadco_int_failures'] = 0;
$GLOBALS['cadco_int_passes']   = 0;

function check(bool $ok, string $what): void
{
    if ($ok) {
        $GLOBALS['cadco_int_passes']++;
        echo "  ok    {$what}\n";
    } else {
        $GLOBALS['cadco_int_failures']++;
        echo "  FAIL  {$what}\n";
    }
}

/* -------------------------------------------------------------------------
   Isolation: one transaction, never committed
   ------------------------------------------------------------------------- */

global $wpdb;

$tables = [$wpdb->posts, $wpdb->postmeta, $wpdb->terms, $wpdb->term_taxonomy, $wpdb->term_relationships, $wpdb->termmeta, $wpdb->options, $wpdb->prefix . 'wc_product_meta_lookup'];
$engines = $wpdb->get_results("SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()", OBJECT_K);

foreach ($tables as $table) {
    if (!isset($engines[$table]) || strcasecmp((string) $engines[$table]->ENGINE, 'InnoDB') !== 0) {
        fwrite(STDERR, "Refusing to run: {$table} is not InnoDB, so it cannot be rolled back.\n");
        exit(1);
    }
}

$wpdb->query('SET autocommit = 0');
$wpdb->query('START TRANSACTION');

/** Every attachment this suite creates, so exactly those files are deleted. */
$GLOBALS['cadco_int_created'] = [];
add_action('add_attachment', static function (int $id) { $GLOBALS['cadco_int_created'][] = $id; });

register_shutdown_function(static function () {
    global $wpdb;

    // Files first: they are not in the transaction. The rows these calls
    // delete are rolled back anyway.
    foreach (array_unique($GLOBALS['cadco_int_created']) as $id) {
        wp_delete_attachment((int) $id, true);
    }

    $wpdb->query('ROLLBACK');
    $wpdb->query('SET autocommit = 1');
    wp_cache_flush();
});

function section(string $title): void
{
    echo "\n{$title}\n";
}

/** A real PNG of a given colour, so each image has distinct bytes. */
function png(int $r, int $g, int $b): string
{
    $im = imagecreatetruecolor(8, 8);
    imagefill($im, 0, 0, imagecolorallocate($im, $r, $g, $b));
    ob_start();
    imagepng($im);
    imagedestroy($im);

    return (string) ob_get_clean();
}

function pdf(string $text): string
{
    return "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n% {$text}\ntrailer<</Root 1 0 R>>\n%%EOF\n";
}

const SP = 'https://cadcoltd232.sharepoint.com/:i:/s/CADCOMediaLibrary/';
const DL = 'https://cadcoltd232.sharepoint.com/sites/CADCOMediaLibrary/_layouts/15/download.aspx?share=';

/** url => [status, body] — what the "internet" serves. Edited between runs. */
$files = [
    DL . 'IMG1'   => [200, png(255, 0, 0)],
    DL . 'IMG2'   => [200, png(0, 255, 0)],
    DL . 'IMG3'   => [200, png(0, 0, 255)],
    DL . 'SPEC'   => [200, pdf('shared spec v1')],
    DL . 'SPEC2'  => [200, pdf('shared spec v1')],          // same bytes, other link
    DL . 'MANA'   => [200, pdf('manual for A')],
    DL . 'LOCKED' => [200, '<!DOCTYPE html><html><body>' . str_repeat(' ', 4000) . 'Sorry, you cannot access this document.</body></html>'],
    DL . 'IMG4'   => [500, 'server error'],
    'https://img.youtube.com/vi/zzIntTest01/maxresdefault.jpg' => [404, ''],
    'https://img.youtube.com/vi/zzIntTest01/hqdefault.jpg'     => [200, png(9, 9, 9)],
    'https://www.youtube.com/oembed?format=json&url=' . rawurlencode('https://www.youtube.com/watch?v=zzIntTest01') => [200, '{"title":"Panini Grills in action"}'],
];

$requests = [];

add_filter('pre_http_request', static function ($pre, array $args, string $url) use (&$files, &$requests) {
    $requests[] = $url;
    $answer = $files[$url] ?? [404, 'not found'];

    // A list of answers is served one per request, the last one repeating —
    // how a throttled host behaves: a sign-in page, then the file.
    if (isset($answer[0]) && is_array($answer[0])) {
        $answer = count($answer) > 1 ? array_shift($files[$url]) : $answer[0];
    }

    if ($answer[0] === 'error') {
        return new WP_Error('http_request_failed', (string) $answer[1]);
    }

    [$status, $body] = $answer;

    if (!empty($args['stream']) && !empty($args['filename'])) {
        file_put_contents($args['filename'], $body);
    }

    return [
        'headers'  => [],
        'body'     => !empty($args['stream']) ? '' : $body,
        'response' => ['code' => $status, 'message' => ''],
        'cookies'  => [],
        'filename' => $args['filename'] ?? null,
    ];
}, 10, 3);

/** Writes to watch for the "unchanged means nothing written" rule. */
$writes = 0;
$count  = static function () use (&$writes) { $writes++; };

foreach (['added_post_meta', 'updated_post_meta', 'deleted_post_meta', 'wp_insert_post', 'set_object_terms', 'add_attachment'] as $hook) {
    add_action($hook, $count);
}



function run_media(array $rows, bool $refresh = false): array
{
    CADCO_Import_Media_Applier::reset_cache();

    $plan  = CADCO_Import_Media_Plan::build($rows, 'cadcoltd232.sharepoint.com');
    $queue = CADCO_Import_Media_Applier::queue($plan, $refresh);
    $out   = CADCO_Import_Applier::apply_jobs($queue, 0, 10000);

    CADCO_Import_Media_Applier::reset_cache();

    return $out['log'];
}

function row(string $model, array $media): array
{
    return $media + [
        'Model #'        => $model,
        'Product Name'   => 'Integration ' . $model,
        'Images URL'     => '',
        'Spec Sheet URL' => '',
        'Manual URL'     => '',
        'Video URL'      => '',
        '__sheet'        => 'COUNTERTOP EQUIPMENT',
        '__row'          => 2,
    ];
}

function product(string $sku): int
{
    $p = new WC_Product_Simple();
    $p->set_name('Integration ' . $sku);
    $p->set_sku($sku);
    $p->set_slug(sanitize_title($sku));   // as the importer names products
    $p->set_status('publish');

    return (int) $p->save();
}

function att(string $share): int
{
    CADCO_Import_Media_Applier::reset_cache();

    return CADCO_Import_Media_Applier::attachment_for('sp:cadcoltd232.sharepoint.com:' . $share);
}

function resource(string $identity): ?WP_Post
{
    $found = get_posts([
        'post_type'   => 'resource',
        'post_status' => 'any',
        'meta_key'    => CADCO_Import_Media_Applier::RESOURCE_META,
        'meta_value'  => $identity,
        'numberposts' => 1,
    ]);

    return $found ? $found[0] : null;
}

function tagged(int $resource_id): array
{
    $slugs = wp_get_object_terms($resource_id, 'resource_product', ['fields' => 'slugs']);
    sort($slugs);

    return $slugs;
}

function gallery(int $post_id): array
{
    return array_values(array_filter(array_map('intval', explode(',', (string) get_post_meta($post_id, '_product_image_gallery', true)))));
}

function has_failure(array $log, string $needle): bool
{
    foreach ($log as $line) {
        if (str_starts_with($line, 'MEDIA FAILED') && str_contains($line, $needle)) {
            return true;
        }
    }

    return false;
}

/* -------------------------------------------------------------------------
   The downloader
   ------------------------------------------------------------------------- */

section('Downloader');

$signin = '<html><script>location="https://login.microsoftonline.com/common/oauth2/authorize"</script></html>';

$files['https://dl.test/throttled'] = [[200, $signin], [200, pdf('after throttling')]];
$r = CADCO_Import_Fetcher::fetch('https://dl.test/throttled', 'k1');
check($r['ok'] && $r['type'] === 'pdf', 'a sign-in page (SharePoint throttling) is retried, and the file then accepted');
CADCO_Import_Fetcher::discard($r['path']);

$files['https://dl.test/locked'] = [200, '<html>' . str_repeat(' ', 200000) . 'Sorry, you cannot access this document.</html>'];
$requests = [];
$r = CADCO_Import_Fetcher::fetch('https://dl.test/locked', 'k2');
check(!$r['ok'] && str_contains($r['error'], 'cannot be accessed'), 'a locked file is refused with the reason, found deep in the page');
check(count($requests) === 1, 'and not retried, since waiting will not unlock it');

$files['https://dl.test/gone'] = [404, ''];
$requests = [];
$r = CADCO_Import_Fetcher::fetch('https://dl.test/gone', 'k3');
check(!$r['ok'] && str_contains($r['error'], 'not there') && count($requests) === 1, 'a 404 fails once, without retrying');

$files['https://dl.test/flaky'] = [['error', 'cURL error 28: Operation timed out'], [200, png(4, 5, 6)]];
$r = CADCO_Import_Fetcher::fetch('https://dl.test/flaky', 'k4');
check($r['ok'] && $r['type'] === 'png', 'a timeout is retried on the command line');
CADCO_Import_Fetcher::discard($r['path']);

// Inside a web request the same timeout is not retried: the whole download
// has to fit under the host's request limit, and the next import retries it.
$files['https://dl.test/flaky-web'] = [['error', 'cURL error 28: Operation timed out'], [200, png(4, 5, 7)]];
$ajax = static fn () => true;
add_filter('wp_doing_ajax', $ajax);
$timeout = null;
$probe   = static function ($args) use (&$timeout) { $timeout = $args['timeout']; return $args; };
add_filter('http_request_args', $probe);
$requests = [];
$r = CADCO_Import_Fetcher::fetch('https://dl.test/flaky-web', 'k4w');
remove_filter('http_request_args', $probe);
remove_filter('wp_doing_ajax', $ajax);
check(!$r['ok'] && count($requests) === 1, 'in a web request a timeout is not retried');
check($timeout === 25, 'and the web timeout is 25 seconds (' . var_export($timeout, true) . ')');

$files['https://dl.test/down'] = [['error', 'cURL error 6: Could not resolve host']];
$r = CADCO_Import_Fetcher::fetch('https://dl.test/down', 'k5');
check(!$r['ok'] && $r['retryable'] && str_contains($r['error'], 'Could not resolve host'), 'a network failure is reported with the reason');

$files['https://dl.test/big'] = [200, '%PDF-1.4' . str_repeat('x', 4096)];
$cap = static fn () => 1024;
add_filter('cadco_import_media_max_bytes', $cap);
$r = CADCO_Import_Fetcher::fetch('https://dl.test/big', 'k6');
remove_filter('cadco_import_media_max_bytes', $cap);
check(!$r['ok'] && str_contains($r['error'], 'larger than'), 'a file over the size limit is refused');

$files['https://dl.test/svg'] = [200, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'];
$r = CADCO_Import_Fetcher::fetch('https://dl.test/svg', 'k7');
check(!$r['ok'], 'an SVG is refused');

$leftovers = glob(get_temp_dir() . 'cadco-media*') ?: [];
check(count(array_filter($leftovers, static fn ($f) => filemtime($f) >= time() - 60)) === 0, 'refused downloads leave no temporary files behind');

$requests = [];

/* -------------------------------------------------------------------------
   Catalogue
   ------------------------------------------------------------------------- */

$A = product('ZZ-INT-A');
$B = product('ZZ-INT-B');
$C = product('ZZ-INT-C');

$rows = [
    'A' => row('ZZ-INT-A', [
        'Images URL'     => "a.jpg\n" . SP . 'IMG1' . "\n" . SP . 'IMG2',
        'Spec Sheet URL' => SP . 'SPEC',
        'Manual URL'     => SP . 'MANA',
        'Video URL'      => 'https://protect.checkpoint.com/v2/r01/___https:/youtu.be/zzIntTest01___.YXAz',
    ]),
    'B' => row('ZZ-INT-B', [
        'Images URL'     => SP . 'IMG3',
        'Spec Sheet URL' => SP . 'SPEC2',
    ]),
    'C' => row('ZZ-INT-C', [
        'Spec Sheet URL' => SP . 'LOCKED',
        'Manual URL'     => 'file:///C:\\Users\\someone\\manual.pdf',
    ]),
];

section('Review on a first import');

// Products this import is about to create do not exist when Review is
// shown. Their resources must still be counted as new.
CADCO_Import_Media_Applier::reset_cache();
$preview = CADCO_Import_Media_Applier::preview(CADCO_Import_Media_Plan::build([
    row('ZZ-INT-NOT-YET', ['Video URL' => 'https://youtu.be/zzIntTest02']),
], 'cadcoltd232.sharepoint.com'));
check($preview['resources_new'] === 1, 'a resource cited only by a product still to be created counts as new (' . $preview['resources_new'] . ')');
CADCO_Import_Media_Applier::reset_cache();

section('Review media section renders');

// The view only loads inside wp-admin; load it here to render the section
// exactly as Review does, with a plan that has every kind of notice.
require_once get_stylesheet_directory() . '/inc/import/class-cadco-import-view.php';
require_once get_stylesheet_directory() . '/inc/import/class-cadco-import-admin.php';

$review_plan = CADCO_Import_Media_Plan::build([
    row('ZZ-INT-VIEW', [
        'Images URL'     => '<script>alert(1)</script>.jpg',
        'Spec Sheet URL' => SP . 'VIEWSPEC',
        'Manual URL'     => "https://cadcoltd232.sharepoint.com/:f:/s/CADCOMediaLibrary/EopViewFolder\nfile:///C:/Users/x/m.pdf",
    ]),
], 'cadcoltd232.sharepoint.com');

$section = new ReflectionMethod('CADCO_Import_View', 'section_media');
$section->setAccessible(true);
ob_start();
$section->invoke(null, ['plan' => $review_plan, 'preview' => CADCO_Import_Media_Applier::preview($review_plan), 'run_id' => '2026-11-13-000000-1-abcdefghijkl']);
$html = (string) ob_get_clean();
CADCO_Import_Media_Applier::reset_cache();

check(str_contains($html, 'id="cadco-section-media"'), 'the section renders with its anchor');
check(str_contains($html, '1 to download'), 'it counts the file to download');
check(substr_count($html, '<tr>') >= 3 + 4, 'it lists the three unusable links under the summary rows');
check(!str_contains($html, '<script>alert(1)</script>'), 'workbook text is escaped');
check(str_contains($html, 'action=export-archived-report') && str_contains($html, 'file=media') && str_contains($html, '_wpnonce='), 'the CSV link carries the run, the file and a nonce');
check(str_contains($html, 'id="cadco-import-refresh-media"') && str_contains($html, 'id="cadco-import-skip-media"'), 'both options are offered');

$nav = new ReflectionMethod('CADCO_Import_View', 'media_nav_meta');
$nav->setAccessible(true);
check($nav->invoke(null, ['plan' => $review_plan, 'preview' => ['downloads' => 0, 'images' => 0, 'resources_new' => 0, 'resources_changed' => 0, 'resources_removed' => 0], 'run_id' => '']) === '3 to fix', 'with nothing to download, the navigator shows the links to fix');

/* -------------------------------------------------------------------------
   1. First import
   ------------------------------------------------------------------------- */

section('First import');

$log = run_media(array_values($rows));

check(att('IMG1') > 0 && att('IMG2') > 0 && att('IMG3') > 0, 'every image downloaded into the library');
check((int) get_post_thumbnail_id($A) === att('IMG1'), 'the first image is the featured image');
check(gallery($A) === [att('IMG2')], 'the rest become the gallery, in order');
check((int) get_post_thumbnail_id($B) === att('IMG3'), 'a second product gets its own image');
check((string) get_post_meta(att('IMG1'), '_wp_attachment_image_alt', true) === 'Integration ZZ-INT-A', 'an image gets the product name as alt text');

check(att('SPEC') > 0 && att('SPEC') === att('SPEC2'), 'two links to the same bytes are one attachment');

$spec = resource('doc:spec-sheet:' . att('SPEC'));
check($spec !== null, 'a spec sheet resource is created');
check($spec !== null && tagged($spec->ID) === ['zz-int-a', 'zz-int-b'], 'the shared spec sheet is tagged to both products (' . implode(',', $spec ? tagged($spec->ID) : []) . ')');
check($spec !== null && (int) get_post_meta($spec->ID, 'cadco_resource_file_id', true) === att('SPEC'), 'the resource opens the downloaded file');
check($spec !== null && get_post_meta($spec->ID, 'cadco_resource_link_type', true) === 'file', 'the resource is a file');
check($spec !== null && has_term('spec-sheet', 'resource_media', $spec->ID), 'the resource is filed as a spec sheet');

$manual = resource('doc:manual:' . att('MANA'));
check($manual !== null && $manual->post_title === 'ZZ-INT-A Manual', 'a single-product document is titled with its model and kind (' . ($manual->post_title ?? '-') . ')');

$video = resource('video:youtube:zzIntTest01');
check($video !== null, 'a wrapped YouTube link becomes a video resource');
check($video !== null && $video->post_title === 'Panini Grills in action', 'the video takes its title from YouTube (' . ($video->post_title ?? '-') . ')');
check($video !== null && get_post_meta($video->ID, 'cadco_resource_url', true) === 'https://www.youtube.com/watch?v=zzIntTest01', 'the video stores its canonical address');
check($video !== null && has_post_thumbnail($video->ID), 'the video has a still, from the fallback thumbnail size');

check(has_failure($log, 'ZZ-INT-C') && str_contains(implode("\n", $log), 'cannot be accessed'), 'a locked file fails with the reason in words');
check(count(get_posts(['post_type' => 'resource', 'meta_key' => CADCO_Import_Media_Applier::RESOURCE_META, 'tax_query' => [['taxonomy' => 'resource_product', 'field' => 'slug', 'terms' => 'zz-int-c']]])) === 0, 'a failed download creates no resource');
check(!has_post_thumbnail($C), 'a product with no images gets none');

$plan = CADCO_Import_Media_Plan::build(array_values($rows), 'cadcoltd232.sharepoint.com');
check(count($plan->notices()) === 1 && $plan->notices()[0]->column === 'Manual URL', 'the PC path is a notice, not a download');

/* -------------------------------------------------------------------------
   2. Unchanged re-run
   ------------------------------------------------------------------------- */

section('Unchanged re-run');

$requests = [];
$writes   = 0;
$log      = run_media(array_values($rows));

check($writes === 0, sprintf('nothing is written (%d writes)', $writes));
check(count(array_filter($requests, static fn ($u) => !str_contains($u, 'LOCKED'))) === 0, 'nothing already in the library is downloaded again');

/* -------------------------------------------------------------------------
   3. What an editor did survives
   ------------------------------------------------------------------------- */

section('Editor changes survive');

$manual_image = (int) wp_insert_attachment(['post_mime_type' => 'image/png', 'post_title' => 'editor photo', 'post_status' => 'inherit'], false);
update_post_meta($A, '_product_image_gallery', implode(',', array_merge(gallery($A), [$manual_image])));
wp_update_post(['ID' => $spec->ID, 'post_title' => 'Panini grill specifications']);

run_media(array_values($rows));

check(in_array($manual_image, gallery($A), true), 'an image an editor added stays in the gallery');
check(get_post($spec->ID)->post_title === 'Panini grill specifications', 'an editor\'s resource title is kept');

/* -------------------------------------------------------------------------
   4. The workbook changes
   ------------------------------------------------------------------------- */

section('Workbook changes');

$rows['A']['Images URL'] = SP . 'IMG2';
$rows['A']['Video URL']  = '';
$rows['B']['Images URL'] = '';

$log = run_media(array_values($rows));

check((int) get_post_thumbnail_id($A) === att('IMG2'), 'a removed first image hands the featured slot to the next');
check(gallery($A) === [$manual_image], 'the gallery keeps only the editor\'s image');
check(!has_post_thumbnail($B), 'an emptied Images cell removes the importer\'s image');
check(get_post_meta($B, CADCO_Import_Media_Applier::IMAGES_META, true) === '', 'and forgets it owned one');
check(get_post_status($video->ID) === 'trash', 'a video no product cites any more is trashed');
check(get_post_status($spec->ID) === 'publish', 'a resource still cited stays');

/* -------------------------------------------------------------------------
   5. A failed download never takes anything away
   ------------------------------------------------------------------------- */

section('Failure is not destructive');

$rows['A']['Images URL'] = SP . 'IMG4';
$log = run_media(array_values($rows));

check(has_failure($log, 'ZZ-INT-A'), 'the failing image is reported');
check((int) get_post_thumbnail_id($A) === att('IMG2'), 'the product keeps the image it had');

$rows['A']['Images URL'] = SP . 'IMG2';

/* -------------------------------------------------------------------------
   6. A file replaced behind the same link
   ------------------------------------------------------------------------- */

section('Refresh');

$old_spec = att('SPEC');
$files[DL . 'SPEC']  = [200, pdf('shared spec v2')];
$files[DL . 'SPEC2'] = [200, pdf('shared spec v2')];

run_media(array_values($rows));
check(att('SPEC') === $old_spec, 'without refresh, a known link is not downloaded again');

run_media(array_values($rows), true);
$new_spec = att('SPEC');

check($new_spec !== $old_spec && $new_spec > 0, 'refresh downloads the new version');
check(att('SPEC2') === $new_spec, 'both links follow the new version');
check((int) get_post_meta($spec->ID, 'cadco_resource_file_id', true) === $new_spec, 'the same resource now opens the new file');
check(get_post_meta($spec->ID, CADCO_Import_Media_Applier::RESOURCE_META, true) === 'doc:spec-sheet:' . $new_spec, 'the resource is re-keyed, not replaced');
check(get_post($spec->ID)->post_title === 'Panini grill specifications', 'and keeps its editor\'s title');

/* -------------------------------------------------------------------------
   7. Links CADCO still has to fix
   ------------------------------------------------------------------------- */

section('Unusable links and late fixes');

// The shape ~50 real Manual cells have: a folder link as the text, a path
// on someone's PC as the hyperlink behind it.
$rows['C']['Manual URL'] = "https://cadcoltd232.sharepoint.com/:f:/s/CADCOMediaLibrary/EopFolderToken?e=wRo4bC\nfile:///C:\\Users\\apenn\\AppData\\Local\\Microsoft\\:i:\\s\\CADCOMediaLibrary\\IQAG9IGx%3fe=DOgZmM";
// A manual still on the site being replaced.
$rows['C']['Images URL'] = 'http://cadco-ltd.com/edit/resources/images/zz-int-c.png';
$files['http://cadco-ltd.com/edit/resources/images/zz-int-c.png'] = [200, png(1, 2, 3)];

$plan     = CADCO_Import_Media_Plan::build(array_values($rows), 'cadcoltd232.sharepoint.com');
$cnotices = array_values(array_filter($plan->notices(), static fn ($n) => $n->row === 2 && in_array($n->column, ['Manual URL', 'Images URL'], true)));

$requests = [];
$log      = run_media(array_values($rows));

check(count(array_filter($cnotices, static fn ($n) => $n->column === 'Manual URL')) === 2, 'a folder link and a PC path in one cell are two notices');
check(count(array_filter($requests, static fn ($u) => str_contains($u, 'EopFolderToken') || str_starts_with($u, 'file:'))) === 0, 'neither is ever requested');
check(count(get_posts(['post_type' => 'resource', 'meta_key' => CADCO_Import_Media_Applier::RESOURCE_META, 'tax_query' => [['taxonomy' => 'resource_product', 'field' => 'slug', 'terms' => 'zz-int-c']]])) === 0, 'and no manual resource is made for them');
check((int) get_post_thumbnail_id($C) > 0, 'a file on the old site is still imported');
check(count(array_filter($cnotices, static fn ($n) => $n->column === 'Images URL' && str_contains($n->message, 'cadco-ltd.com'))) === 1, 'and flagged as hosted on the site being replaced');

// CADCO unlock the spec sheet that failed on every run so far.
$files[DL . 'LOCKED'] = [200, pdf('spec for C, unlocked')];
$writes = 0;
$log    = run_media(array_values($rows));
$cspec  = resource('doc:spec-sheet:' . att('LOCKED'));

check(att('LOCKED') > 0, 'a file that was locked is downloaded once it is shared');
check($cspec !== null && tagged($cspec->ID) === ['zz-int-c'], 'and its resource appears, tagged to its product');
check(!has_failure($log, 'ZZ-INT-C'), 'with no failure reported any more');

/* -------------------------------------------------------------------------
   8. Renamed and removed products
   ------------------------------------------------------------------------- */

section('Product renames and removals');

wp_update_post(['ID' => $A, 'post_name' => 'zz-int-a-renamed']);

// As the command line does it: preview, then apply, in one process.
CADCO_Import_Media_Applier::reset_cache();
CADCO_Import_Media_Applier::preview(CADCO_Import_Media_Plan::build(array_values($rows), 'cadcoltd232.sharepoint.com'));
$queue = CADCO_Import_Media_Applier::queue(CADCO_Import_Media_Plan::build(array_values($rows), 'cadcoltd232.sharepoint.com'));
CADCO_Import_Applier::apply_jobs($queue, 0, 10000);
CADCO_Import_Media_Applier::reset_cache();

check(in_array('zz-int-a-renamed', tagged($spec->ID), true), 'the resource tag follows a product\'s new slug');

wp_trash_post($B);
unset($rows['B']);
run_media(array_values($rows));
check(tagged($spec->ID) === ['zz-int-a-renamed'], 'a removed product drops off the resource');
check(get_term_by('slug', 'zz-int-b', 'resource_product') === false, 'its now-unused tag is deleted');

printf("\n%d passed, %d failed\n", $GLOBALS['cadco_int_passes'], $GLOBALS['cadco_int_failures']);

// The shutdown function deletes the files and rolls the database back.
exit($GLOBALS['cadco_int_failures'] > 0 ? 1 : 0);
