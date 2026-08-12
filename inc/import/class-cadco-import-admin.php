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
    private const NONCE     = 'cadco_import';
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

        echo '<div class="wrap cadco-import"><h1>' . esc_html__('Import products', 'cadco-theme') . '</h1>';

        $result = null;

        // PHP discards $_POST entirely when the body exceeds post_max_size —
        // isset($_POST['cadco_import_upload']) is then simply false and the
        // page would otherwise silently redraw the empty form with no
        // explanation. A POST with an empty $_POST but a non-zero
        // Content-Length is exactly that case.
        if (self::exceeded_post_max_size()) {
            self::notice(
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

        if ($result === null) {
            self::render_form();
        } else {
            self::render_result($result);
        }

        echo '</div>';
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

    private static function render_form(): void
    {
        ?>
        <p class="description">
            <?php esc_html_e('Upload the product workbook. It is checked before anything is changed — if any problem is found, nothing at all is imported.', 'cadco-theme'); ?>
        </p>
        <form method="post" enctype="multipart/form-data">
            <?php wp_nonce_field(self::NONCE); ?>
            <input type="file" name="workbook" accept=".xlsx" required>
            <?php submit_button(__('Check workbook', 'cadco-theme'), 'primary', 'cadco_import_upload'); ?>
        </form>
        <?php
    }

    /**
     * @return array{report:CADCO_Import_Report,plan:?CADCO_Import_Plan,rows:array,changes:array}|null
     */
    private static function handle_upload(): ?array
    {
        if (!isset($_FILES['workbook'])) {
            self::notice('error', __('No file was uploaded.', 'cadco-theme'));

            return null;
        }

        $error = (int) ($_FILES['workbook']['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($error !== UPLOAD_ERR_OK) {
            self::notice('error', self::upload_error_message($error));

            return null;
        }

        $file  = $_FILES['workbook'];
        $check = wp_check_filetype_and_ext($file['tmp_name'], $file['name'], [
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);

        if (($check['ext'] ?? '') !== 'xlsx') {
            self::notice('error', __('That file is not an .xlsx workbook.', 'cadco-theme'));

            return null;
        }

        $base_dir = trailingslashit(wp_upload_dir()['basedir']) . 'cadco-imports';

        self::guard_imports_dir($base_dir);
        self::garbage_collect_runs($base_dir);

        // The timestamp alone is guessable; the user ID plus a random
        // component makes the directory unguessable (it sits behind
        // guard_imports_dir()'s deny-all anyway, but this is cheap and
        // removes the timestamp collision between two operators uploading
        // in the same second as a side effect).
        $dir = $base_dir . '/' . gmdate('Y-m-d-His') . '-' . get_current_user_id() . '-' . wp_generate_password(12, false);
        wp_mkdir_p($dir);

        $path = $dir . '/workbook.xlsx';

        if (!move_uploaded_file($file['tmp_name'], $path)) {
            self::notice('error', __('The uploaded file could not be saved.', 'cadco-theme'));

            return null;
        }

        $result = self::run_pipeline($path);

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

    /**
     * Make sure the archive directory cannot be browsed or executed.
     *
     * Every run's workbook, report, plan and (temporarily) job queue live
     * under wp-content/uploads/cadco-imports/ — real catalogue data (SKUs,
     * UPCs, supplier descriptions, dimensions). Nothing under uploads/ on
     * this install carries an .htaccess of its own, so without this the
     * whole run archive is one guessable directory name away from being
     * readable — or, with autoindex on, listable — by anyone.
     *
     * Idempotent and cheap: called on every upload, so a guard file removed
     * by hand is simply rewritten on the next run rather than staying gone.
     */
    private static function guard_imports_dir(string $base_dir): void
    {
        wp_mkdir_p($base_dir);

        $index = $base_dir . '/index.php';

        if (!file_exists($index)) {
            file_put_contents($index, "<?php\n// Silence is golden.\n");
        }

        $htaccess = $base_dir . '/.htaccess';

        if (!file_exists($htaccess)) {
            file_put_contents($htaccess, "Require all denied\n");
        }
    }

    /**
     * Delete run directories older than a week.
     *
     * Every upload — including ones that fail validation — leaves a
     * permanent copy of the workbook plus its report and plan, and an
     * abandoned apply run leaves the (potentially large) queue file behind
     * too, since unlink() for that file only runs when a run completes.
     * Nothing else ever cleaned these up.
     *
     * Deliberately conservative: only entries whose name is exactly the
     * shape this class creates (a timestamp, a user ID, and the random
     * suffix from guard's sibling upload code) are even considered, and only
     * plain files one level inside such a directory are ever unlinked — an
     * unexpected subdirectory, a symlink, or a name that merely looks close
     * is left alone rather than recursed into or followed. This never
     * touches anything outside $base_dir.
     */
    private static function garbage_collect_runs(string $base_dir): void
    {
        if (!is_dir($base_dir)) {
            return;
        }

        $entries = scandir($base_dir);

        if ($entries === false) {
            return;
        }

        $cutoff = time() - 7 * DAY_IN_SECONDS;

        foreach ($entries as $entry) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}-\d{6}-\d+-[A-Za-z0-9]{12}$/', $entry)) {
                continue;
            }

            $path = $base_dir . '/' . $entry;

            if (is_link($path) || !is_dir($path)) {
                continue;
            }

            $mtime = filemtime($path);

            if ($mtime === false || $mtime > $cutoff) {
                continue;
            }

            $files = scandir($path);

            if ($files === false) {
                continue;
            }

            foreach ($files as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }

                $file_path = $path . '/' . $file;

                if (is_file($file_path) && !is_link($file_path)) {
                    unlink($file_path);
                }
            }

            // Fails harmlessly if an unexpected entry kept the directory
            // from being empty — that entry was left alone above, on
            // purpose, rather than deleted.
            @rmdir($path);
        }
    }

    private static function render_result(array $result): void
    {
        $report = $result['report'];

        if (!$report->passed()) {
            self::notice(
                'error',
                sprintf(
                    /* translators: %d: number of problems found */
                    _n('%d problem found. Nothing has been imported.', '%d problems found. Nothing has been imported.', $report->count(), 'cadco-theme'),
                    $report->count()
                )
            );

            self::render_report($report);

            return;
        }

        self::notice('success', __('The workbook is clean. Review the plan below before applying it.', 'cadco-theme'));
        self::render_plan($result['plan'], $result['changes']);
    }

    private static function render_report(CADCO_Import_Report $report): void
    {
        // The report is the system's primary deliverable (design spec §8.1) —
        // it must leave the browser as a file CADCO can hand to whoever
        // maintains the workbook, not just sit on screen.
        printf(
            '<p><a class="button" href="%s">%s</a></p>',
            esc_url(wp_nonce_url(
                admin_url('edit.php?post_type=product&page=cadco-import&action=export-report'),
                self::NONCE
            )),
            esc_html__('Download report (CSV)', 'cadco-theme')
        );

        $labels = [
            'A' => __('Identity — duplicate, missing or malformed product identifiers', 'cadco-theme'),
            'B' => __('Consistency — the same value spelled several ways', 'cadco-theme'),
            'C' => __('Completeness — blank fields that must state a value', 'cadco-theme'),
        ];

        foreach ($report->by_tier() as $tier => $issues) {
            printf('<h2>%s <span class="count">%d</span></h2>', esc_html($labels[$tier] ?? $tier), count($issues));

            echo '<table class="widefat striped"><thead><tr>';
            echo '<th>' . esc_html__('Sheet', 'cadco-theme') . '</th>';
            echo '<th>' . esc_html__('Row', 'cadco-theme') . '</th>';
            echo '<th>' . esc_html__('Column', 'cadco-theme') . '</th>';
            echo '<th>' . esc_html__('Found', 'cadco-theme') . '</th>';
            echo '<th>' . esc_html__('Problem', 'cadco-theme') . '</th>';
            echo '<th>' . esc_html__('How to fix', 'cadco-theme') . '</th>';
            echo '</tr></thead><tbody>';

            foreach ($issues as $issue) {
                printf(
                    '<tr><td>%s</td><td>%s</td><td>%s</td><td><code>%s</code></td><td>%s</td><td>%s</td></tr>',
                    esc_html($issue->sheet),
                    esc_html($issue->row === null ? '—' : (string) $issue->row),
                    esc_html($issue->column),
                    esc_html($issue->found),
                    esc_html($issue->message),
                    esc_html($issue->fix)
                );
            }

            echo '</tbody></table>';
        }
    }

    private static function render_plan(CADCO_Import_Plan $plan, array $changes): void
    {
        $counts = $plan->counts();
        ?>
        <ul class="cadco-import-counts">
            <li><strong><?php echo (int) $counts['create']; ?></strong> <?php esc_html_e('to create', 'cadco-theme'); ?></li>
            <li><strong><?php echo (int) $counts['update']; ?></strong> <?php esc_html_e('to update', 'cadco-theme'); ?></li>
            <li><strong><?php echo (int) $counts['rename']; ?></strong> <?php esc_html_e('renames to approve', 'cadco-theme'); ?></li>
            <li><strong><?php echo (int) $counts['trash']; ?></strong> <?php esc_html_e('to trash', 'cadco-theme'); ?></li>
            <li><strong><?php echo (int) $counts['skip']; ?></strong> <?php esc_html_e('unchanged', 'cadco-theme'); ?></li>
        </ul>

        <?php self::render_update_table($plan); ?>
        <?php self::render_create_table($plan); ?>
        <?php self::render_trash_table($plan); ?>

        <?php if (get_option('cadco_import_redirects', []) !== []) : ?>
            <p>
                <a class="button" href="<?php echo esc_url(wp_nonce_url(
                    admin_url('edit.php?post_type=product&page=cadco-import&action=export-redirects'),
                    self::NONCE
                )); ?>">
                    <?php esc_html_e('Download redirect map', 'cadco-theme'); ?>
                </a>
            </p>
        <?php endif; ?>

        <?php if ($plan->renames() !== []) : ?>
            <h2><?php esc_html_e('Renames', 'cadco-theme'); ?></h2>
            <p class="description">
                <?php esc_html_e('These products kept their UPC but changed model number. Approving one keeps the existing page, its address and its images. Leaving it unticked will trash the old product and create a new one instead.', 'cadco-theme'); ?>
            </p>
            <table class="widefat striped">
                <thead><tr>
                    <th><?php esc_html_e('Approve', 'cadco-theme'); ?></th>
                    <th><?php esc_html_e('Was', 'cadco-theme'); ?></th>
                    <th><?php esc_html_e('Becomes', 'cadco-theme'); ?></th>
                    <th><?php esc_html_e('UPC', 'cadco-theme'); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($plan->renames() as $rename) : ?>
                    <tr>
                        <td><input type="checkbox" class="cadco-rename" value="<?php echo esc_attr($rename['upc']); ?>" checked></td>
                        <td><code><?php echo esc_html($rename['old_sku']); ?></code></td>
                        <td><code><?php echo esc_html($rename['new_sku']); ?></code></td>
                        <td><?php echo esc_html($rename['upc']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if ($changes !== []) : ?>
            <h2><?php esc_html_e('Values cleaned up automatically', 'cadco-theme'); ?> <span class="count"><?php echo count($changes); ?></span></h2>
            <table class="widefat striped">
                <thead><tr>
                    <th><?php esc_html_e('Sheet', 'cadco-theme'); ?></th>
                    <th><?php esc_html_e('Row', 'cadco-theme'); ?></th>
                    <th><?php esc_html_e('Column', 'cadco-theme'); ?></th>
                    <th><?php esc_html_e('Was', 'cadco-theme'); ?></th>
                    <th><?php esc_html_e('Now', 'cadco-theme'); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach (array_slice($changes, 0, 200) as $change) : ?>
                    <tr>
                        <td><?php echo esc_html($change['sheet']); ?></td>
                        <td><?php echo (int) $change['row']; ?></td>
                        <td><?php echo esc_html($change['column']); ?></td>
                        <td><code><?php echo esc_html($change['before']); ?></code></td>
                        <td><code><?php echo esc_html($change['after']); ?></code></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <p>
            <button type="button" class="button button-primary" id="cadco-import-apply">
                <?php esc_html_e('Apply this plan', 'cadco-theme'); ?>
            </button>
        </p>
        <div id="cadco-import-progress" hidden>
            <progress value="0" max="100"></progress>
            <p class="cadco-import-status"></p>
        </div>
        <div id="cadco-import-failures" class="notice notice-error" hidden>
            <p class="cadco-import-failures-heading"></p>
            <ul class="cadco-import-failures-list"></ul>
        </div>
        <?php
    }

    /**
     * The important table on this screen (design spec §8.1 step 3): the plan
     * previously said only "N to update" with nothing to inspect. One row per
     * changed field, so the operator can actually see what an update run will
     * do — the run type every import after the first one is.
     *
     * Flattened across every update rather than one row per product, because
     * a product can have several changed fields and the cap below has to
     * bound the number of *facts* shown, not the number of products.
     */
    private static function render_update_table(CADCO_Import_Plan $plan): void
    {
        if ($plan->updates() === []) {
            return;
        }

        $rows = [];

        foreach ($plan->updates() as $update) {
            $sku = (string) ($update['row']['Model #'] ?? '');

            if ($update['diff'] === []) {
                $rows[] = ['sku' => $sku, 'no_snapshot' => true, 'field' => '', 'before' => '', 'after' => ''];
                continue;
            }

            foreach ($update['diff'] as $field => $pair) {
                $rows[] = [
                    'sku'         => $sku,
                    'no_snapshot' => false,
                    'field'       => (string) $field,
                    'before'      => (string) $pair[0],
                    'after'       => (string) $pair[1],
                ];
            }
        }
        ?>
        <h2><?php esc_html_e('Products to update', 'cadco-theme'); ?> <span class="count"><?php echo count($plan->updates()); ?></span></h2>
        <table class="widefat striped">
            <thead><tr>
                <th><?php esc_html_e('Model #', 'cadco-theme'); ?></th>
                <th><?php esc_html_e('Field', 'cadco-theme'); ?></th>
                <th><?php esc_html_e('Was', 'cadco-theme'); ?></th>
                <th><?php esc_html_e('Now', 'cadco-theme'); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach (array_slice($rows, 0, 200) as $row) : ?>
                <tr>
                    <td><code><?php echo esc_html($row['sku']); ?></code></td>
                    <?php if ($row['no_snapshot']) : ?>
                        <td colspan="3"><em><?php esc_html_e('no previous snapshot — this product predates diff tracking', 'cadco-theme'); ?></em></td>
                    <?php else : ?>
                        <td><?php echo esc_html($row['field']); ?></td>
                        <td><code><?php echo esc_html($row['before']); ?></code></td>
                        <td><code><?php echo esc_html($row['after']); ?></code></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php self::render_truncation_note(count($rows)); ?>
        <?php
    }

    private static function render_create_table(CADCO_Import_Plan $plan): void
    {
        if ($plan->creates() === []) {
            return;
        }
        ?>
        <h2><?php esc_html_e('Products to create', 'cadco-theme'); ?> <span class="count"><?php echo count($plan->creates()); ?></span></h2>
        <table class="widefat striped">
            <thead><tr>
                <th><?php esc_html_e('Model #', 'cadco-theme'); ?></th>
                <th><?php esc_html_e('Product Name', 'cadco-theme'); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach (array_slice($plan->creates(), 0, 200) as $create) : ?>
                <tr>
                    <td><code><?php echo esc_html((string) ($create['row']['Model #'] ?? '')); ?></code></td>
                    <td><?php echo esc_html((string) ($create['row']['Product Name'] ?? '')); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php self::render_truncation_note(count($plan->creates())); ?>
        <?php
    }

    private static function render_trash_table(CADCO_Import_Plan $plan): void
    {
        if ($plan->trashes() === []) {
            return;
        }
        ?>
        <h2><?php esc_html_e('Products to trash', 'cadco-theme'); ?> <span class="count"><?php echo count($plan->trashes()); ?></span></h2>
        <p class="description">
            <?php esc_html_e('These products are trashed, not deleted, and can be restored.', 'cadco-theme'); ?>
        </p>
        <table class="widefat striped">
            <thead><tr>
                <th><?php esc_html_e('Model #', 'cadco-theme'); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach (array_slice($plan->trashes(), 0, 200) as $trash) : ?>
                <tr>
                    <td><code><?php echo esc_html((string) $trash['sku']); ?></code></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php self::render_truncation_note(count($plan->trashes())); ?>
        <?php
    }

    /**
     * A silent cap on a plan the operator is approving would be worse than no
     * cap at all — so whenever array_slice(..., 0, 200) actually cut
     * something, say how many rows were hidden.
     */
    private static function render_truncation_note(int $total): void
    {
        if ($total <= 200) {
            return;
        }
        ?>
        <p class="description">
            <?php
            printf(
                /* translators: %d: number of rows not shown */
                esc_html__('%d more not shown.', 'cadco-theme'),
                $total - 200
            );
            ?>
        </p>
        <?php
    }

    private static function notice(string $type, string $message): void
    {
        printf(
            '<div class="notice notice-%s"><p>%s</p></div>',
            esc_attr($type),
            esc_html($message)
        );
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
            // a guessable-if-not-for-guard_imports_dir() URL. .bin carries
            // no handler mapping anywhere.
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
     * Download the legacy-URL redirect map as CSV.
     *
     * Renamed products keep their post ID and their page, but their address
     * changes with the model number. This map pairs the old model number with
     * the new URL so the redirects can be loaded into Yoast or the server
     * config — the importer deliberately does not create them itself, because
     * redirect handling is the SEO plugin's job on this site.
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

        $map    = (array) get_option('cadco_import_redirects', []);
        $handle = fopen('php://output', 'w');

        if ($handle === false) {
            wp_die(esc_html__('The redirect map could not be generated.', 'cadco-theme'));
        }

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=cadco-redirects.csv');

        fputcsv($handle, array_map([CADCO_Import_Report::class, 'csv_safe'], ['Old model number', 'New URL']), ',', '"', '');

        foreach ($map as $old => $url) {
            fputcsv($handle, array_map([CADCO_Import_Report::class, 'csv_safe'], [(string) $old, (string) $url]), ',', '"', '');
        }

        fclose($handle);
        exit;
    }

    /**
     * Download the validation report as CSV.
     *
     * The report is the primary deliverable of a failing run (design spec
     * §8.1 step 2) — guard_imports_dir() denies web access to the archived
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
