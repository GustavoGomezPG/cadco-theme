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

    /**
     * Set by maybe_handle_restore() (admin_init, fix round 1 finding 2)
     * when a restore request fails validation. A successful restore
     * redirects and exits during admin_init, before this page's own chrome
     * (the "wrap" div, the tab nav) has sent a single byte of output — but
     * a FAILED one has to show its notice inside that chrome, at the same
     * point handle_upload()'s own error notices appear, which does not
     * exist yet during admin_init. This static property is how the message
     * travels from admin_init to render(), later in the very same request.
     */
    private static ?string $restore_error = null;

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
        add_action('wp_ajax_cadco_import_batch', [self::class, 'ajax_batch']);
        add_action('wp_ajax_cadco_import_label', [self::class, 'ajax_label']);
        add_action('admin_init', [self::class, 'maybe_export_redirects']);
        add_action('admin_init', [self::class, 'maybe_export_report']);
        add_action('admin_init', [self::class, 'maybe_export_archived_report']);
        add_action('admin_init', [self::class, 'maybe_handle_restore']);
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

        // Barlow Condensed over Barlow is the "Industry" design system's type
        // pairing (assets/css/import-admin.css). The theme already loads Barlow
        // from Google Fonts for the front end (functions.php), so this follows
        // the established pattern rather than introducing a second one; the
        // condensed face is the part that is new, and it is loaded only on this
        // screen. The stylesheet's own font stacks fall back to Barlow and then
        // the system UI face, so the screen degrades legibly if the request to
        // Google is blocked.
        wp_enqueue_style(
            'cadco-import-admin-fonts',
            'https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@400;500;600;700&family=Barlow:wght@400;500;600&display=swap',
            [],
            null
        );

        wp_enqueue_style(
            'cadco-import-admin',
            get_stylesheet_directory_uri() . '/assets/css/import-admin.css',
            ['cadco-import-admin-fonts'],
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
                'labelSaved'       => __('Saved.', 'cadco-theme'),
                'labelFailed'      => __('Could not save the label.', 'cadco-theme'),
            ],
        ]);
    }

    /**
     * Reader → Normaliser → Validator → Planner.
     *
     * The planner runs only when validation passes, so a failing workbook can
     * never produce a plan that somebody might approve.
     *
     * $trashed is [] for every ordinary import — a product deliberately
     * removed must stay removed. It is populated only when this pipeline is
     * run on behalf of a restore (handle_restore(), and ajax_batch() re-
     * running the same pipeline at apply time for a run the transient has
     * flagged as one), from CADCO_Import_Repository::trashed_products(). The
     * asymmetry is deliberate and lives here, at the one place both the
     * normal and restore paths funnel through, rather than inside the
     * planner itself (design spec §8.4).
     *
     * @param list<array{post_id:int,sku:string,upc:string,hash:string,snapshot?:array<string,string>}> $trashed
     * @return array{report:CADCO_Import_Report,plan:?CADCO_Import_Plan,rows:array,changes:array}
     */
    public static function run_pipeline(string $path, array $trashed = []): array
    {
        $read       = CADCO_Import_Reader::read($path);
        $normalised = CADCO_Import_Normaliser::normalise($read['rows']);
        $report     = CADCO_Import_Validator::validate($normalised['rows'], $read['errors']);

        $plan = $report->passed()
            ? CADCO_Import_Planner::plan($normalised['rows'], CADCO_Import_Repository::current_products(), $trashed)
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

        // Title on the left, tab strip pinned right, on one row (design file's
        // header). The <hr class="wp-header-end"> below is load-bearing, not
        // decoration: wp-admin/js/common.js relocates every .notice to just
        // after that marker if it exists, and otherwise to just after the first
        // <h1> inside .wrap. Without it, nesting the <h1> in this flex row
        // would drop every admin notice *inside* the row and wreck it — the
        // same class of failure the restore banner's `inline` class avoids.
        echo '<div class="wrap cadco-import">';
        echo '<div class="cadco-import-header">';
        echo '<div class="cadco-import-titles">';
        echo '<p class="cadco-import-kicker">' . esc_html__('Catalogue tools', 'cadco-theme') . '</p>';
        echo '<h1 class="cadco-import-title">' . esc_html__('Import products', 'cadco-theme') . '</h1>';
        echo '</div>';

        CADCO_Import_View::tabs($tab);

        echo '</div>';
        echo '<hr class="wp-header-end">';

        if ($tab === 'history') {
            // Read manifests only (task brief) — the history list never
            // opens a workbook, a report or a plan, so it stays fast no
            // matter how many runs are retained. CADCO_Import_Archive::all()
            // already degrades a missing/corrupt manifest to "not listed"
            // rather than fataling the whole screen.
            $runs = CADCO_Import_Archive::all();

            if ($runs === []) {
                CADCO_Import_View::history_empty();
            } else {
                CADCO_Import_View::history($runs);
            }

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
        } elseif (self::$restore_error !== null) {
            // maybe_handle_restore() (admin_init, fix round 1 finding 2)
            // already checked the nonce, the capability and the run id
            // before this page's own chrome had sent a single byte of
            // output — a successful restore redirected and exited there,
            // long before render() was even called. This only ever shows
            // the notice a FAILED one left behind; $result stays null, same
            // as any other failed request on this screen, so the upload
            // form renders underneath it.
            CADCO_Import_View::notice('error', self::$restore_error);
        } elseif (isset($_GET['restored_run'])) {
            // Where a successful restore lands (fix round 1, finding 2): a
            // read-only re-render of a run handle_restore() already
            // archived. Reloading this URL is always safe — it re-derives
            // the same Review from what is already on disk, and never
            // creates another run or prunes one out from under a restore
            // still in flight. No nonce is required, same as any other
            // read-only admin view gated only by current_user_can() above —
            // nothing here writes anything.
            $result = self::handle_restored_review(self::request_string($_GET['restored_run']));
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
     * A request value as a string, or '' for anything that isn't one (fix
     * round 1, finding 7). A run id normally arrives as a plain scalar, but
     * nothing stops a request from sending `run_id[]=x`, which PHP decodes
     * to an array — casting that with `(string)` doesn't throw, but with
     * `display_errors` on it emits an "Array to string conversion" warning
     * that lands in the response body ahead of JSON a client then fails to
     * parse. Every one of this class's run-id/label entry points reads
     * through here first, so the value handed to is_valid_run_id() (which
     * would reject "Array" anyway) is instead never allowed to become the
     * literal string "Array" that warning implies — it becomes '', which
     * every one of these handlers already treats as invalid input, and
     * `is_string()` never warns on anything it is handed.
     */
    private static function request_string(mixed $value): string
    {
        return is_string($value) ? $value : '';
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
        // The Workbook review section (design spec §6.2) reports the file's
        // real size. Read from the just-written copy on disk rather than
        // $file['size'] — both should agree, but the archived file is what
        // was actually parsed, so it is the more honest source.
        $size                = filesize($path);
        $result['size']      = $size !== false ? $size : 0;

        return self::archive_and_track($archive, $result, (string) $file['name'], false);
    }

    /**
     * The admin_init entry point for a restore request (fix round 1,
     * finding 2). Everything that turns the browser-supplied run id into a
     * path, or writes anything, happens inside handle_restore() — this is
     * just the same page/action match plus nonce/capability gate every
     * other admin_init handler in this file (maybe_export_report() and its
     * two siblings) already uses, run here specifically because a
     * successful restore needs to wp_safe_redirect(), and headers can only
     * be sent before this page's own chrome (the "wrap" div, the tab nav)
     * has echoed anything — which has already happened by the time
     * render() (the page callback) runs. admin_init fires well before that.
     */
    public static function maybe_handle_restore(): void
    {
        if (
            ($_GET['page'] ?? '') !== self::SLUG
            || ($_GET['action'] ?? '') !== 'restore'
            || !isset($_GET['run_id'])
        ) {
            return;
        }

        check_admin_referer(self::NONCE);

        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You are not allowed to do that.', 'cadco-theme'));
        }

        self::handle_restore(self::request_string($_GET['run_id']));
    }

    /**
     * Restore (design spec §8.3, task brief step 3): load a previously
     * archived workbook back into the wizard at Review, as an ordinary
     * import from there — re-validated, re-planned against the catalogue as
     * it is now, with the full diff on screen before anything is written.
     * This is deliberately NOT a replay of the archived plan.json: that plan
     * references post IDs that may no longer exist, and replaying it could
     * trash products legitimately added since the run being restored.
     *
     * $run_id arrives from the browser (a History-tab link) — the security
     * boundary CADCO_Import_Archive::is_valid_run_id() exists for. Validated
     * here, first, before it is used to build any path; maybe_handle_restore()
     * has already checked the nonce and the capability before this is ever
     * called.
     *
     * The archived workbook is copied into a brand new run directory and
     * run through exactly the same archive-and-track path a fresh upload
     * takes (self::archive_and_track()) — a restore is a real, new import
     * run in its own right, and gets its own history entry; the run being
     * restored is left completely untouched, so its own `created`/`applied`
     * facts keep describing what actually happened *then*, not this restore.
     *
     * The one difference from a plain upload: the pipeline is run with
     * CADCO_Import_Repository::trashed_products() as its third argument, so
     * the planner can offer an untrash for a product this run's workbook
     * still lists but the current catalogue has trashed since (design spec
     * §8.4). A normal import never does this — see run_pipeline()'s
     * docblock.
     *
     * Fix round 1, finding 2: this used to render Review directly, in the
     * same GET request that archived the new run — which made restore a
     * side-effecting GET. WP nonces are not single-use, so a reload (F5)
     * replayed the whole thing: another run directory, another workbook
     * copy, another manifest, another prune(). On success this now
     * `wp_safe_redirect()`s instead, onto a URL
     * (`?restored_run=<the new run's own id>`) that only ever *reads* what
     * was just archived — see handle_restored_review(). A reload of that
     * URL cannot create anything. This method never returns a value: on
     * failure it records the reason in self::$restore_error for render()
     * to show later in the same request (it must not echo anything itself
     * — called from admin_init, before this page's own chrome exists to
     * echo a notice into); on success it redirects and exits.
     *
     * Fix round 1, finding 2's other half: at retention's cap (20 runs),
     * archiving this restore's own new run could otherwise prune the very
     * run being restored out from under it in the same request — the
     * archived copy the operator is about to review would still exist, but
     * the source they restored *from* would silently vanish. $run_id is
     * passed to archive_and_track() as the run prune() must not delete this
     * one time, no matter where it falls relative to the newest 20.
     */
    private static function handle_restore(string $run_id): void
    {
        if (!CADCO_Import_Archive::is_valid_run_id($run_id)) {
            self::$restore_error = __('That import run could not be found.', 'cadco-theme');

            return;
        }

        $source_manifest = CADCO_Import_Archive::get($run_id);

        if ($source_manifest === null) {
            self::$restore_error = __('That import run could not be found.', 'cadco-theme');

            return;
        }

        $source_dir  = self::archive_dir($run_id);
        $source_path = $source_dir !== null ? $source_dir . '/workbook.xlsx' : '';

        // Fix round 1, finding 3: every other file read this class
        // introduced checks is_link() before is_readable() — this one
        // didn't. Exploiting it needs write access to uploads/ already, but
        // this is the one read that skipped the same guard the class's own
        // other new methods (archive_dir(), maybe_export_archived_report())
        // and CADCO_Import_Archive itself all apply consistently.
        if ($source_dir === null || is_link($source_path) || !is_readable($source_path)) {
            self::$restore_error = __('That run\'s workbook is no longer available to restore.', 'cadco-theme');

            return;
        }

        $archive = CADCO_Import_Archive::create(get_current_user_id());
        $dir     = $archive['dir'];
        $path    = $dir . '/workbook.xlsx';

        if (!copy($source_path, $path)) {
            self::$restore_error = __('The archived workbook could not be copied.', 'cadco-theme');

            return;
        }

        $filename = (string) ($source_manifest['filename'] ?? basename($path));

        $result              = self::run_pipeline($path, CADCO_Import_Repository::trashed_products());
        $result['filename']  = $filename;
        $size                = filesize($path);
        $result['size']      = $size !== false ? $size : 0;

        // $run_id (the source being restored) records this run's lineage
        // (fix round 1, finding 4) — surfaced by the History table and read
        // back by handle_restored_review() to build the restore banner —
        // and is also the one run archive_and_track()'s prune() call must
        // spare this time, so archiving this restore can never delete the
        // very run it restores from (fix round 1, finding 2).
        self::archive_and_track($archive, $result, $filename, true, $run_id);

        wp_safe_redirect(admin_url(
            'edit.php?post_type=product&page=cadco-import&restored_run=' . rawurlencode($archive['run_id'])
        ));
        exit;
    }

    /**
     * The read-only landing page handle_restore() redirects to on success
     * (fix round 1, finding 2). $run_id here is always the NEW run
     * handle_restore() just archived, never the source — everything this
     * method does is a read: re-run the pipeline against that new run's own
     * already-copied workbook.xlsx purely to rebuild the plan for display,
     * then read the ORIGINAL run's manifest (via this run's own
     * `restored_from`, fix round 1 finding 4) to build the restore banner.
     * Nothing here calls CADCO_Import_Archive::create(), write_manifest()
     * or prune() — a reload of this URL can only ever show the same Review
     * again, never create another run or prune one out from under a
     * restore still in flight.
     *
     * $run_id still arrives from the browser (the query string, however it
     * got there) and is still validated with is_valid_run_id() before it
     * touches anything — "we built the redirect ourselves" is not a reason
     * to skip the check a user could otherwise bypass by typing their own
     * URL.
     *
     * Only a run carrying `restored_from` is accepted: this endpoint's one
     * job is showing what a restore produced, not becoming a general
     * "replay any past Review" feature for an arbitrary run id.
     *
     * @return array{report:CADCO_Import_Report,plan:?CADCO_Import_Plan,rows:array,changes:array,filename:string,size:int,restore:array{run_id:string,label:string,filename:string,created:string}}|null
     */
    private static function handle_restored_review(string $run_id): ?array
    {
        if (!CADCO_Import_Archive::is_valid_run_id($run_id)) {
            CADCO_Import_View::notice('error', __('That import run could not be found.', 'cadco-theme'));

            return null;
        }

        $manifest = CADCO_Import_Archive::get($run_id);

        if ($manifest === null || empty($manifest['restored_from']) || !is_string($manifest['restored_from'])) {
            CADCO_Import_View::notice('error', __('That import run could not be found.', 'cadco-theme'));

            return null;
        }

        $dir  = self::archive_dir($run_id);
        $path = $dir !== null ? $dir . '/workbook.xlsx' : '';

        if ($dir === null || is_link($path) || !is_readable($path)) {
            CADCO_Import_View::notice('error', __('That run\'s workbook is no longer available.', 'cadco-theme'));

            return null;
        }

        // This run was itself only ever created by handle_restore(), which
        // always plans with trashed candidates — redisplaying it must use
        // the same mode, or an untrash Review originally showed would
        // silently redisplay as a create.
        $result              = self::run_pipeline($path, CADCO_Import_Repository::trashed_products());
        $filename            = (string) ($manifest['filename'] ?? basename($path));
        $result['filename']  = $filename;
        $size                = filesize($path);
        $result['size']      = $size !== false ? $size : 0;

        // The banner names the ORIGINAL run — read from ITS manifest, not
        // this one. Degrades gracefully (rather than fatal or a broken
        // banner) if the source has since been pruned: falls back to this
        // run's own filename with an empty label/date, still honestly
        // marked as a restore, just of a run that no longer has its own
        // record to show.
        $source_run_id = (string) $manifest['restored_from'];
        $origin        = CADCO_Import_Archive::get($source_run_id);

        $result['restore'] = [
            'run_id'   => $source_run_id,
            'label'    => $origin !== null ? (string) ($origin['label'] ?? '') : '',
            'filename' => $origin !== null ? (string) ($origin['filename'] ?? '') : $filename,
            'created'  => $origin !== null ? (string) ($origin['created'] ?? '') : '',
        ];

        return $result;
    }

    /**
     * The shared tail of both handle_upload() and handle_restore(): archive
     * the run's report and plan, write its manifest (`applied: false` until
     * mark_applied() flips it in ajax_batch()), prune old runs, and record
     * the run in the per-user transient ajax_batch() reads at apply time.
     *
     * $is_restore is carried into the transient so ajax_batch() knows, at
     * offset 0, to re-run the pipeline with trashed candidates too — the
     * plan actually applied must match the plan Review showed, or an
     * operator approving "1 untrash" on screen would silently get a create
     * instead once the batch loop rebuilds the plan for itself.
     *
     * $restored_from (fix round 1, findings 2 and 4) is the run id being
     * restored, non-null only when $is_restore is true. It is written into
     * this new run's own manifest as `restored_from` — its only consumer is
     * handle_restored_review() and CADCO_Import_View::history(), so the
     * lineage between a restore and its source survives even after the
     * source itself is eventually pruned. It is also passed straight
     * through to CADCO_Import_Archive::prune() as the one run this
     * request's pruning must never delete: archiving a restore must not be
     * able to prune the very run it restores from out from under itself.
     *
     * @param array{run_id:string,dir:string} $archive
     * @param array{report:CADCO_Import_Report,plan:?CADCO_Import_Plan,rows:array,changes:array,filename:string,size:int} $result
     * @return array{report:CADCO_Import_Report,plan:?CADCO_Import_Plan,rows:array,changes:array,filename:string,size:int}
     */
    private static function archive_and_track(
        array $archive,
        array $result,
        string $filename,
        bool $is_restore,
        ?string $restored_from = null
    ): array {
        $dir  = $archive['dir'];
        $path = $dir . '/workbook.xlsx';

        // Archive the run: the workbook exactly as uploaded (or restored),
        // the report, and the plan it produced. When a later import
        // surprises somebody, this is the only record of what the workbook
        // said at the time.
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
            'filename' => $filename,
            'label'    => '',
            'passed'   => $result['report']->passed(),
            'rows'     => count($result['rows']),
            'issues'   => $result['report']->count(),
            'counts'   => $result['plan'] instanceof CADCO_Import_Plan
                ? $result['plan']->counts()
                : ['create' => 0, 'update' => 0, 'rename' => 0, 'trash' => 0, 'untrash' => 0, 'skip' => 0],
            'applied'       => false,
            'restored_from' => $restored_from,
        ]);

        // Count-based retention replaces the previous 7-day age-based
        // collector — a restore point should not vanish purely because a
        // fortnight passed. Keep the newest 20 runs, delete the rest — but
        // never the run this archiving is itself a restore of (fix round 1,
        // finding 2): at exactly 20 existing runs, archiving a restore of
        // the oldest one would otherwise prune that same run in this same
        // request, deleting the source out from under the restore that
        // just copied it.
        CADCO_Import_Archive::prune(20, $restored_from);

        // Only the workbook path and archive directory are stored here. The
        // job queue itself — built once apply starts — lives on disk inside
        // this same directory rather than in this transient's value; see
        // ajax_batch() for why. `restore` records whether this run's apply
        // must re-supply trashed candidates to the planner (see this
        // method's own docblock).
        set_transient(
            'cadco_import_run_' . get_current_user_id(),
            ['path' => $path, 'dir' => $dir, 'restore' => $is_restore],
            HOUR_IN_SECONDS
        );

        return $result;
    }

    /**
     * The directory a validated run id names, or null for anything that
     * doesn't check out.
     *
     * CADCO_Import_Archive::base_dir() is private, so this mirrors its exact
     * formula (wp_upload_dir()'s basedir + 'cadco-imports' + the run id) —
     * the same duplication tests/e2e/helpers.js's cleanupUploadRuns()
     * already carries for the same reason. is_valid_run_id() runs first,
     * always, before the id is used to build anything — never a looser
     * check, never after the path already exists — matching the exact
     * discipline CADCO_Import_Archive::get()/set_label() themselves use.
     */
    private static function archive_dir(string $run_id): ?string
    {
        if (!CADCO_Import_Archive::is_valid_run_id($run_id)) {
            return null;
        }

        $dir = trailingslashit(wp_upload_dir()['basedir']) . 'cadco-imports/' . $run_id;

        if (is_link($dir) || !is_dir($dir)) {
            return null;
        }

        return $dir;
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

        // Everything the review screen needs beyond the plan itself (design
        // spec §6) is gathered here, in the controller, rather than in the
        // view — get_terms() and the workbook byte count are database/file
        // reads, not rendering decisions.
        $workbook = [
            'filename' => (string) ($result['filename'] ?? ''),
            'size'     => (int) ($result['size'] ?? 0),
            'rows'     => count($result['rows']),
            'issues'   => $report->count(),
            'sheets'   => self::sheet_breakdown($result['rows']),
        ];

        $term_diff    = CADCO_Import_Term_Diff::compare($result['rows'], self::existing_terms());
        $redirect_map = self::redirect_map();

        CADCO_Import_View::review(
            $result['plan'],
            $result['changes'],
            $result['rows'],
            $term_diff,
            $workbook,
            $redirect_map,
            $result['restore'] ?? null
        );
    }

    /**
     * Rows read per canonical sheet (design spec §6.2's Workbook section),
     * derived from the rows the Reader already tagged with `__sheet` rather
     * than re-reading the workbook. Every canonical sheet is listed even if
     * it happened to contribute zero body rows — Review is only reached once
     * validation passed, and a missing sheet is itself a Tier A error, so
     * every one of cadco_import_sheets() is guaranteed present here.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array{name:string, rows:int}>
     */
    private static function sheet_breakdown(array $rows): array
    {
        $counts = [];

        foreach ($rows as $row) {
            $sheet = (string) ($row['__sheet'] ?? '');
            $counts[$sheet] = ($counts[$sheet] ?? 0) + 1;
        }

        $sheets = [];

        foreach (cadco_import_sheets() as $name) {
            $sheets[] = ['name' => $name, 'rows' => $counts[$name] ?? 0];
        }

        return $sheets;
    }

    /**
     * The site's current product_cat/product_tag/product_brand terms, shaped
     * for CADCO_Import_Term_Diff::compare() (design spec §7). Real terms via
     * get_terms(), not derived from any plan — this is the "what the site
     * already has" half of the diff, and has to come from the database.
     *
     * @return list<array{taxonomy:string, term_id:int, name:string, parent:int, count:int}>
     */
    private static function existing_terms(): array
    {
        $existing = [];

        foreach (['product_cat', 'product_tag', 'product_brand'] as $taxonomy) {
            $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);

            if (is_wp_error($terms)) {
                continue;
            }

            foreach ($terms as $term) {
                $existing[] = [
                    'taxonomy' => $taxonomy,
                    'term_id'  => (int) $term->term_id,
                    'name'     => (string) $term->name,
                    'parent'   => (int) $term->parent,
                    'count'    => (int) $term->count,
                ];
            }
        }

        return $existing;
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
            // A run the transient marked as a restore (set in
            // archive_and_track(), called from handle_restore()) must plan
            // with the same trashed candidates Review showed, or an
            // operator approving "1 untrash" on screen would silently get a
            // create instead once this offset-0 request rebuilds the plan
            // for itself — see run_pipeline()'s docblock for why a normal
            // import must never do this.
            $trashed = !empty($run['restore']) ? CADCO_Import_Repository::trashed_products() : [];
            $result  = self::run_pipeline($run['path'], $trashed);

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

    /**
     * Download a past run's report.csv from the archive (History tab's
     * "View report" action for a failed run — task brief step 1, design
     * spec §8.1: "A failed run has no plan to restore, so it offers only its
     * report.").
     *
     * $run_id arrives from the browser, so the same discipline as every
     * other handler that turns one into a path applies: nonce, then
     * capability, then archive_dir()'s is_valid_run_id() check, all before
     * the filesystem is touched. Reads the report.csv archived at upload
     * time directly (already exactly what that run's screen showed) rather
     * than re-running the pipeline — unlike maybe_export_report() above,
     * there is no "current" transient to re-validate from; this run may not
     * even be the one the operator has open right now.
     */
    public static function maybe_export_archived_report(): void
    {
        if (($_GET['page'] ?? '') !== self::SLUG || ($_GET['action'] ?? '') !== 'export-archived-report') {
            return;
        }

        check_admin_referer(self::NONCE);

        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You are not allowed to do that.', 'cadco-theme'));
        }

        $dir = self::archive_dir(self::request_string($_GET['run_id'] ?? null));

        if ($dir === null) {
            wp_die(esc_html__('That import run could not be found.', 'cadco-theme'));
        }

        $path = $dir . '/report.csv';

        if (is_link($path) || !is_readable($path)) {
            wp_die(esc_html__('That run\'s report is no longer available.', 'cadco-theme'));
        }

        $csv = file_get_contents($path);

        if ($csv === false) {
            wp_die(esc_html__('That run\'s report could not be read.', 'cadco-theme'));
        }

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=cadco-import-report.csv');

        echo $csv;
        exit;
    }

    /**
     * Inline label editing (task brief step 2): an AJAX endpoint, nonce- and
     * capability-checked, that calls CADCO_Import_Archive::set_label().
     *
     * $run_id arrives from the browser here too — validated with
     * is_valid_run_id() before it is used for anything, same as every other
     * entry point a run id reaches (restore above, CADCO_Import_Archive's
     * own get()/set_label()). set_label() re-validates it again internally;
     * that redundancy is intentional defence in depth, not a substitute for
     * checking here first.
     *
     * The label itself is operator-typed, untrusted input: sanitized on the
     * way in (sanitize_text_field() strips tags/extra whitespace; length is
     * capped so one run cannot grow its manifest without bound) and, per the
     * brief, escaped again on the way out wherever CADCO_Import_View prints
     * it — sanitizing on write is not a substitute for escaping on output.
     */
    public static function ajax_label(): void
    {
        check_ajax_referer(self::NONCE);

        if (!current_user_can(self::CAPABILITY)) {
            wp_send_json_error(['message' => __('Not allowed.', 'cadco-theme')], 403);
        }

        $run_id = self::request_string($_POST['run_id'] ?? null);

        if (!CADCO_Import_Archive::is_valid_run_id($run_id)) {
            wp_send_json_error(['message' => __('That import run could not be found.', 'cadco-theme')], 400);
        }

        $label = mb_substr(
            sanitize_text_field(wp_unslash(self::request_string($_POST['label'] ?? null))),
            0,
            190
        );

        if (!CADCO_Import_Archive::set_label($run_id, $label)) {
            wp_send_json_error(['message' => __('That import run could not be found.', 'cadco-theme')], 404);
        }

        wp_send_json_success(['label' => $label]);
    }
}
