<?php
/**
 * The documents and videos the single product page lists, plus the freight
 * class its specification band shows.
 *
 *   wp eval-file scripts/test-data/import-test-resources.php <extras.json> <products.json>
 *
 * These go into the `resource` post type rather than into product meta, because
 * that is the model the site already has: `resource_product` exists so a
 * resource can say which products it covers, and the resource centre is the
 * unfiltered view of the same set. A spec sheet belongs to one product and a
 * cleaning guide to a dozen, which is why resources are keyed by URL here --
 * one post per document, tagged with every product that cites it, rather than
 * the same PDF imported fifty times.
 *
 * Note this does add every imported document to the resource centre's own
 * listing, since that page lists all resources. They are flagged like
 * everything else the importer writes, so remove-test-products.php takes them
 * out again.
 *
 * Files are referenced by URL rather than sideloaded: these are the client's
 * own PDFs, already served from their site, and pulling ~90 of them into the
 * media library to then throw away would be a lot of weight for test content.
 * Video thumbnails ARE sideloaded -- the card has nothing to show without one.
 *
 * Re-running is safe: a resource whose URL is already present is left alone,
 * and its product tags are added to rather than replaced.
 */

if (!post_type_exists('resource')) {
    WP_CLI::error('The resource post type is not registered.');
}

$args     = $args ?? [];
$extrasIn = $args[0] ?? '';
$prodIn   = $args[1] ?? '';

if (!$extrasIn || !is_readable($extrasIn) || !$prodIn || !is_readable($prodIn)) {
    WP_CLI::error('Pass a readable extras JSON and products JSON.');
}

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

const CADCO_TEST_FLAG = '_cadco_test_product';
const CADCO_TEST_TERM = '_cadco_test_term';

$extras   = json_decode(file_get_contents($extrasIn), true);
$products = json_decode(file_get_contents($prodIn), true);

if (!is_array($extras) || !is_array($products)) {
    WP_CLI::error('Could not read the JSON.');
}

/**
 * Find or create a term, remembering only the ones we had to create.
 */
function cadco_res_term(string $taxonomy, string $name, string $slug): int
{
    $existing = get_term_by('slug', $slug, $taxonomy);

    if ($existing instanceof WP_Term) {
        return (int) $existing->term_id;
    }

    $created = wp_insert_term($name, $taxonomy, ['slug' => $slug]);

    if (is_wp_error($created)) {
        // A name collision still resolves to a usable term.
        $term = get_term_by('name', $name, $taxonomy);
        return $term instanceof WP_Term ? (int) $term->term_id : 0;
    }

    add_term_meta((int) $created['term_id'], CADCO_TEST_TERM, '1', true);

    return (int) $created['term_id'];
}

/**
 * A caption that is only the model number tells a reader on that model's own
 * page nothing it does not already know.
 */
function cadco_res_video_title(string $caption, string $sku, string $product): string
{
    $caption = trim($caption);

    if ($caption === '' || strcasecmp($caption, $sku) === 0) {
        return $product . ' overview';
    }

    // The source site shouts most of them.
    if ($caption === strtoupper($caption) && strlen($caption) > 4) {
        $caption = ucwords(strtolower($caption));
    }

    return $caption;
}

/* -------------------------------------------------------------------------
   Collect, keyed by URL.

   Each entry gathers the products that cite it, so a guide shared by a dozen
   ovens is one post carrying a dozen product tags.
   ------------------------------------------------------------------------- */
$wanted  = [];
$freight = 0;

foreach ($products as $row) {
    $sku = (string) $row['sku'];
    $pid = wc_get_product_id_by_sku($sku);

    if (!$pid) {
        WP_CLI::warning("no product for {$sku}");
        continue;
    }

    $extra = $extras[$sku] ?? null;

    if (!$extra) {
        continue;
    }

    $post  = get_post($pid);
    $title = get_the_title($pid);

    if ($extra['freight'] !== '') {
        update_post_meta($pid, '_cadco_freight_class', sanitize_text_field($extra['freight']));
        $freight++;
    }

    $entries = [];

    if ($extra['specSheet']) {
        $entries[] = [$extra['specSheet'], $title . ' spec sheet', 'Spec Sheet', 'spec-sheet', 'file', ''];
    }

    if ($extra['manual']) {
        $entries[] = [$extra['manual'], 'Instruction manual', 'Manual', 'manual', 'file', ''];
    }

    foreach ($extra['documents'] as $doc) {
        $entries[] = [$doc['url'], $doc['label'], 'Guide', 'guide', 'file', ''];
    }

    foreach ($extra['videos'] as $vid) {
        $entries[] = [
            $vid['url'],
            cadco_res_video_title($vid['title'], $sku, $title),
            'Video',
            'video',
            'video',
            $vid['youtubeId'],
        ];
    }

    foreach ($entries as [$url, $label, $media, $mediaSlug, $kind, $youtube]) {
        if (!isset($wanted[$url])) {
            $wanted[$url] = [
                'title'     => $label,
                'media'     => $media,
                'mediaSlug' => $mediaSlug,
                'kind'      => $kind,
                'youtube'   => $youtube,
                'products'  => [],
            ];
        }

        $wanted[$url]['products'][$post->post_name] = $title;
    }
}

WP_CLI::log(sprintf('%d unique resources across %d products; freight class on %d.', count($wanted), count($products), $freight));

/* -------------------------------------------------------------------------
   Write.
   ------------------------------------------------------------------------- */
$existingByUrl = [];

foreach (get_posts([
    'post_type'      => 'resource',
    'post_status'    => 'any',
    'posts_per_page' => -1,
    'fields'         => 'ids',
]) as $rid) {
    $u = (string) get_post_meta($rid, 'cadco_resource_url', true);

    if ($u !== '') {
        $existingByUrl[$u] = $rid;
    }
}

$made = 0;
$reused = 0;
$thumbs = 0;

foreach ($wanted as $url => $spec) {
    $rid = $existingByUrl[$url] ?? 0;

    if ($rid) {
        $reused++;
    } else {
        $rid = wp_insert_post([
            'post_type'   => 'resource',
            'post_status' => 'publish',
            'post_title'  => $spec['title'],
        ], true);

        if (is_wp_error($rid)) {
            WP_CLI::warning($spec['title'] . ': ' . $rid->get_error_message());
            continue;
        }

        update_post_meta($rid, CADCO_TEST_FLAG, '1');
        update_post_meta($rid, 'cadco_resource_url', esc_url_raw($url));
        update_post_meta($rid, 'cadco_resource_link_type', $spec['kind']);

        $mediaId = cadco_res_term('resource_media', $spec['media'], $spec['mediaSlug']);

        if ($mediaId) {
            wp_set_object_terms($rid, [$mediaId], 'resource_media');
        }

        // The card has nothing to show for a video without a still.
        if ($spec['youtube']) {
            foreach (['maxresdefault', 'hqdefault'] as $size) {
                $att = media_sideload_image(
                    "https://img.youtube.com/vi/{$spec['youtube']}/{$size}.jpg",
                    $rid,
                    $spec['title'],
                    'id'
                );

                if (!is_wp_error($att)) {
                    update_post_meta((int) $att, CADCO_TEST_FLAG, '1');
                    set_post_thumbnail($rid, (int) $att);
                    $thumbs++;
                    break;
                }
            }
        }

        $made++;
    }

    // Product tags are added to, never replaced: an existing resource may
    // already cover products this import knows nothing about.
    $termIds = [];

    foreach ($spec['products'] as $slug => $name) {
        $id = cadco_res_term('resource_product', $name, $slug);

        if ($id) {
            $termIds[] = $id;
        }
    }

    if ($termIds) {
        wp_set_object_terms($rid, $termIds, 'resource_product', true);
    }
}

WP_CLI::success("created {$made} resources, reused {$reused}, {$thumbs} video thumbnails");
