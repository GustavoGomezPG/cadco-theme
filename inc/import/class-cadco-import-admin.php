<?php

/**
 * Products → Import.
 *
 * The screen is deliberately linear: upload, read the report, fix the sheet,
 * repeat until it passes, then review the plan and apply. Because validation
 * is all-or-nothing, most visits end at the report — so the report is the
 * part that gets the space and the detail.
 */

declare(strict_types=1);

final class CADCO_Import_Admin
{
    private const SLUG      = 'cadco-import';
    public const NONCE      = 'cadco_import';
    private const CAPABILITY = 'manage_woocommerce';

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
        add_action('wp_ajax_cadco_import_batch', [self::class, 'ajax_batch']);
        add_action('admin_init', [self::class, 'maybe_export_redirects']);
        add_action('admin_init', [self::class, 'maybe_export_report']);
    }

    public static function menu(): void
    {
        add_submenu_page(
            'edit.php?post_type=product',
            __('Import products', 'cadco-theme'),
            __('Import', 'cadco-theme'),
            self::CAPABILITY,
            self::SLUG,
            [self::class, 'render']
        );
    }

    public static function assets(string $hook): void
    {
        if (!str_contains($hook, self::SLUG)) {
            return;
        }

        wp_enqueue_style(
            'cadco-import-admin',
            get_stylesheet_directory_uri() . '/assets/css/import-admin.css',
            [],
            (string) filemtime(get_stylesheet_directory() . '/assets/css/import-admin.css')
        );

        wp_enqueue_script(
            'cadco-import-admin',
            get_stylesheet_directory_uri() . '/assets/js/import-admin.js',
            [],
            (string) filemtime(get_stylesheet_directory() . '/assets/js/import-admin.js'),
            true
        );

        wp_localize_script('cadco-import-admin', 'cadcoImport', [
            'ajaxUrl'    => admin_url('admin-ajax.php'),
            'nonce'      => wp_create_nonce(self::NONCE),
            'batchSize'  => 25,
            'i18n'       => [
                'failed'           => __('The import failed.', 'cadco-theme'),
                'network'          => __('The import request failed.', 'cadco-theme'),
                /* translators: {applied} is replaced with the number of changes actually applied */
                'done'             => __('Done — {applied} changes applied.', 'cadco-theme'),
                /* translators: {applied}, {total} and {failed} are each replaced with a count */
                'doneWithFailures' => __('Done — {applied} of {total} changes applied. {failed} failed; see the list below.', 'cadco-theme'),
                /* translators: {count} is replaced with the number of failed rows */
                'failuresHeading'  => __('{count} row(s) failed and were not applied:', 'cadco-theme'),
            ],
        ]);
    }

    /**
     * Reader → Normaliser → Validator → Planner.
     *
     * The planner runs only when validation passes, so a failing workbook can
     * never produce a plan that somebody might approve.
     *
     * @return array{report:CADCO_Import_Report,plan:?CADCO_Import_Plan,rows:array,changes:array}
     */
    public static function run_pipeline(string $path): array
    {
        $read       = CADCO_Import_Reader::read($path);
        $normalised = CADCO_Import_Normaliser::normalise($read['rows']);
        $report     = CADCO_Import_Validator::validate($normalised['rows'], $read['errors']);

        $plan = $report->passed()
            ? CADCO_Import_Planner::plan($normalised['rows'], CADCO_Import_Repository::current_products())
            : null;

        return [
            'report'  => $report,
            'plan'    => $plan,
            'rows'    => $normalised['rows'],
            'changes' => $normalised['changes'],
        ];
    }

    public static function render(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You are not allowed to import products.', 'cadco-theme'));
        }

        // The controller resolves the request to exactly one screen state
        // (design spec §4.1) and hands it to the view; it never decides how
        // that state looks. 'tab' just switches between the wizard and the
        // (Task 8) history list — an unrecognised value falls back to the
        // wizard rather than 500ing or rendering nothing.
        $tab = (($_GET['tab'] ?? '') === 'history') ? 'history' : 'import';

        echo '<div class="wrap cadco-import"><h1>' . esc_html__('Import products', 'cadco-theme') . '</h1>';

        CADCO_Import_View::tabs($tab);

        if ($tab === 'history') {
            CADCO_Import_View::history_empty();
            echo '</div>';

            return;
        }

        $result = null;

        // PHP discards $_POST entirely when the body exceeds post_max_size —
        // isset($_POST['cadco_import_upload']) is then simply false and the
        // page would otherwise silently redraw the empty form with no
        // explanation. A POST with an empty $_POST but a non-zero
        // Content-Length is exactly that case.
        if (self::exceeded_post_max_size()) {
            CADCO_Import_View::notice(
                'error',
                sprintf(
                    /* translators: %s: the server's configured upload limit, e.g. "8 MB" */
                    __('That file is larger than this server allows (%s). Ask your host to raise post_max_size, or upload a smaller workbook.', 'cadco-theme'),
                    size_format((int) wp_convert_hr_to_bytes(ini_get('post_max_size')))
                )
            );
        } elseif (isset($_POST['cadco_import_upload'])) {
            check_admin_referer(self::NONCE);
            $result = self::handle_upload();
        }

        CADCO_Import_View::stage_bar(self::screen_state($result), self::stage_bar_context($result));

        if ($result === null) {
            CADCO_Import_View::upload_form();
        } else {
            self::render_result($result);
        }

        echo '</div>';
    }

    /**
     * Which of the wizard's screen states (design spec §4.1) this request
     * resolves to. Only 'upload', 'invalid' and 'review' are possible here —
     * 'applying' and 'done' are states the browser moves through itself, via
     * the AJAX batch loop in assets/js/import-admin.js, after this response
     * has already been sent; the server never renders them.
     */
    private static function screen_state(?array $result): string
    {
        if ($result === null) {
            return 'upload';
        }

        return $result['report']->passed() ? 'review' : 'invalid';
    }

    /**
     * The real figures the stage bar's subtitles report (design spec §5) —
     * never static copy standing in for a count. Empty/zeroed before any
     * workbook has been read, so CADCO_Import_View::stage_bar() falls back to
     * its own "no workbook uploaded yet" / "waiting for a workbook" text
     * rather than printing a filename or count that doesn't exist yet.
     *
     * @return array{filename:string,rows:int,issues:int}
     */
    private static function stage_bar_context(?array $result): array
    {
        if ($result === null) {
            return ['filename' => '', 'rows' => 0, 'issues' => 0];
        }

        return [
            'filename' => (string) ($result['filename'] ?? ''),
            'rows'     => count($result['rows']),
            'issues'   => $result['report']->count(),
        ];
    }

    /**
     * True when this request's body was too large for post_max_size.
     *
     * PHP drops the whole $_POST superglobal in that case rather than
     * reporting an error, so the only signal left is an empty $_POST on a
     * POST request that Content-Length says was not empty.
     */
    private static function exceeded_post_max_size(): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
            && $_POST === []
            && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0;
    }

    /**
     * @return array{report:CADCO_Import_Report,plan:?CADCO_Import_Plan,rows:array,changes:array,filename:string}|null
     */
    private static function handle_upload(): ?array
    {
        if (!isset($_FILES['workbook'])) {
            CADCO_Import_View::notice('error', __('No file was uploaded.', 'cadco-theme'));

            return null;
        }

        $error = (int) ($_FILES['workbook']['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($error !== UPLOAD_ERR_OK) {
            CADCO_Import_View::notice('error', self::upload_error_message($error));

            return null;
        }

        $file  = $_FILES['workbook'];
        $check = wp_check_filetype_and_ext($file['tmp_name'], $file['name'], [
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);

        if (($check['ext'] ?? '') !== 'xlsx') {
            CADCO_Import_View::notice('error', __('That file is not an .xlsx workbook.', 'cadco-theme'));

            return null;
        }

        $archive = CADCO_Import_Archive::create(get_current_user_id());
        $dir     = $archive['dir'];

        $path = $dir . '/workbook.xlsx';

        if (!move_uploaded_file($file['tmp_name'], $path)) {
            CADCO_Import_View::notice('error', __('The uploaded file could not be saved.', 'cadco-theme'));

            return null;
        }

        $result             = self::run_pipeline($path);
        // Kept alongside the pipeline's own output purely for the stage
        // bar's "1. Upload" subtitle (design spec §5) — the original name is
        // untrusted workbook-adjacent input, escaped wherever it is printed
        // (CADCO_Import_View::stage_bar()), never used as a path.
        $result['filename'] = (string) $file['name'];

        // Archive the run: the workbook exactly as uploaded, the report, and
        // the plan it produced. When a later import surprises somebody, this
        // is the only record of what the workbook said at the time.
        file_put_contents($dir . '/report.csv', $result['report']->to_csv());

        if ($result['plan'] instanceof CADCO_Import_Plan) {
            file_put_contents($dir . '/plan.json', (string) wp_json_encode([
                'counts'  => $result['plan']->counts(),
                'creates' => array_map(static fn ($c) => $c['row']['Model #'] ?? '', $result['plan']->creates()),
                'updates' => array_map(static fn ($u) => $u['row']['Model #'] ?? '', $result['plan']->updates()),
                'renames' => array_map(
                    static fn ($r) => ['from' => $r['old_sku'], 'to' => $r['new_sku'], 'upc' => $r['upc']],
                    $result['plan']->renames()
                ),
                'trashes' => array_map(static fn ($t) => $t['sku'], $result['plan']->trashes()),
                'changes' => $result['changes'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        // The manifest is what the history list reads — never the workbook
        // — so it is written unconditionally, pass or fail. `applied` is
        // false until mark_applied() flips it in ajax_batch(), so a run
        // abandoned midway is never offered as a usable restore point.
        CADCO_Import_Archive::write_manifest($dir, [
            'run_id'   => $archive['run_id'],
            'created'  => gmdate('Y-m-d\TH:i:s\Z'),
            'user_id'  => get_current_user_id(),
            'filename' => $file['name'],
            'label'    => '',
            'passed'   => $result['report']->passed(),
            'rows'     => count($result['rows']),
            'issues'   => $result['report']->count(),
            'counts'   => $result['plan'] instanceof CADCO_Import_Plan
                ? $result['plan']->counts()
                : ['create' => 0, 'update' => 0, 'rename' => 0, 'trash' => 0, 'untrash' => 0, 'skip' => 0],
            'applied'  => false,
        ]);

        // Count-based retention replaces the previous 7-day age-based
        // collector — a restore point should not vanish purely because a
        // fortnight passed. Keep the newest 20 runs, delete the rest.
        CADCO_Import_Archive::prune(20);

        // Only the workbook path and archive directory are stored here. The
        // job queue itself — built once apply starts — lives on disk inside
        // this same directory rather than in this transient's value; see
        // ajax_batch() for why.
        set_transient(
            'cadco_import_run_' . get_current_user_id(),
            ['path' => $path, 'dir' => $dir],
            HOUR_IN_SECONDS
        );

        return $result;
    }

    /**
     * A real reason for each PHP upload error code, rather than the generic
     * "No file was uploaded" the brief's reference implementation gave every
     * failure including UPLOAD_ERR_INI_SIZE — which is both wrong (a file
     * *was* uploaded; it was rejected) and unactionable.
     */
    private static function upload_error_message(int $code): string
    {
        switch ($code) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return __('That file is larger than this server allows. Ask your host to raise the upload limit, or upload a smaller workbook.', 'cadco-theme');
            case UPLOAD_ERR_PARTIAL:
                return __('The upload was interrupted partway through. Please try again.', 'cadco-theme');
            case UPLOAD_ERR_NO_FILE:
                return __('No file was uploaded.', 'cadco-theme');
            case UPLOAD_ERR_NO_TMP_DIR:
            case UPLOAD_ERR_CANT_WRITE:
            case UPLOAD_ERR_EXTENSION:
                return __('The server could not accept the upload. Please try again or contact your host.', 'cadco-theme');
            default:
                return __('The upload failed for an unknown reason. Please try again.', 'cadco-theme');
        }
    }

    private static function render_result(array $result): void
    {
        $report = $result['report'];

        if (!$report->passed()) {
            CADCO_Import_View::notice(
                'error',
                sprintf(
                    /* translators: %d: number of problems found */
                    _n('%d problem found. Nothing has been imported.', '%d problems found. Nothing has been imported.', $report->count(), 'cadco-theme'),
                    $report->count()
                )
            );

            CADCO_Import_View::report($report);

            return;
        }

        CADCO_Import_View::notice('success', __('The workbook is clean. Review the plan below before applying it.', 'cadco-theme'));
        CADCO_Import_View::plan($result['plan'], $result['changes'], $result['rows']);
    }

    /**
     * One batch of the apply run.
     *
     * The queue is built ONCE, on the first request, and persisted. It is
     * tempting to re-plan every request from the stored workbook — that is
     * what an earlier draft did, and it is silently wrong: after the first
     * batch has written its products, a fresh plan sees them as unchanged and
     * files them under skips. The queue shrinks, and a fixed offset into a
     * shrinking list steps straight over rows that were never applied.
     *
     * The built queue is persisted to a file inside the run's own archive
     * directory rather than inside the `cadco_import_run_{user}` transient.
     * Measured against the real 236-row workbook the serialised queue runs to
     * ~850KB, and a transient backed by an external object cache (memcached,
     * redis) is typically capped around 1MB per item with no database
     * fallback on a cache miss — a value near that ceiling can silently fail
     * to persist, and get_transient() then has no way to tell "never stored"
     * apart from "stored as an empty result". A plain file has no such limit.
     *
     * Whatever is read back from that file is trusted as-is: if it cannot be
     * read back intact, the batch fails loudly and tells the operator to
     * start again. It never falls back to building a fresh plan, because that
     * reintroduces exactly the shrinking-queue bug described above.
     */
    public static function ajax_batch(): void
    {
        check_ajax_referer(self::NONCE);

        if (!current_user_can(self::CAPABILITY)) {
            wp_send_json_error(['message' => __('Not allowed.', 'cadco-theme')], 403);
        }

        $key    = 'cadco_import_run_' . get_current_user_id();
        $run    = get_transient($key);
        $offset = max(0, (int) ($_POST['offset'] ?? 0));
        $size   = max(1, min(100, (int) ($_POST['size'] ?? 25)));

        if (
            !is_array($run)
            || !isset($run['path'], $run['dir'])
            || !is_string($run['path'])
            || !is_string($run['dir'])
            || !is_readable($run['path'])
        ) {
            wp_send_json_error(['message' => __('The uploaded workbook has expired. Please upload it again.', 'cadco-theme')], 400);
        }

        if ($offset === 0) {
            $result = self::run_pipeline($run['path']);

            if (!$result['report']->passed() || $result['plan'] === null) {
                wp_send_json_error(['message' => __('The workbook no longer validates.', 'cadco-theme')], 400);
            }

            $plan = $result['plan'];

            // Renames are approved by UPC rather than by position. The UPC is
            // unique per rename and stable across requests; an array index is
            // neither if the plan is ever rebuilt in a different order.
            $approved = array_map('strval', (array) ($_POST['approved'] ?? []));

            foreach ($plan->renames() as $i => $rename) {
                if (in_array((string) $rename['upc'], $approved, true)) {
                    $plan->approve_rename((int) $i);
                }
            }

            CADCO_Import_Applier::prepare_terms($result['rows']);

            $queue      = CADCO_Import_Applier::build_queue($plan);
            // Not queue.php.ser: mod_mime matches ANY dot-separated
            // extension, not just the last, so a name ending in .php...ser
            // is handed to the PHP handler on a large class of hosts. The
            // file's contents are raw workbook cell values via serialize();
            // a product name containing "<?php" would become executable at
            // a guessable-if-not-for-CADCO_Import_Archive's-deny-all URL.
            // .bin carries no handler mapping anywhere.
            $queue_file = $run['dir'] . '/queue.bin';

            // build_queue() guarantees plain scalars and nested arrays only —
            // no objects, closures or resources — which is exactly what
            // survives a serialize()/unserialize() round trip intact.
            $written = file_put_contents($queue_file, serialize($queue), LOCK_EX);

            if ($written === false) {
                wp_send_json_error(['message' => __('The import queue could not be saved. Please start again.', 'cadco-theme')], 500);
            }

            $run['queue_file'] = $queue_file;
            set_transient($key, $run, HOUR_IN_SECONDS);
        }

        if (!isset($run['queue_file']) || !is_string($run['queue_file']) || !is_readable($run['queue_file'])) {
            wp_send_json_error(['message' => __('The import run has expired. Please start again.', 'cadco-theme')], 400);
        }

        $raw   = file_get_contents($run['queue_file']);
        $queue = $raw === false ? null : unserialize($raw, ['allowed_classes' => false]);

        // The queue must be read back intact or the batch refuses to run —
        // never re-planned. See the method docblock.
        if (!is_array($queue)) {
            wp_send_json_error(['message' => __('The import run could not be read back. Please start again.', 'cadco-theme')], 500);
        }

        $batch = CADCO_Import_Applier::apply_jobs($queue, $offset, $size);

        if ($batch['complete']) {
            $removed_terms = CADCO_Import_Applier::finalise();

            // Orphan removal is reported, not just carried out silently
            // (design spec §5.5) — appended to the final batch's own log so
            // it travels the same path as every other line the operator
            // already sees for this run.
            foreach ($removed_terms as $removed_term) {
                $batch['log'][] = sprintf(
                    /* translators: %s: term name and taxonomy, e.g. "Widgets (product_cat)" */
                    __('Removed unused term: %s', 'cadco-theme'),
                    $removed_term
                );
            }

            if (is_file($run['queue_file'])) {
                unlink($run['queue_file']);
            }

            // Flip the manifest's `applied` flag now that the run has
            // actually finished, not merely been reviewed — see
            // CADCO_Import_Archive::mark_applied().
            CADCO_Import_Archive::mark_applied($run['dir']);

            delete_transient($key);
        } else {
            // apply_jobs() just cleared this flag so that a batch which
            // touches several terms does not flush once per term. Re-arm it
            // here so this request's own shutdown hook (registered in
            // inc/cadco-woocommerce.php) still flushes the rewrite rules
            // before the response ends. If the operator closes the tab and
            // never sends the next batch, finalise() never runs — without
            // this, the rules stay unflushed and new category permalinks
            // 404 until something unrelated happens to touch a term.
            update_option('cadco_flush_category_rules', true, false);
        }

        wp_send_json_success($batch);
    }

    /**
     * The full legacy-URL redirect map: from path => to URL.
     *
     * Two halves, built two different ways, merged here:
     *
     * - Legacy entries (the old cadco-ltd.com site) are *derived* every call
     *   from CADCO_Import_Repository::legacy_urls() — every published
     *   product's stored _cadco_legacy_url, run through
     *   CADCO_Import_Planner::legacy_path() to reject anything that is not a
     *   product page — rather than accumulated in an option. That is
     *   deliberate, and fixes three things at once: the option this used to
     *   grow in stops growing without bound; the map always reflects
     *   *current* permalinks, so a product recategorised after import
     *   exports the right target instead of a stale one; and a trashed
     *   product drops out automatically instead of leaving a dead entry.
     *
     * - Rename entries are historical events no future workbook run can
     *   reconstruct — a product renamed in one run carries no trace of its
     *   old URL in the next run's workbook — so those stay persisted in the
     *   `cadco_import_redirects` option, written by
     *   CADCO_Import_Applier::record_redirect().
     *
     * Renames win on a key collision: they are the more specific record for
     * that exact path (a real event this system carried out), where a
     * legacy entry is only ever inferred from workbook data. Entries whose
     * `from` equals their `to` are dropped — nothing to redirect. Sorted by
     * `from` so repeated exports of an unchanged map produce byte-identical
     * CSVs.
     *
     * A handful of legacy paths are claimed by two different products (see
     * the plan preview, CADCO_Import_View::legacy_redirect_preview()) — legacy_urls()'s
     * `ORDER BY sku ASC` is what makes the winner for those a stable fact
     * (the alphabetically-last SKU) rather than whatever order MySQL
     * happened to return rows in, so two exports of an unchanged catalogue
     * are guaranteed to agree.
     *
     * @return array<string, string>
     */
    public static function redirect_map(): array
    {
        $map = [];

        foreach (CADCO_Import_Repository::legacy_urls() as $product) {
            $from = CADCO_Import_Planner::legacy_path($product['legacy']);

            if ($from === '') {
                continue;
            }

            $map[$from] = $product['permalink'];
        }

        foreach ((array) get_option('cadco_import_redirects', []) as $from => $to) {
            $map[(string) $from] = (string) $to;
        }

        foreach ($map as $from => $to) {
            if ($from === $to) {
                unset($map[$from]);
            }
        }

        ksort($map);

        return $map;
    }

    /**
     * Download the redirect map as CSV.
     *
     * Renamed products keep their post ID and their page, but their address
     * changes with the model number; every other product's address changed
     * too, from the old site's /product/<slug> to this site's
     * /products/<category>/<child>/<slug>/. This map pairs each old path
     * with the new URL so the redirects can be loaded into Yoast or the
     * server config — the importer deliberately does not create them itself,
     * because redirect handling is the SEO plugin's job on this site.
     */
    public static function maybe_export_redirects(): void
    {
        if (($_GET['page'] ?? '') !== self::SLUG || ($_GET['action'] ?? '') !== 'export-redirects') {
            return;
        }

        check_admin_referer(self::NONCE);

        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You are not allowed to do that.', 'cadco-theme'));
        }

        $map    = self::redirect_map();
        $handle = fopen('php://output', 'w');

        if ($handle === false) {
            wp_die(esc_html__('The redirect map could not be generated.', 'cadco-theme'));
        }

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=cadco-redirects.csv');

        fputcsv($handle, array_map([CADCO_Import_Report::class, 'csv_safe'], ['Old path', 'New URL']), ',', '"', '');

        foreach ($map as $from => $to) {
            fputcsv($handle, array_map([CADCO_Import_Report::class, 'csv_safe'], [(string) $from, (string) $to]), ',', '"', '');
        }

        fclose($handle);
        exit;
    }

    /**
     * Download the validation report as CSV.
     *
     * The report is the primary deliverable of a failing run (design spec
     * §8.1 step 2) — CADCO_Import_Archive denies web access to the archived
     * copy on disk, so without this handler the only way to see a problem
     * row is to read the on-screen table, which nobody can hand to CADCO or
     * grep. The workbook is re-validated from the archived upload rather
     * than re-reading the archived report.csv directly, so this always
     * reflects the same pipeline the operator's screen came from.
     */
    public static function maybe_export_report(): void
    {
        if (($_GET['page'] ?? '') !== self::SLUG || ($_GET['action'] ?? '') !== 'export-report') {
            return;
        }

        check_admin_referer(self::NONCE);

        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You are not allowed to do that.', 'cadco-theme'));
        }

        $run = get_transient('cadco_import_run_' . get_current_user_id());

        if (!is_array($run) || !isset($run['path']) || !is_string($run['path']) || !is_readable($run['path'])) {
            wp_die(esc_html__('The uploaded workbook has expired. Please upload it again.', 'cadco-theme'));
        }

        $report = self::run_pipeline($run['path'])['report'];

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=cadco-import-report.csv');

        echo $report->to_csv();
        exit;
    }
}
