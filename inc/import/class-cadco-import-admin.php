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
                'failed'   => __('The import failed.', 'cadco-theme'),
                'network'  => __('The import request failed.', 'cadco-theme'),
                /* translators: %d: number of changes applied */
                'done'     => __('Done — %d changes applied.', 'cadco-theme'),
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

        if (isset($_POST['cadco_import_upload'])) {
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
        if (!isset($_FILES['workbook']) || ($_FILES['workbook']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            self::notice('error', __('No file was uploaded.', 'cadco-theme'));

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

        $dir = trailingslashit(wp_upload_dir()['basedir']) . 'cadco-imports/' . gmdate('Y-m-d-His');
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
            $queue_file = $run['dir'] . '/queue.php.ser';

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
            CADCO_Import_Applier::finalise();

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
}
