<?php

/**
 * The media stage: downloads what the workbook links to, and puts it where
 * the site reads it from.
 *
 * - Images become each product's featured image (the first link) and
 *   gallery (the rest).
 * - Spec sheets, manuals and videos become `resource` posts — the model the
 *   resource centre and the product page already read — one per document,
 *   tagged through `resource_product` with every product that cites it.
 *
 * Ownership is recorded, and it is what keeps the stage safe to run again
 * and again:
 *
 * - An attachment remembers every source it came from (_cadco_media_source,
 *   one row per source key) and its content hash. A source is downloaded
 *   once; a second link to the same bytes reuses the attachment.
 * - A product remembers which of its images the importer set
 *   (_cadco_import_images). Only those are ever replaced or removed, so an
 *   image an editor adds by hand survives every import.
 * - A resource the importer created carries its identity
 *   (_cadco_import_resource). Only those are retitled, retagged or trashed;
 *   a resource an editor made by hand is never touched.
 *
 * Every write is preceded by a comparison, so running the stage against an
 * unchanged workbook writes nothing — the same guarantee the product stage
 * gives (design spec §6.1).
 *
 * Failure is never destructive. A link that fails to download leaves
 * whatever the product or resource had before; only a cell that has been
 * emptied removes the importer's media for it.
 */

declare(strict_types=1);

final class CADCO_Import_Media_Applier
{
    public const SOURCE_META   = '_cadco_media_source';
    public const SHA_META      = '_cadco_media_sha256';
    public const IMAGES_META   = '_cadco_import_images';
    public const RESOURCE_META = '_cadco_import_resource';
    public const TITLE_META    = '_cadco_import_title';
    public const PRODUCT_TERM  = '_cadco_product_id';

    /** Resource jobs handle this many resources each. */
    private const RESOURCE_CHUNK = 15;

    /** Product jobs are cheap; this many products per job. */
    private const PRODUCT_CHUNK = 10;

    /** @var array<string, int>|null source key => attachment id */
    private static ?array $sources = null;

    /** @var array<string, int> sku => product id */
    private static array $products = [];

    /** @var array<int, int> product id => resource_product term id */
    private static array $terms = [];

    /* ---------------------------------------------------------------------
       Queue
       --------------------------------------------------------------------- */

    /**
     * The media stage's jobs, in the order they must run: downloads first,
     * then product images, then resources, then the clean-up of resources
     * the workbook no longer cites.
     *
     * Downloads are planned only for sources no attachment holds yet — or
     * every file, when $refresh asks for files CADCO may have replaced in
     * place behind the same link to be checked again.
     *
     * @return list<array<string, mixed>>
     */
    public static function queue(CADCO_Import_Media_Plan $plan, bool $refresh = false): array
    {
        // Remembered so the product edit screen resolves the relative links
        // it shows the same way the import did. update_option() writes only
        // when the value changes.
        if ($plan->tenant() !== '') {
            update_option('cadco_import_sharepoint_tenant', $plan->tenant(), false);
        }

        $jobs = [];

        foreach ($plan->files() as $key => $source) {
            if (!$refresh && self::attachment_for((string) $key) > 0) {
                continue;
            }

            $jobs[] = [
                'op'      => 'media-fetch',
                'key'     => (string) $key,
                'fetch'   => (string) $source['fetch'],
                'url'     => (string) $source['url'],
                'roles'   => array_values((array) $source['roles']),
                'models'  => array_slice(array_values((array) $source['models']), 0, 50),
                'refresh' => $refresh,
            ];
        }

        $products = [];

        foreach ($plan->products() as $model => $product) {
            $products[] = [
                'model'   => (string) $model,
                'images'  => array_values($product['images']),
                'has_any' => !empty($product['cells']['image']),
            ];
        }

        foreach (array_chunk($products, self::PRODUCT_CHUNK) as $chunk) {
            $jobs[] = ['op' => 'media-products', 'products' => $chunk];
        }

        $entries = self::resource_entries($plan);

        foreach (array_chunk(array_keys($entries), self::RESOURCE_CHUNK) as $chunk) {
            $jobs[] = ['op' => 'media-resources', 'entries' => $entries, 'only' => $chunk];
        }

        $jobs[] = ['op' => 'media-cleanup', 'entries' => $entries];

        return $jobs;
    }

    /**
     * The plan's resources with what the jobs need to know about each source,
     * keyed by a stable index.
     *
     * @return array<int, array{role:string,key:string,models:list<string>,kind:string,url:string,video:array|null}>
     */
    private static function resource_entries(CADCO_Import_Media_Plan $plan): array
    {
        $sources = $plan->sources();
        $out     = [];

        foreach ($plan->resources() as $resource) {
            $source = $sources[$resource['key']] ?? null;

            if ($source === null) {
                continue;
            }

            $out[] = [
                'role'   => $resource['role'],
                'key'    => $resource['key'],
                'models' => $resource['models'],
                'kind'   => (string) $source['kind'],
                'url'    => (string) $source['url'],
                'video'  => $source['video'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * Run one media job. Returns a log line; a failure starts 'MEDIA FAILED'
     * so the screen can count it apart from product failures.
     *
     * @param array<string, mixed> $job
     */
    public static function run(array $job): string
    {
        try {
            switch ($job['op']) {
                case 'media-fetch':
                    return self::fetch_job($job);

                case 'media-products':
                    return self::products_job($job);

                case 'media-resources':
                    return self::resources_job($job);

                case 'media-cleanup':
                    return self::cleanup_job($job);
            }

            return 'MEDIA FAILED: unknown job ' . (string) $job['op'];
        } catch (\Throwable $e) {
            return 'MEDIA FAILED: ' . $e->getMessage();
        }
    }

    /* ---------------------------------------------------------------------
       Downloads
       --------------------------------------------------------------------- */

    /**
     * @param array<string, mixed> $job
     */
    private static function fetch_job(array $job): string
    {
        $key      = (string) $job['key'];
        $existing = self::attachment_for($key);
        $label    = self::describe($job);

        if ($existing > 0 && empty($job['refresh'])) {
            return sprintf('Media already in the library: %s', $label);
        }

        $file = CADCO_Import_Fetcher::fetch((string) $job['fetch'], $key, (string) $job['url']);

        if (!$file['ok']) {
            return sprintf('MEDIA FAILED %s: %s', $label, $file['error']);
        }

        $roles = (array) $job['roles'];

        // An Images link must be an image: the product card and gallery have
        // nothing to show for a PDF. Any other role takes any accepted file.
        if ($roles === ['image'] && !CADCO_Import_Fetcher::TYPES[$file['type']]['image']) {
            CADCO_Import_Fetcher::discard($file['path']);

            return sprintf('MEDIA FAILED %s: the Images link is a %s, not an image.', $label, strtoupper($file['type']));
        }

        // Same bytes already in the library under another link: reuse them.
        $same = self::attachment_by_sha($file['sha256']);

        if ($same > 0) {
            CADCO_Import_Fetcher::discard($file['path']);

            if ($existing > 0 && $existing !== $same) {
                // A refresh found that this link now serves bytes another
                // link already brought in (two links to one file, both
                // replaced). Same hand-over as a fresh download.
                self::move_source($key, $existing, $same);
                self::rekey_resources($existing, $same);
            } elseif ($existing !== $same) {
                add_post_meta($same, self::SOURCE_META, $key);
                self::$sources[$key] = $same;
            }

            return $existing === $same
                ? sprintf('Media unchanged: %s', $label)
                : sprintf('Media matched an existing file: %s', $label);
        }

        if (in_array($file['type'], ['jpeg'], true)) {
            self::to_srgb($file['path']);
        }

        $title = (string) preg_replace('~\.[^.]+$~', '', $file['name']);
        $id    = self::sideload($file['path'], $file['name'], $title);

        if ($id <= 0) {
            CADCO_Import_Fetcher::discard($file['path']);

            return sprintf('MEDIA FAILED %s: the file could not be added to the media library.', $label);
        }

        update_post_meta($id, self::SHA_META, $file['sha256']);

        if ($existing > 0) {
            // A refresh found new bytes behind an old link. The new
            // attachment takes the link over; everything that resolves
            // media by source — product images, resources — follows it in
            // the jobs after this one. The old file stays in the library
            // rather than being deleted out from under anything that
            // linked it by hand.
            self::move_source($key, $existing, $id);
            self::rekey_resources($existing, $id);

            return sprintf('Media updated (the file had changed): %s', $label);
        }

        add_post_meta($id, self::SOURCE_META, $key);
        self::$sources[$key] = $id;

        return sprintf('Downloaded %s (%s)', $label, size_format($file['size']));
    }

    private static function move_source(string $key, int $from, int $to): void
    {
        delete_post_meta($from, self::SOURCE_META, $key);
        add_post_meta($to, self::SOURCE_META, $key);
        self::$sources[$key] = $to;
    }

    /**
     * Point the resources that opened an old file at its replacement.
     *
     * A resource's identity names its attachment, so a refreshed file would
     * otherwise read as a new resource — created alongside the old one,
     * which the clean-up would then trash, taking any editing done to it
     * along. Re-keying in place keeps the post, its title and its URL. Done
     * only once the old file holds no other link: while it still does, it is
     * still in use as itself.
     */
    private static function rekey_resources(int $from, int $to): void
    {
        if (get_post_meta($from, self::SOURCE_META) !== []) {
            return;
        }

        global $wpdb;

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s",
                self::RESOURCE_META,
                'doc:%:' . $wpdb->esc_like((string) $from)
            )
        ) ?: [];

        foreach ($ids as $resource_id) {
            $identity = (string) get_post_meta((int) $resource_id, self::RESOURCE_META, true);

            if (preg_match('~^doc:([^:]+):' . $from . '$~', $identity, $m) !== 1) {
                continue;
            }

            update_post_meta((int) $resource_id, self::RESOURCE_META, 'doc:' . $m[1] . ':' . $to);
            update_post_meta((int) $resource_id, 'cadco_resource_file_id', $to);
            update_post_meta((int) $resource_id, 'cadco_resource_url', esc_url_raw((string) wp_get_attachment_url($to)));
        }
    }

    /**
     * Put a downloaded file into the media library. Returns its id, or 0.
     */
    private static function sideload(string $path, string $name, string $title): int
    {
        if (!function_exists('media_handle_sideload')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        // No preview images for PDFs. WordPress renders a PDF's first page
        // into every image size when it can (Imagick with Ghostscript); the
        // site never shows those — a document resource is a link, not a
        // picture — and it is the slowest step of importing one, seconds per
        // file across a few hundred spec sheets and manuals.
        $no_previews = static fn (): array => [];
        add_filter('fallback_intermediate_image_sizes', $no_previews);

        $id = media_handle_sideload(['name' => $name, 'tmp_name' => $path], 0, $title);

        remove_filter('fallback_intermediate_image_sizes', $no_previews);

        if (is_wp_error($id)) {
            return 0;
        }

        return (int) $id;
    }

    /**
     * Convert a CMYK JPEG to sRGB in place.
     *
     * CADCO's photography is print-ready CMYK. Browsers render CMYK JPEGs,
     * but without colour management the colours shift — and every
     * thumbnail WordPress cuts inherits the problem. Converting once, before
     * the file enters the library, fixes the original and every size.
     *
     * Best effort: without Imagick, or on any error, the file is left as it
     * was, which is no worse than not trying.
     */
    private static function to_srgb(string $path): void
    {
        if (!class_exists('Imagick')) {
            return;
        }

        try {
            $image = new \Imagick($path);

            if ($image->getImageColorspace() !== \Imagick::COLORSPACE_CMYK) {
                $image->clear();

                return;
            }

            $image->transformImageColorspace(\Imagick::COLORSPACE_SRGB);
            // The embedded CMYK profile no longer describes the pixels.
            $image->profileImage('icc', null);
            $image->setImageCompressionQuality(90);
            $image->writeImage($path);
            $image->clear();
        } catch (\Throwable $e) {
            // Leave the original in place.
        }
    }

    /* ---------------------------------------------------------------------
       Product images
       --------------------------------------------------------------------- */

    /**
     * @param array<string, mixed> $job
     */
    private static function products_job(array $job): string
    {
        $changed = 0;
        $missing = [];

        foreach ((array) $job['products'] as $product) {
            $post_id = self::product_id((string) $product['model']);

            if ($post_id <= 0) {
                $missing[] = (string) $product['model'];
                continue;
            }

            if (self::assign_images($post_id, (array) $product['images'], (bool) $product['has_any'], false)) {
                $changed++;
            }
        }

        $line = sprintf('Product images checked for %d products, %d changed', count((array) $job['products']), $changed);

        return $missing === [] ? $line : $line . sprintf(' (not in the catalogue: %s)', implode(', ', $missing));
    }

    /**
     * Make a product's featured image and gallery match the workbook.
     *
     * Returns whether anything changed (or, with $dry, would change).
     *
     * @param list<string> $keys image source keys, in the workbook's order
     */
    public static function assign_images(int $post_id, array $keys, bool $has_any, bool $dry): bool
    {
        $managed = array_map('intval', (array) get_post_meta($post_id, self::IMAGES_META, true));
        $managed = array_values(array_filter($managed));

        $wanted = [];

        foreach ($keys as $key) {
            $id = self::attachment_for((string) $key);

            if ($id > 0 && !in_array($id, $wanted, true)) {
                $wanted[] = $id;
            }
        }

        // Links that all failed: the workbook still wants images here, the
        // importer just could not get them this time. Keep what is there.
        if ($wanted === [] && $has_any) {
            return false;
        }

        $featured = (int) get_post_thumbnail_id($post_id);
        $gallery  = self::gallery($post_id);

        // What an editor added by hand: anything the importer did not set.
        $manual_gallery = array_values(array_filter($gallery, static fn (int $id): bool => !in_array($id, $managed, true)));

        if ($wanted === []) {
            // The cell has been emptied: take the importer's images away,
            // leave the editor's.
            $new_featured = in_array($featured, $managed, true) ? 0 : $featured;
            $new_gallery  = $manual_gallery;
        } else {
            $new_featured = $wanted[0];
            $new_gallery  = array_slice($wanted, 1);

            // An editor's own featured image is not discarded when the
            // workbook supplies one; it moves into the gallery.
            if ($featured > 0 && !in_array($featured, $managed, true) && !in_array($featured, $wanted, true)) {
                $manual_gallery = array_values(array_unique(array_merge([$featured], $manual_gallery)));
            }

            foreach ($manual_gallery as $id) {
                if (!in_array($id, $new_gallery, true) && $id !== $new_featured) {
                    $new_gallery[] = $id;
                }
            }
        }

        $changed = $new_featured !== $featured || $new_gallery !== $gallery || $wanted !== $managed;

        if (!$changed || $dry) {
            return $changed;
        }

        if ($new_featured > 0) {
            set_post_thumbnail($post_id, $new_featured);
        } else {
            delete_post_thumbnail($post_id);
        }

        update_post_meta($post_id, '_product_image_gallery', implode(',', $new_gallery));

        if ($wanted === []) {
            delete_post_meta($post_id, self::IMAGES_META);
        } else {
            update_post_meta($post_id, self::IMAGES_META, $wanted);
        }

        // Alt text from the product's name, where the image has none: a
        // product photograph with an empty alt is invisible to a screen
        // reader and to image search.
        $name = (string) get_the_title($post_id);

        foreach ($wanted as $id) {
            if ((string) get_post_meta($id, '_wp_attachment_image_alt', true) === '' && $name !== '') {
                update_post_meta($id, '_wp_attachment_image_alt', $name);
            }

            // Attach an unattached file to the first product that uses it,
            // so the media library's "Uploaded to" column says where it is
            // used. A file already attached elsewhere stays put.
            if ((int) wp_get_post_parent_id($id) === 0) {
                wp_update_post(['ID' => $id, 'post_parent' => $post_id]);
            }
        }

        if (function_exists('wc_delete_product_transients')) {
            wc_delete_product_transients($post_id);
        }

        return true;
    }

    /**
     * @return list<int>
     */
    private static function gallery(int $post_id): array
    {
        $raw = (string) get_post_meta($post_id, '_product_image_gallery', true);

        return array_values(array_filter(array_map('intval', explode(',', $raw))));
    }

    /* ---------------------------------------------------------------------
       Resources
       --------------------------------------------------------------------- */

    /**
     * @param array<string, mixed> $job
     */
    private static function resources_job(array $job): string
    {
        $entries = (array) $job['entries'];
        $groups  = self::group_resources($entries);
        $counts  = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'waiting' => 0];
        $done    = [];
        $failed  = [];

        foreach ((array) $job['only'] as $index) {
            $entry = $entries[$index] ?? null;

            if (!is_array($entry)) {
                continue;
            }

            $identity = self::identity($entry);

            if ($identity === '') {
                // The file has not been downloaded (its fetch failed). There
                // is nothing to point a resource at yet.
                $counts['waiting']++;
                continue;
            }

            if (isset($done[$identity])) {
                continue;
            }

            $done[$identity] = true;

            $outcome = self::upsert_resource($identity, $groups[$identity], false);

            if (str_starts_with($outcome, 'failed:')) {
                $failed[] = substr($outcome, 7);
                continue;
            }

            $counts[$outcome]++;
        }

        $line = sprintf(
            'Resources: %d created, %d updated, %d unchanged%s',
            $counts['created'],
            $counts['updated'],
            $counts['unchanged'],
            $counts['waiting'] > 0 ? sprintf(', %d waiting for a file that did not download', $counts['waiting']) : ''
        );

        return $failed === [] ? $line : 'MEDIA FAILED ' . $line . ': ' . implode('; ', $failed);
    }

    /**
     * Merge entries that turn out to be one resource: two links to the same
     * file in the same role are one spec sheet, cited by both links'
     * products.
     *
     * @param array<int, array<string, mixed>> $entries
     * @return array<string, array{role:string,kind:string,attachment:int,url:string,video:array|null,models:list<string>}>
     */
    private static function group_resources(array $entries): array
    {
        $groups = [];

        foreach ($entries as $entry) {
            $identity = self::identity($entry);

            if ($identity === '') {
                continue;
            }

            if (!isset($groups[$identity])) {
                $groups[$identity] = [
                    'role'       => (string) $entry['role'],
                    'kind'       => (string) $entry['kind'],
                    'attachment' => $entry['kind'] === CADCO_Import_Media::KIND_VIDEO ? 0 : self::attachment_for((string) $entry['key']),
                    'url'        => (string) $entry['url'],
                    'video'      => $entry['video'] ?? null,
                    'models'     => [],
                ];
            }

            foreach ((array) $entry['models'] as $model) {
                if (!in_array($model, $groups[$identity]['models'], true)) {
                    $groups[$identity]['models'][] = (string) $model;
                }
            }
        }

        return $groups;
    }

    /**
     * What makes a resource one resource: its role and the file it opens,
     * or the video it plays. '' when its file has never been downloaded.
     *
     * @param array<string, mixed> $entry
     */
    private static function identity(array $entry): string
    {
        if ($entry['kind'] === CADCO_Import_Media::KIND_VIDEO && is_array($entry['video'] ?? null)) {
            return 'video:' . $entry['video']['provider'] . ':' . $entry['video']['id'];
        }

        $attachment = self::attachment_for((string) $entry['key']);

        return $attachment > 0 ? 'doc:' . $entry['role'] . ':' . $attachment : '';
    }

    /**
     * Create or bring up to date the resource for one identity.
     *
     * @param array{role:string,kind:string,attachment:int,url:string,video:array|null,models:list<string>} $group
     * @return string 'created' | 'updated' | 'unchanged' | 'failed:<why>'
     */
    public static function upsert_resource(string $identity, array $group, bool $dry): string
    {
        $product_ids = [];

        foreach ($group['models'] as $model) {
            $id = self::product_id($model);

            if ($id > 0) {
                $product_ids[] = $id;
            }
        }

        if ($product_ids === []) {
            return 'unchanged';
        }

        $existing = self::resource_by_identity($identity);

        // An editor trashed it: that is a decision about this resource, and
        // the importer does not bring it back.
        if ($existing !== null && $existing->post_status === 'trash') {
            return 'unchanged';
        }

        $is_video = str_starts_with($identity, 'video:');
        $media    = $is_video ? 'video' : $group['role'];
        $title    = self::resource_title($group, $product_ids, $existing);
        $kind     = $is_video ? 'video' : 'file';
        $url      = $is_video && is_array($group['video'])
            ? CADCO_Import_Media::video_url($group['video'])
            : (string) wp_get_attachment_url($group['attachment']);
        $terms    = [];

        foreach ($product_ids as $product_id) {
            $term = self::product_term($product_id, $dry);

            if ($term > 0) {
                $terms[] = $term;
            }
        }

        sort($terms);

        if ($existing === null) {
            if ($dry) {
                return 'created';
            }

            $id = wp_insert_post([
                'post_type'   => 'resource',
                'post_status' => 'publish',
                'post_title'  => $title,
            ], true);

            if (is_wp_error($id)) {
                return 'failed:' . $id->get_error_message();
            }

            $id = (int) $id;
            update_post_meta($id, self::RESOURCE_META, $identity);
            update_post_meta($id, self::TITLE_META, $title);
            update_post_meta($id, 'cadco_resource_link_type', $kind);
            update_post_meta($id, 'cadco_resource_url', esc_url_raw($url));

            if (!$is_video) {
                update_post_meta($id, 'cadco_resource_file_id', $group['attachment']);
            }

            self::set_media_term($id, $media);
            wp_set_object_terms($id, $terms, 'resource_product');

            if ($is_video && is_array($group['video'])) {
                self::video_thumbnail($id, $group['video'], $title);
            }

            return 'created';
        }

        $id      = (int) $existing->ID;
        $changes = [];

        // The title is the importer's only while it is still the one the
        // importer wrote. An editor's rename is kept.
        $last_title = (string) get_post_meta($id, self::TITLE_META, true);

        if ($existing->post_title === $last_title && $title !== $last_title) {
            $changes['title'] = $title;
        }

        if ((string) get_post_meta($id, 'cadco_resource_link_type', true) !== $kind) {
            $changes['kind'] = $kind;
        }

        if ((string) get_post_meta($id, 'cadco_resource_url', true) !== esc_url_raw($url)) {
            $changes['url'] = $url;
        }

        if (!$is_video && (int) get_post_meta($id, 'cadco_resource_file_id', true) !== $group['attachment']) {
            $changes['file'] = $group['attachment'];
        }

        $current_terms = wp_get_object_terms($id, 'resource_product', ['fields' => 'ids']);
        $current_terms = is_wp_error($current_terms) ? [] : array_map('intval', $current_terms);
        sort($current_terms);

        if ($current_terms !== $terms) {
            $changes['terms'] = $terms;
        }

        if (!self::has_media_term($id, $media)) {
            $changes['media'] = $media;
        }

        if ($changes === []) {
            return 'unchanged';
        }

        if ($dry) {
            return 'updated';
        }

        if (isset($changes['title'])) {
            wp_update_post(['ID' => $id, 'post_title' => $changes['title']]);
            update_post_meta($id, self::TITLE_META, $changes['title']);
        }

        if (isset($changes['kind'])) {
            update_post_meta($id, 'cadco_resource_link_type', $changes['kind']);
        }

        if (isset($changes['url'])) {
            update_post_meta($id, 'cadco_resource_url', esc_url_raw($changes['url']));
        }

        if (isset($changes['file'])) {
            update_post_meta($id, 'cadco_resource_file_id', $changes['file']);
        }

        if (isset($changes['terms'])) {
            wp_set_object_terms($id, $changes['terms'], 'resource_product');
        }

        if (isset($changes['media'])) {
            self::set_media_term($id, $changes['media']);
        }

        return 'updated';
    }

    /**
     * A resource's title.
     *
     * One product: that product's name — the card already says what kind of
     * document it is, so "Half Size Convection Oven" under "Spec Sheet"
     * reads completely. Shared by several: the file's own name, which is
     * what CADCO called the document ("Cadco Panini Grills"), since no one
     * product's name describes it. Videos take the video's own title.
     *
     * @param array{role:string,kind:string,attachment:int,url:string,video:array|null,models:list<string>} $group
     * @param list<int> $product_ids
     */
    private static function resource_title(array $group, array $product_ids, ?WP_Post $existing): string
    {
        if (is_array($group['video'])) {
            // Fetched once, when the resource is made. A title CADCO change
            // on YouTube later is theirs to change here too.
            if ($existing !== null) {
                return (string) get_post_meta($existing->ID, self::TITLE_META, true) ?: $existing->post_title;
            }

            $title = self::video_title($group['video']);

            if ($title !== '') {
                return $title;
            }

            return count($product_ids) === 1
                ? sprintf('%s video', get_the_title($product_ids[0]))
                : 'Product video';
        }

        if (count($product_ids) === 1) {
            return (string) get_the_title($product_ids[0]);
        }

        $file = (string) get_the_title($group['attachment']);
        $file = trim((string) preg_replace('~[_]+~', ' ', $file));

        return $file !== '' ? $file : (string) get_the_title($product_ids[0]);
    }

    /**
     * A video's title from the provider's oEmbed endpoint, or ''.
     *
     * @param array{provider: string, id: string} $video
     */
    private static function video_title(array $video): string
    {
        $data = self::oembed($video);

        return isset($data['title']) && is_string($data['title']) ? trim(wp_strip_all_tags($data['title'])) : '';
    }

    /**
     * @param array{provider: string, id: string} $video
     * @return array<string, mixed>
     */
    private static function oembed(array $video): array
    {
        $endpoint = $video['provider'] === 'vimeo'
            ? 'https://vimeo.com/api/oembed.json?url=' . rawurlencode(CADCO_Import_Media::video_url($video))
            : 'https://www.youtube.com/oembed?format=json&url=' . rawurlencode(CADCO_Import_Media::video_url($video));

        $response = wp_safe_remote_get($endpoint, ['timeout' => 10]);

        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return [];
        }

        $data = json_decode((string) wp_remote_retrieve_body($response), true);

        return is_array($data) ? $data : [];
    }

    /**
     * The still a video's card shows. The provider's own thumbnail, stored
     * in the library like any other image so the page never hot-links it.
     *
     * @param array{provider: string, id: string} $video
     */
    private static function video_thumbnail(int $resource_id, array $video, string $title): void
    {
        $key = 'thumb:' . $video['provider'] . ':' . $video['id'];
        $id  = self::attachment_for($key);

        if ($id <= 0) {
            $candidates = $video['provider'] === 'youtube'
                ? [
                    'https://img.youtube.com/vi/' . $video['id'] . '/maxresdefault.jpg',
                    'https://img.youtube.com/vi/' . $video['id'] . '/hqdefault.jpg',
                ]
                : array_filter([(string) (self::oembed($video)['thumbnail_url'] ?? '')]);

            foreach ($candidates as $url) {
                $file = CADCO_Import_Fetcher::fetch($url, $key, '', 1);

                if (!$file['ok'] || !CADCO_Import_Fetcher::TYPES[$file['type']]['image']) {
                    continue;
                }

                $name = sanitize_file_name($video['provider'] . '-' . $video['id'] . '.' . CADCO_Import_Fetcher::TYPES[$file['type']]['ext']);
                $id   = self::sideload($file['path'], $name, $title);

                if ($id > 0) {
                    add_post_meta($id, self::SOURCE_META, $key);
                    update_post_meta($id, self::SHA_META, $file['sha256']);
                    update_post_meta($id, '_wp_attachment_image_alt', $title);
                    self::$sources[$key] = $id;
                    break;
                }

                CADCO_Import_Fetcher::discard($file['path']);
            }
        }

        if ($id > 0 && !has_post_thumbnail($resource_id)) {
            set_post_thumbnail($resource_id, $id);
        }
    }

    /**
     * The resource_product term that stands for a product.
     *
     * The resource block finds a product's resources by its slug, so the
     * term's slug must be the product's — and must follow it when a rename
     * changes it. The term remembers its product (_cadco_product_id), which
     * is how it is found again after the slug has moved.
     */
    private static function product_term(int $product_id, bool $dry): int
    {
        if (isset(self::$terms[$product_id])) {
            return self::$terms[$product_id];
        }

        $slug = (string) get_post_field('post_name', $product_id);
        $sku  = (string) get_post_meta($product_id, '_sku', true);
        $name = trim(get_the_title($product_id) . ' ' . $sku);

        if ($slug === '') {
            return 0;
        }

        $found = get_terms([
            'taxonomy'   => 'resource_product',
            'hide_empty' => false,
            'number'     => 1,
            'meta_key'   => self::PRODUCT_TERM,
            'meta_value' => (string) $product_id,
        ]);

        $term = !is_wp_error($found) && $found !== [] ? $found[0] : get_term_by('slug', $slug, 'resource_product');

        if ($term instanceof WP_Term) {
            if (!$dry) {
                if ($term->slug !== $slug || $term->name !== $name) {
                    wp_update_term($term->term_id, 'resource_product', ['slug' => $slug, 'name' => $name]);
                }

                if ((string) get_term_meta($term->term_id, self::PRODUCT_TERM, true) !== (string) $product_id) {
                    update_term_meta($term->term_id, self::PRODUCT_TERM, (string) $product_id);
                }
            }

            return self::$terms[$product_id] = (int) $term->term_id;
        }

        if ($dry) {
            return 0;
        }

        $created = wp_insert_term($name, 'resource_product', ['slug' => $slug]);

        if (is_wp_error($created)) {
            return 0;
        }

        update_term_meta((int) $created['term_id'], self::PRODUCT_TERM, (string) $product_id);

        return self::$terms[$product_id] = (int) $created['term_id'];
    }

    private static function set_media_term(int $resource_id, string $slug): void
    {
        $term = get_term_by('slug', $slug, 'resource_media');

        if (!$term instanceof WP_Term) {
            $names   = cadco_import_resource_media_terms();
            $created = wp_insert_term($names[$slug] ?? ucwords(str_replace('-', ' ', $slug)), 'resource_media', ['slug' => $slug]);

            if (is_wp_error($created)) {
                return;
            }

            $term_id = (int) $created['term_id'];
        } else {
            $term_id = (int) $term->term_id;
        }

        wp_set_object_terms($resource_id, [$term_id], 'resource_media');
    }

    private static function has_media_term(int $resource_id, string $slug): bool
    {
        $terms = wp_get_object_terms($resource_id, 'resource_media', ['fields' => 'slugs']);

        return !is_wp_error($terms) && $terms === [$slug];
    }

    /**
     * @param array<string, mixed> $job
     */
    private static function cleanup_job(array $job): string
    {
        // A failed download cannot cost an existing resource its place
        // here: a source that was ever downloaded keeps its attachment, so
        // it still has an identity this run and stays wanted. Only a source
        // the workbook no longer cites drops out.
        $wanted  = array_flip(array_keys(self::group_resources((array) $job['entries'])));
        $trashed = [];

        foreach (self::importer_resources() as $resource) {
            $identity = (string) get_post_meta($resource->ID, self::RESOURCE_META, true);

            if (isset($wanted[$identity]) || $resource->post_status === 'trash') {
                continue;
            }

            wp_trash_post($resource->ID);
            $trashed[] = $resource->post_title;
        }

        $terms = self::remove_orphan_product_terms();
        $line  = $trashed === []
            ? 'Resources no longer cited: none'
            : sprintf('Resources no longer cited, moved to the trash: %s', implode(', ', $trashed));

        return $terms > 0 ? $line . sprintf('; %d unused product tag(s) removed from the resource library', $terms) : $line;
    }

    /**
     * Delete resource_product terms the importer made whose product is gone
     * and which no resource uses any more. Only the importer's own terms
     * (they carry _cadco_product_id); a term an editor made is theirs.
     */
    private static function remove_orphan_product_terms(): int
    {
        $terms = get_terms([
            'taxonomy'   => 'resource_product',
            'hide_empty' => false,
            'meta_key'   => self::PRODUCT_TERM,
        ]);

        if (is_wp_error($terms)) {
            return 0;
        }

        $removed = 0;

        foreach ($terms as $term) {
            $product = get_post((int) get_term_meta($term->term_id, self::PRODUCT_TERM, true));
            $live    = $product instanceof WP_Post && $product->post_type === 'product' && $product->post_status !== 'trash';

            if (!$live && (int) $term->count === 0) {
                wp_delete_term($term->term_id, 'resource_product');
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * How the media stage would change the site, without changing it — for
     * the review screen.
     *
     * @return array{downloads:int,images:int,resources_new:int,resources_changed:int,resources_removed:int}
     */
    public static function preview(CADCO_Import_Media_Plan $plan): array
    {
        $downloads = 0;

        foreach (array_keys($plan->files()) as $key) {
            if (self::attachment_for((string) $key) <= 0) {
                $downloads++;
            }
        }

        $images = 0;

        foreach ($plan->products() as $model => $product) {
            $post_id = self::product_id((string) $model);

            if ($post_id > 0 && self::assign_images($post_id, $product['images'], !empty($product['cells']['image']), true)) {
                $images++;
            }
        }

        $entries = self::resource_entries($plan);
        $groups  = self::group_resources($entries);
        $new     = 0;
        $changed = 0;

        foreach ($groups as $identity => $group) {
            $outcome = self::upsert_resource($identity, $group, true);

            if ($outcome === 'created') {
                $new++;
            } elseif ($outcome === 'updated') {
                $changed++;
            }
        }

        // Resources whose file is still to be downloaded will be created
        // once it is.
        foreach ($entries as $entry) {
            if (self::identity($entry) === '') {
                $new++;
            }
        }

        $removed = 0;
        $wanted  = array_flip(array_keys($groups));

        foreach (self::importer_resources() as $resource) {
            if (!isset($wanted[(string) get_post_meta($resource->ID, self::RESOURCE_META, true)])) {
                $removed++;
            }
        }

        return [
            'downloads'         => $downloads,
            'images'            => $images,
            'resources_new'     => $new,
            'resources_changed' => $changed,
            'resources_removed' => $removed,
        ];
    }

    /* ---------------------------------------------------------------------
       Lookups
       --------------------------------------------------------------------- */

    /**
     * The attachment that holds a source, or 0.
     *
     * Every source key in the library is read in one query and cached for
     * the request: a batch resolves hundreds of keys, and one query per key
     * would be hundreds of queries.
     */
    public static function attachment_for(string $key): int
    {
        if (self::$sources === null) {
            global $wpdb;

            self::$sources = [];

            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT pm.meta_value AS source_key, MAX(pm.post_id) AS post_id
                       FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                      WHERE pm.meta_key = %s
                        AND p.post_type = 'attachment'
                   GROUP BY pm.meta_value",
                    self::SOURCE_META
                ),
                ARRAY_A
            ) ?: [];

            foreach ($rows as $row) {
                self::$sources[(string) $row['source_key']] = (int) $row['post_id'];
            }
        }

        return self::$sources[$key] ?? 0;
    }

    private static function attachment_by_sha(string $sha): int
    {
        if ($sha === '') {
            return 0;
        }

        $found = get_posts([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'DESC',
            'meta_key'       => self::SHA_META,
            'meta_value'     => $sha,
            'no_found_rows'  => true,
        ]);

        return $found ? (int) $found[0] : 0;
    }

    private static function product_id(string $model): int
    {
        if (!array_key_exists($model, self::$products)) {
            self::$products[$model] = (int) (CADCO_Import_Repository::find_by_sku($model) ?? 0);
        }

        return self::$products[$model];
    }

    private static function resource_by_identity(string $identity): ?WP_Post
    {
        $found = get_posts([
            'post_type'      => 'resource',
            'post_status'    => ['publish', 'draft', 'pending', 'private', 'future', 'trash'],
            'posts_per_page' => 1,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'meta_key'       => self::RESOURCE_META,
            'meta_value'     => $identity,
            'no_found_rows'  => true,
        ]);

        return $found ? $found[0] : null;
    }

    /**
     * @return list<WP_Post>
     */
    private static function importer_resources(): array
    {
        return get_posts([
            'post_type'      => 'resource',
            'post_status'    => ['publish', 'draft', 'pending', 'private', 'future'],
            'posts_per_page' => -1,
            'meta_key'       => self::RESOURCE_META,
            'no_found_rows'  => true,
        ]);
    }

    /**
     * Forget everything cached for the request. Tests and the CLI call it
     * between runs; a web request never lives long enough to need it.
     */
    public static function reset_cache(): void
    {
        self::$sources  = null;
        self::$products = [];
        self::$terms    = [];
    }

    /**
     * @param array<string, mixed> $job
     */
    private static function describe(array $job): string
    {
        $models = (array) ($job['models'] ?? []);
        $roles  = implode('/', (array) ($job['roles'] ?? []));
        $who    = $models === [] ? '' : ' for ' . implode(', ', array_slice($models, 0, 3)) . (count($models) > 3 ? sprintf(' and %d more', count($models) - 3) : '');

        return trim($roles . $who);
    }
}
