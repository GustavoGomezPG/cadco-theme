<?php

/**
 * Products → Import: rendering.
 *
 * Everything here emits markup and nothing else — no request handling, no
 * pipeline decisions. CADCO_Import_Admin decides what state the screen is in
 * (form, report, plan) and calls into this class to draw it. Moved out of
 * the controller verbatim; see class-cadco-import-admin.php for the state
 * resolution this is called from.
 */

declare(strict_types=1);

final class CADCO_Import_View
{
    /**
     * The `Import` / `History` tab bar (design spec §5), using WordPress's own
     * `nav-tab` convention so it reads as native chrome rather than a custom
     * widget. `$active` is one of 'import' | 'history'.
     */
    public static function tabs(string $active): void
    {
        $import_url  = admin_url('edit.php?post_type=product&page=cadco-import');
        $history_url = admin_url('edit.php?post_type=product&page=cadco-import&tab=history');
        ?>
        <nav class="nav-tab-wrapper cadco-import-tabs" aria-label="<?php esc_attr_e('Import sections', 'cadco-theme'); ?>">
            <a href="<?php echo esc_url($import_url); ?>" class="nav-tab<?php echo $active === 'import' ? ' nav-tab-active' : ''; ?>">
                <?php esc_html_e('Import', 'cadco-theme'); ?>
            </a>
            <a href="<?php echo esc_url($history_url); ?>" class="nav-tab<?php echo $active === 'history' ? ' nav-tab-active' : ''; ?>">
                <?php esc_html_e('History', 'cadco-theme'); ?>
            </a>
        </nav>
        <?php
    }

    /**
     * The three-stage wizard bar (design spec §4.1, §5): Upload → Review →
     * Apply, each carrying a status word, a numbered title and a subtitle of
     * real figures — never static copy standing in for one. `$state` is one
     * of 'upload' | 'invalid' | 'review' — the only states the controller
     * ever resolves to *before* a page render; 'applying' and 'done' happen
     * client-side, after this markup has already left the server, and never
     * reach this method.
     *
     * $context carries:
     *  - filename: string   the uploaded workbook's original name, or '' before any upload
     *  - rows:     int       rows read from the workbook (0 before any upload)
     *  - issues:   int       validation issues found (0 before any upload, or once clean)
     */
    public static function stage_bar(string $state, array $context): void
    {
        $filename = (string) ($context['filename'] ?? '');
        $rows     = (int) ($context['rows'] ?? 0);
        $issues   = (int) ($context['issues'] ?? 0);

        // Only 'upload' leaves stage 1 itself current; both 'invalid' and
        // 'review' mean a workbook was already read, so stage 2 (Review) is
        // where the operator now stands — whether that review found problems
        // or not.
        $current = $state === 'upload' ? 1 : 2;

        $subtitle_upload = $filename !== ''
            ? $filename
            : __('no workbook yet', 'cadco-theme');

        if ($state === 'upload') {
            $subtitle_review = __('waiting for a workbook', 'cadco-theme');
        } else {
            // Two independent nouns, each pluralised on its own count — a
            // single _n() keyed to $rows would silently mis-pluralise
            // "issue(s)" whenever the row and issue counts disagree, which is
            // the common case (0 issues on nearly every row count).
            $subtitle_review = sprintf(
                '%1$s, %2$s',
                sprintf(
                    /* translators: %d: number of rows read from the workbook */
                    _n('%d row', '%d rows', $rows, 'cadco-theme'),
                    $rows
                ),
                sprintf(
                    /* translators: %d: number of validation issues found */
                    _n('%d issue', '%d issues', $issues, 'cadco-theme'),
                    $issues
                )
            );
        }

        $stages = [
            // "01 — Upload", per the design file's own stage titles. The
            // zero-padded number and the em dash are part of the wording, not
            // decoration added in CSS, so they stay translatable with the rest
            // of the label.
            1 => ['title' => __('01 — Upload', 'cadco-theme'), 'subtitle' => $subtitle_upload],
            2 => ['title' => __('02 — Review', 'cadco-theme'), 'subtitle' => $subtitle_review],
            3 => ['title' => __('03 — Apply', 'cadco-theme'), 'subtitle' => __('nothing written yet', 'cadco-theme')],
        ];

        $status_words = [
            'complete' => __('Complete', 'cadco-theme'),
            'current'  => __('Current', 'cadco-theme'),
            'waiting'  => __('Waiting', 'cadco-theme'),
        ];
        ?>
        <div class="cadco-import-stagebar">
            <ol class="cadco-import-stages">
                <?php foreach ($stages as $number => $stage) :
                    $status = $number < $current ? 'complete' : ($number === $current ? 'current' : 'waiting');
                    ?>
                    <li class="cadco-import-stage is-<?php echo esc_attr($status); ?>"<?php echo $status === 'current' ? ' aria-current="step"' : ''; ?>>
                        <span class="cadco-import-stage-status"><?php echo esc_html($status_words[$status]); ?></span>
                        <span class="cadco-import-stage-title"><?php echo esc_html($stage['title']); ?></span>
                        <span class="cadco-import-stage-subtitle"><?php echo esc_html($stage['subtitle']); ?></span>
                    </li>
                <?php endforeach; ?>
            </ol>
            <div class="cadco-import-cta">
                <?php self::cta($state, $issues); ?>
            </div>
        </div>
        <?php
    }

    /**
     * The primary action, pinned beside the stage bar (design spec §5). It is
     * the honest signal of whether this import can run: real and clickable
     * only in 'review', and on every other state a disabled button that says
     * *why*, not a greyed-out button with no explanation.
     *
     * The 'review' button keeps the exact id/classes/markup the batched-apply
     * JS (assets/js/import-admin.js) and the E2E suite already depend on —
     * #cadco-import-apply, #cadco-import-progress, #cadco-import-failures —
     * moved here verbatim from the foot of plan(), not reinvented. The
     * disabled placeholder deliberately carries no id: the E2E suite asserts
     * zero elements match #cadco-import-apply on an invalid workbook, so the
     * inert stand-in must never claim that id.
     */
    private static function cta(string $state, int $issues): void
    {
        if ($state === 'review') {
            ?>
            <button type="button" class="button button-primary" id="cadco-import-apply">
                <?php esc_html_e('Apply plan', 'cadco-theme'); ?>
            </button>
            <div id="cadco-import-progress" hidden>
                <progress value="0" max="100"></progress>
                <p class="cadco-import-status" aria-live="polite"></p>
            </div>
            <div id="cadco-import-failures" class="notice notice-error" hidden>
                <p class="cadco-import-failures-heading"></p>
                <ul class="cadco-import-failures-list"></ul>
            </div>
            <?php
            return;
        }

        // 'invalid' gets its own modifier class so the disabled CTA can
        // carry the one red signal this screen allows itself (design spec
        // §5's "status colour used sparingly and meaningfully… red only for
        // blocking problems") — the plain "nothing uploaded yet" disabled
        // state on 'upload' is not a problem, so it stays neutral grey.
        $blocked = $state === 'invalid';

        $label = $blocked
            ? sprintf(
                /* translators: %d: number of validation problems blocking the import */
                _n('Fix %d problem to continue', 'Fix %d problems to continue', $issues, 'cadco-theme'),
                $issues
            )
            : __('Upload a workbook to continue', 'cadco-theme');
        ?>
        <button
            type="button"
            class="button button-primary<?php echo $blocked ? ' cadco-import-cta-blocked' : ''; ?>"
            disabled
            aria-disabled="true"
            title="<?php echo esc_attr($label); ?>"
        >
            <?php echo esc_html($label); ?>
        </button>
        <?php
    }

    /**
     * The History tab's empty state (design spec §8). Runs are archived from
     * Task 6 on, but the list itself — reading manifest.json per run — is
     * Task 8's job; until then the tab exists and says so plainly rather than
     * rendering nothing.
     */
    public static function history_empty(): void
    {
        ?>
        <div class="cadco-import-history-empty">
            <p><?php esc_html_e('No import runs yet. Once you check a workbook on the Import tab, it will appear here.', 'cadco-theme'); ?></p>
        </div>
        <?php
    }

    /**
     * The History tab's list (task brief step 1, design spec §8.1): Date,
     * Label (edited inline), original filename, Result, and Actions. Every
     * fact here comes straight off each run's manifest.json
     * (CADCO_Import_Archive::all(), called from CADCO_Import_Admin::render()
     * — "Read manifests only," per the brief) — never a re-opened workbook,
     * report or plan, so this list stays fast no matter how many runs are
     * retained.
     *
     * Restore is offered only for a run that passed validation (and
     * therefore has an archived workbook.xlsx and plan.json to restore
     * from); a failed run offers only its report — design spec §8.1: "A
     * failed run has no plan to restore, so it offers only its report."
     *
     * @param list<array<string, mixed>> $runs newest first — CADCO_Import_Archive::all()'s own order
     */
    public static function history(array $runs): void
    {
        // A lookup of every currently-retained run by its own id, built
        // once, so a restore row can show which run it restores FROM (fix
        // round 1, finding 4) without any extra filesystem read: the source
        // run, if it hasn't been pruned since, is already sitting somewhere
        // else in this exact $runs list — CADCO_Import_Archive::all() reads
        // every retained manifest in one pass regardless.
        $by_id = [];

        foreach ($runs as $run) {
            if (isset($run['run_id']) && is_string($run['run_id'])) {
                $by_id[$run['run_id']] = $run;
            }
        }
        ?>
        <div class="cadco-import-history-panel">
            <h2><?php esc_html_e('Past runs', 'cadco-theme'); ?></h2>
            <p>
                <?php
                printf(
                    /* translators: %d: how many import runs are retained before the oldest is deleted */
                    esc_html__('The %d most recent workbooks are kept with their plans, so any run can be re-checked and restored against the catalogue as it stands today.', 'cadco-theme'),
                    (int) CADCO_Import_Archive::KEEP
                );
                ?>
            </p>
        <table class="widefat striped cadco-import-history">
            <thead>
            <tr>
                <th scope="col"><?php esc_html_e('Date', 'cadco-theme'); ?></th>
                <th scope="col"><?php esc_html_e('Label', 'cadco-theme'); ?></th>
                <th scope="col"><?php esc_html_e('Filename', 'cadco-theme'); ?></th>
                <th scope="col"><?php esc_html_e('Result', 'cadco-theme'); ?></th>
                <th scope="col"><?php esc_html_e('Actions', 'cadco-theme'); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($runs as $run) : self::history_row($run, $by_id); endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php
    }

    /**
     * One History row. Every value it prints is untrusted — an archived
     * filename, an operator-typed label, a run id derived from user input on
     * the restore/report links below — so every one of them goes through
     * esc_html()/esc_attr()/esc_url() here, per the brief's escaping
     * requirement and this system's own prior XSS findings.
     *
     * Fix round 1, finding 1: a passed-but-never-applied run (Review was
     * reached and archived, but the operator never clicked Apply, or the
     * apply run never finished) still offers Restore — the workbook is
     * valid and restoring from it is meaningful — but the row carries a
     * `cadco-import-history-unapplied` class and run_result_summary()
     * prefixes its Result with "Not applied", so what used to read as
     * "236 created" (a past-tense fact) cannot be mistaken for one when it
     * is really only ever what applying THIS plan would still do.
     *
     * @param array<string, mixed> $run one manifest, shaped as CADCO_Import_Archive::all()/get() return it
     * @param array<string, array<string, mixed>> $by_id every retained run's own manifest, keyed by run_id
     */
    private static function history_row(array $run, array $by_id): void
    {
        $run_id  = (string) ($run['run_id'] ?? '');
        $label   = (string) ($run['label'] ?? '');
        $passed  = !empty($run['passed']);
        $applied = !empty($run['applied']);
        ?>
        <tr<?php echo ($passed && !$applied) ? ' class="cadco-import-history-unapplied"' : ''; ?>>
            <td><?php echo esc_html(self::format_run_date((string) ($run['created'] ?? ''))); ?></td>
            <td>
                <input
                    type="text"
                    class="cadco-import-history-label regular-text"
                    data-run-id="<?php echo esc_attr($run_id); ?>"
                    value="<?php echo esc_attr($label); ?>"
                    placeholder="<?php esc_attr_e('Add a label…', 'cadco-theme'); ?>"
                    aria-label="<?php esc_attr_e('Label for this import run', 'cadco-theme'); ?>"
                >
                <span class="cadco-import-history-label-status" aria-live="polite"></span>
            </td>
            <td>
                <?php echo esc_html((string) ($run['filename'] ?? '')); ?>
                <?php self::lineage_note($run, $by_id); ?>
            </td>
            <td><?php echo esc_html(self::run_result_summary($run)); ?></td>
            <td>
                <?php if ($passed) : ?>
                    <a
                        class="button"
                        href="<?php echo esc_url(wp_nonce_url(
                            admin_url('edit.php?post_type=product&page=cadco-import&action=restore&run_id=' . rawurlencode($run_id)),
                            CADCO_Import_Admin::NONCE
                        )); ?>"
                    ><?php esc_html_e('Restore', 'cadco-theme'); ?></a>
                <?php else : ?>
                    <a
                        class="button"
                        href="<?php echo esc_url(wp_nonce_url(
                            admin_url('edit.php?post_type=product&page=cadco-import&action=export-archived-report&run_id=' . rawurlencode($run_id)),
                            CADCO_Import_Admin::NONCE
                        )); ?>"
                    ><?php esc_html_e('View report', 'cadco-theme'); ?></a>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    /**
     * "Restore of <date>" under a restore's own filename (fix round 1,
     * finding 4) — makes the lineage between a restore and its source
     * visible on the list itself, not just inside the restore banner on the
     * one Review page that briefly followed it. Renders nothing for a run
     * with no `restored_from` (every ordinary import).
     *
     * Degrades honestly rather than silently when the source has since
     * fallen out of the retention window: still names the relationship,
     * just without a date it no longer has anywhere to read from.
     *
     * @param array<string, mixed> $run
     * @param array<string, array<string, mixed>> $by_id
     */
    private static function lineage_note(array $run, array $by_id): void
    {
        $restored_from = $run['restored_from'] ?? null;

        if (!is_string($restored_from) || $restored_from === '') {
            return;
        }

        $origin = $by_id[$restored_from] ?? null;
        $date   = $origin !== null
            ? self::format_run_date((string) ($origin['created'] ?? ''))
            : __('an earlier run no longer kept', 'cadco-theme');
        ?>
        <br><span class="cadco-import-history-lineage">
            <?php
            printf(
                /* translators: %s: the date the restored run was originally taken, or a note that it is no longer kept */
                esc_html__('Restore of %s', 'cadco-theme'),
                esc_html($date)
            );
            ?>
        </span>
        <?php
    }

    /**
     * The Result column's text (task brief: "counts, or the issue count for
     * a failed run"). A passed run lists every non-zero count from its
     * manifest (created/updated/renamed/trashed/restored — 'skip' is
     * deliberately excluded, an unchanged row is not a result worth
     * naming); a failed run reports its issue count instead, since it has
     * no plan at all.
     *
     * Fix round 1, finding 1: `applied: false` on a passed run means Review
     * was reached but Apply was never clicked (or never finished) — those
     * counts are only ever a forecast of what applying this plan WOULD do,
     * not a record of what it DID. Prefixing "Not applied —" keeps that
     * distinction on the screen instead of letting a forecast read as a
     * past-tense fact.
     *
     * @param array<string, mixed> $run
     */
    private static function run_result_summary(array $run): string
    {
        if (empty($run['passed'])) {
            $issues = (int) ($run['issues'] ?? 0);

            return sprintf(
                /* translators: %d: number of validation issues found */
                _n('failed, %d issue', 'failed, %d issues', $issues, 'cadco-theme'),
                $issues
            );
        }

        $counts = is_array($run['counts'] ?? null) ? $run['counts'] : [];

        $labels = [
            'create'  => __('created', 'cadco-theme'),
            'update'  => __('updated', 'cadco-theme'),
            'rename'  => __('renamed', 'cadco-theme'),
            'trash'   => __('trashed', 'cadco-theme'),
            'untrash' => __('restored', 'cadco-theme'),
        ];

        $parts = [];

        foreach ($labels as $key => $label) {
            $n = (int) ($counts[$key] ?? 0);

            if ($n > 0) {
                $parts[] = sprintf('%d %s', $n, $label);
            }
        }

        $summary = $parts === [] ? __('no changes', 'cadco-theme') : implode(' · ', $parts);

        if (empty($run['applied'])) {
            /* translators: %s: what this run's plan would still do if applied, e.g. "236 created" or "no changes" */
            return sprintf(__('Not applied — %s', 'cadco-theme'), $summary);
        }

        return $summary;
    }

    /**
     * Stage 1. The design frames this as a drop target rather than a bare file
     * input, and assets/js/import-admin.js wires real drag-and-drop onto it —
     * a panel that says "drop the workbook here" and then ignores a drop would
     * be worse than one that never made the offer.
     *
     * The native <input type="file"> stays in the markup, visually hidden but
     * focusable and label-linked: it is what actually carries the file on
     * submit, what keyboard and screen-reader users operate, and what the E2E
     * suite drives with setInputFiles(). The drop handler only ever assigns to
     * its `files` property; it is never replaced.
     *
     * The size limit is read from the server rather than hard-coded, so the
     * number on screen cannot drift away from the one PHP will actually
     * enforce.
     */
    public static function upload_form(): void
    {
        $max = size_format(wp_max_upload_size());
        ?>
        <div class="cadco-import-upload-grid">
            <div class="cadco-import-section cadco-import-upload">
                <form method="post" enctype="multipart/form-data">
                    <?php wp_nonce_field(CADCO_Import_Admin::NONCE); ?>
                    <div class="cadco-import-dropzone" id="cadco-import-dropzone">
                        <svg class="cadco-import-dropzone-icon" width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false">
                            <path d="M12 16V4"></path><path d="m7 9 5-5 5 5"></path><path d="M4 20h16"></path>
                        </svg>
                        <h2><?php esc_html_e('Drop the product workbook here', 'cadco-theme'); ?></h2>
                        <p>
                            <?php esc_html_e('One .xlsx file. Nothing is written until you have read the plan and pressed Apply — a workbook with problems is rejected whole, never half-imported.', 'cadco-theme'); ?>
                        </p>

                        <label class="button button-primary" for="cadco-import-file">
                            <?php esc_html_e('Choose file…', 'cadco-theme'); ?>
                        </label>
                        <input
                            type="file"
                            name="workbook"
                            id="cadco-import-file"
                            class="cadco-import-file"
                            accept=".xlsx"
                            required
                        >

                        <p class="cadco-import-dropzone-hint">
                            <span class="cadco-import-filename" id="cadco-import-filename" aria-live="polite"></span>
                            <?php
                            printf(
                                /* translators: %s: the server's maximum upload size, e.g. "20 MB" */
                                esc_html__('.xlsx · up to %s', 'cadco-theme'),
                                esc_html($max)
                            );
                            ?>
                        </p>

                        <?php
                        // Choosing a file submits the form (assets/js/import-admin.js):
                        // picking the workbook IS the step, so a second "now
                        // press this" button would be a needless gate.
                        //
                        // The submit button is still rendered, because without
                        // JavaScript there would otherwise be no way to submit at
                        // all. The same script that adds the auto-submit hides it,
                        // so the button only ever appears when it is genuinely the
                        // only route forward.
                        ?>
                        <div class="cadco-import-submit-fallback" id="cadco-import-submit-fallback">
                            <?php submit_button(__('Check workbook', 'cadco-theme'), 'primary', 'cadco_import_upload', false); ?>
                        </div>
                    </div>
                </form>
            </div>

            <div class="cadco-import-section">
                <h2><?php esc_html_e('What happens next', 'cadco-theme'); ?></h2>
                <p><?php esc_html_e('Three steps. You can walk away after any of them.', 'cadco-theme'); ?></p>
                <ol class="cadco-import-steps">
                    <?php
                    $steps = [
                        [
                            'title' => __('Checked', 'cadco-theme'),
                            'body'  => __('Every row is validated first. If anything is wrong the whole workbook is rejected and you get a list of what to fix — nothing is written.', 'cadco-theme'),
                        ],
                        [
                            'title' => __('Reviewed', 'cadco-theme'),
                            'body'  => __('You see exactly what would change: new categories, new products, every altered field, and what would be trashed. Still nothing is written.', 'cadco-theme'),
                        ],
                        [
                            'title' => __('Applied', 'cadco-theme'),
                            'body'  => __('Only when you press Apply. Changes are written in batches, and the workbook is kept so this run can be restored later.', 'cadco-theme'),
                        ],
                    ];

                    foreach ($steps as $i => $step) {
                        ?>
                        <li>
                            <span class="cadco-import-step-n"><?php echo esc_html(sprintf('%02d', $i + 1)); ?></span>
                            <span class="cadco-import-step-body">
                                <span class="cadco-import-step-title"><?php echo esc_html($step['title']); ?></span>
                                <span class="cadco-import-step-text"><?php echo esc_html($step['body']); ?></span>
                            </span>
                        </li>
                        <?php
                    }
                    ?>
                </ol>
            </div>
        </div>
        <?php
    }

    public static function report(CADCO_Import_Report $report): void
    {
        // The report is the system's primary deliverable (design spec §8.1) —
        // it must leave the browser as a file CADCO can hand to whoever
        // maintains the workbook, not just sit on screen.
        printf(
            '<p><a class="button" href="%s">%s</a></p>',
            esc_url(wp_nonce_url(
                admin_url('edit.php?post_type=product&page=cadco-import&action=export-report'),
                CADCO_Import_Admin::NONCE
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

    /**
     * The Review screen (design spec §6): a persistent counts summary, a
     * change navigator, and the eight sections it points at — Workbook,
     * Categories, Products, Updates, Renames, Removals, Cleaned up,
     * Redirects.
     *
     * Every section renders into the page unconditionally, including one
     * whose count is zero — "the absence of renames is itself information
     * worth seeing" (task brief) — so this deliberately does not gate any of
     * the section calls below on whether the underlying list is empty; each
     * section method makes that call internally, printing a stated zero
     * state instead of nothing.
     *
     * Navigation is client-side and additive rather than exclusive: every
     * section stays on the page and readable top-to-bottom (which is also
     * what "so nothing hides below a scroll" in design spec §6.1 describes —
     * a sidebar that itself never scrolls out of view, not content that
     * disappears). assets/js/import-admin.js progressively enhances the
     * sidebar's plain `<a href="#section-id">` anchors into a smooth-scroll
     * jump that also moves focus and updates `aria-current`; without JS the
     * anchors still work natively. This keeps every already-existing E2E
     * assertion valid — several of them (the rename-approval test, the
     * per-field-diff test) require a specific section's table to be visible
     * immediately after checking a workbook, with no navigator interaction
     * at all, which a strict show-one-hide-the-rest tab implementation could
     * not satisfy for more than one of those tests at a time.
     *
     * @param array<string, array{new: list<array<string, mixed>>, removed: list<array<string, mixed>>, in_use: list<array<string, mixed>>}> $term_diff
     * @param array{filename:string, size:int, rows:int, issues:int, sheets:list<array{name:string, rows:int}>} $workbook
     * @param array<string, string> $redirect_map
     * @param array{run_id:string, label:string, filename:string, created:string}|null $restore
     *        Non-null only when this Review is a restore (task brief step
     *        3, design spec §8.3) — the run being restored, so
     *        restore_banner() can name it, never anything about the new
     *        run this restore is itself about to become.
     */
    public static function review(
        CADCO_Import_Plan $plan,
        array $changes,
        array $rows,
        array $term_diff,
        array $workbook,
        array $redirect_map,
        ?array $restore = null
    ): void {
        $counts = $plan->counts();

        if ($restore !== null) {
            self::restore_banner($restore);
        }
        ?>
        <?php
        // The design's figures strip: equal cells divided by hairlines, a large
        // condensed numeral over a small uppercase label. A zero reads muted
        // rather than being dropped — "no renames" is itself worth seeing.
        $figures = [
            ['n' => (int) $counts['create'],  'label' => __('to create', 'cadco-theme')],
            ['n' => (int) $counts['update'],  'label' => __('to update', 'cadco-theme')],
            ['n' => (int) $counts['rename'],  'label' => __('renames to approve', 'cadco-theme')],
            ['n' => (int) $counts['trash'],   'label' => __('to trash', 'cadco-theme')],
            ['n' => (int) $counts['untrash'], 'label' => __('to restore', 'cadco-theme')],
            ['n' => (int) $counts['skip'],    'label' => __('unchanged', 'cadco-theme')],
        ];
        ?>
        <ul class="cadco-import-counts">
            <?php foreach ($figures as $figure) { ?>
                <li<?php echo $figure['n'] === 0 ? ' class="is-zero"' : ''; ?>>
                    <strong><?php echo (int) $figure['n']; ?></strong>
                    <span class="cadco-import-count-label"><?php echo esc_html($figure['label']); ?></span>
                </li>
            <?php } ?>
        </ul>
        <?php
        $term_totals       = self::term_diff_totals($term_diff);
        $redirect_forecast = self::legacy_redirect_forecast($rows);

        $nav = [
            'workbook' => [
                'label' => __('Workbook', 'cadco-theme'),
                'meta'  => sprintf(
                    /* translators: 1: number of rows read, 2: number of sheets read */
                    __('%1$d rows · %2$d sheets', 'cadco-theme'),
                    $workbook['rows'],
                    count($workbook['sheets'])
                ),
                'muted' => false,
            ],
            'categories' => [
                'label' => __('Categories', 'cadco-theme'),
                'meta'  => self::categories_nav_meta($term_totals),
                'muted' => ($term_totals['new'] + $term_totals['removed'] + $term_totals['in_use']) === 0,
            ],
            'products' => [
                'label' => __('Products', 'cadco-theme'),
                'meta'  => (string) $counts['create'],
                'muted' => $counts['create'] === 0,
            ],
            'updates' => [
                'label' => __('Updates', 'cadco-theme'),
                'meta'  => (string) $counts['update'],
                'muted' => $counts['update'] === 0,
            ],
            'renames' => [
                'label' => __('Renames', 'cadco-theme'),
                'meta'  => (string) $counts['rename'],
                'muted' => $counts['rename'] === 0,
            ],
            'removals' => [
                'label' => __('Removals', 'cadco-theme'),
                'meta'  => (string) $counts['trash'],
                'muted' => $counts['trash'] === 0,
            ],
            'cleaned-up' => [
                'label' => __('Cleaned up', 'cadco-theme'),
                'meta'  => (string) count($changes),
                'muted' => $changes === [],
            ],
            'redirects' => [
                'label' => __('Redirects', 'cadco-theme'),
                // The forecast, not count($redirect_map) — see
                // legacy_redirect_forecast()'s docblock for why the latter
                // is empty on exactly the first-import scenario this
                // feature exists for.
                'meta'  => (string) $redirect_forecast['will_export'],
                'muted' => $redirect_forecast['will_export'] === 0
                    && $redirect_map === []
                    && $redirect_forecast['skipped'] === []
                    && $redirect_forecast['collisions'] === [],
            ],
        ];

        ?>
        <div class="cadco-import-panels-wrap">
            <?php self::navigator($nav); ?>
            <div class="cadco-import-panels">
                <?php
                self::section_workbook($workbook);
                self::section_categories($term_diff, $term_totals);
                self::section_products($plan);
                self::section_updates($plan);
                self::section_renames($plan);
                self::section_removals($plan);
                self::section_cleaned_up($changes);
                self::section_redirects($redirect_map, $redirect_forecast);
                ?>
            </div>
        </div>
        <?php
        // The Apply button, its progress bar and its failures box are no
        // longer rendered here: the wizard shell (design spec §5) pins the
        // primary action beside the stage bar, above this content, so it is
        // CADCO_Import_View::stage_bar()/cta() that draws #cadco-import-apply
        // now — see class-cadco-import-admin.php's render(), which calls
        // stage_bar() before review().
    }

    /**
     * The restore banner (task brief step 3, design spec §8.3): "Show a
     * restore banner on Review naming the run and its date, so a restore
     * can never be mistaken for a fresh import." $restore names the
     * ORIGINAL archived run being restored — see review()'s own docblock
     * for why that, and not the fresh run this restore is itself about to
     * become, is what belongs here.
     *
     * Every value is untrusted: the label is operator-typed
     * (CADCO_Import_Admin::ajax_label()), the filename is whatever the
     * original upload was named. Both go through esc_html() here — this is
     * the "escaped on output" half of the brief's label requirement; the
     * write side only sanitizes, which is not a substitute.
     *
     * Fix round 1, finding 6: `notice-warning` alone, without `inline`, is
     * exactly what wp-admin/js/common.js looks for to relocate a notice to
     * just after the page header — this screen has no `.wp-header-end`, so
     * an un-inlined notice here would jump above the tab nav and stage bar
     * instead of staying in flow between the success notice and the counts
     * summary where review() places it and where its margin is tuned. The
     * `inline` class opts this banner out of that relocation, the same as
     * WordPress's own admin screens do for any notice meant to stay exactly
     * where it was rendered.
     *
     * @param array{run_id:string, label:string, filename:string, created:string} $restore
     */
    private static function restore_banner(array $restore): void
    {
        $label    = (string) $restore['label'];
        $filename = (string) $restore['filename'];
        $name     = $label !== '' ? $label : $filename;
        $date     = self::format_run_date((string) $restore['created']);
        ?>
        <div class="notice notice-warning inline cadco-import-restore-banner">
            <p>
                <strong><?php esc_html_e('Restoring a previous import — this is not a fresh upload.', 'cadco-theme'); ?></strong>
            </p>
            <p>
                <?php
                printf(
                    /* translators: 1: the restored run's label or original filename, 2: the date that run was taken */
                    esc_html__('Re-checking "%1$s", taken %2$s, against the catalogue as it stands right now. Applying this plan restores the catalogue to match that earlier workbook — including bringing back, in place, any product it removed since.', 'cadco-theme'),
                    esc_html($name),
                    esc_html($date)
                );
                ?>
            </p>
        </div>
        <?php
    }

    /**
     * A run's `created` UTC ISO-8601 timestamp (design spec §8.2), formatted
     * in the site's own timezone and date/time format. Shared by the
     * restore banner and the history table (below) so both name a run's
     * date the same way. Degrades to a plain, honest "unknown date" rather
     * than a fatal or a blank cell for a manifest whose `created` is
     * missing or malformed — the same defensive posture
     * CADCO_Import_Archive itself takes toward a hand-edited manifest.
     */
    private static function format_run_date(string $created): string
    {
        $timestamp = $created !== '' ? strtotime($created) : false;

        if ($timestamp === false) {
            return __('an unknown date', 'cadco-theme');
        }

        return (string) wp_date(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
    }

    /**
     * The change navigator (design spec §6.1): every section, its count,
     * always on screen. A muted section (zero count) renders as inert
     * `<span>` text rather than a link — nothing to jump to, and nothing a
     * screen reader should announce as actionable.
     *
     * `aria-current="true"` starts on Workbook, the first section on the
     * page, and assets/js/import-admin.js moves it to whichever link was
     * last clicked. `aria-controls` ties each live link to the section id it
     * jumps to; the section itself is labelled by its own on-page heading
     * (see section_open()) rather than by the nav link, which is the more
     * standard direction for that relationship.
     *
     * @param array<string, array{label:string, meta:string, muted:bool}> $sections
     */
    private static function navigator(array $sections): void
    {
        $first = array_key_first($sections);
        ?>
        <nav class="cadco-import-navigator" aria-label="<?php esc_attr_e('Review sections', 'cadco-theme'); ?>">
            <p class="cadco-import-navigator-title"><?php esc_html_e('The plan', 'cadco-theme'); ?></p>
            <ul>
                <?php foreach ($sections as $id => $section) : ?>
                    <li class="cadco-import-nav-item<?php echo !empty($section['muted']) ? ' is-muted' : ''; ?>">
                        <?php if (!empty($section['muted'])) : ?>
                            <span class="cadco-import-nav-link is-disabled" aria-disabled="true">
                                <span class="cadco-import-nav-label"><?php echo esc_html($section['label']); ?></span>
                                <span class="cadco-import-nav-meta"><?php echo esc_html($section['meta']); ?></span>
                            </span>
                        <?php else : ?>
                            <a
                                href="#cadco-section-<?php echo esc_attr($id); ?>"
                                id="cadco-nav-<?php echo esc_attr($id); ?>"
                                class="cadco-import-nav-link"
                                aria-controls="cadco-section-<?php echo esc_attr($id); ?>"
                                <?php echo $id === $first ? ' aria-current="true"' : ''; ?>
                            >
                                <span class="cadco-import-nav-label"><?php echo esc_html($section['label']); ?></span>
                                <span class="cadco-import-nav-meta"><?php echo esc_html($section['meta']); ?></span>
                            </a>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </nav>
        <?php
    }

    private static function section_open(string $id): void
    {
        printf(
            '<section id="cadco-section-%1$s" class="cadco-import-section" tabindex="-1" aria-labelledby="cadco-heading-%1$s">',
            esc_attr($id)
        );
    }

    private static function section_close(): void
    {
        echo '</section>';
    }

    /**
     * Totals the term diff (design spec §7) across all three taxonomies for
     * the navigator's Categories badge: every new term, every removed term,
     * every in-use term. A product_cat "new" entry always carries at least
     * one new child, but is only itself a new term when its own
     * `parent_is_new` says so — an existing parent that merely gained a
     * child must not be double-counted as a new term of its own (see
     * CADCO_Import_Term_Diff::compare()'s `parent_is_new`).
     *
     * @param array<string, array{new: list<array<string, mixed>>, removed: list<array<string, mixed>>, in_use: list<array<string, mixed>>}> $term_diff
     * @return array{new:int, removed:int, in_use:int}
     */
    private static function term_diff_totals(array $term_diff): array
    {
        $new = 0;
        $removed = 0;
        $in_use = 0;

        foreach ($term_diff as $taxonomy => $bucket) {
            if ($taxonomy === 'product_cat') {
                foreach ($bucket['new'] as $parent) {
                    $new += count($parent['children']) + (!empty($parent['parent_is_new']) ? 1 : 0);
                }
            } else {
                $new += count($bucket['new']);
            }

            $removed += count($bucket['removed']);
            $in_use  += count($bucket['in_use']);
        }

        return ['new' => $new, 'removed' => $removed, 'in_use' => $in_use];
    }

    /**
     * @param array{new:int, removed:int, in_use:int} $totals
     */
    private static function categories_nav_meta(array $totals): string
    {
        $meta = sprintf('+%d / -%d', $totals['new'], $totals['removed']);

        if ($totals['in_use'] > 0) {
            $meta .= sprintf(' / !%d', $totals['in_use']);
        }

        return $meta;
    }

    /**
     * @param array{filename:string, size:int, rows:int, issues:int, sheets:list<array{name:string, rows:int}>} $workbook
     */
    private static function section_workbook(array $workbook): void
    {
        self::section_open('workbook');
        ?>
        <h2 id="cadco-heading-workbook"><?php esc_html_e('Workbook', 'cadco-theme'); ?></h2>
        <table class="widefat striped cadco-import-workbook-summary">
            <tbody>
            <tr>
                <th scope="row"><?php esc_html_e('Filename', 'cadco-theme'); ?></th>
                <td><?php echo esc_html($workbook['filename']); ?></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Size', 'cadco-theme'); ?></th>
                <td><?php echo esc_html(size_format($workbook['size'])); ?></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Rows read', 'cadco-theme'); ?></th>
                <td><?php echo (int) $workbook['rows']; ?></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Validation', 'cadco-theme'); ?></th>
                <td>
                    <?php
                    printf(
                        /* translators: %d: number of validation issues found — always 0 here, since Review is only reached by a workbook that passed */
                        esc_html(_n('Passed — %d issue', 'Passed — %d issues', $workbook['issues'], 'cadco-theme')),
                        (int) $workbook['issues']
                    );
                    ?>
                </td>
            </tr>
            </tbody>
        </table>
        <h3><?php esc_html_e('Sheets', 'cadco-theme'); ?></h3>
        <table class="widefat striped">
            <thead><tr>
                <th><?php esc_html_e('Sheet', 'cadco-theme'); ?></th>
                <th><?php esc_html_e('Rows', 'cadco-theme'); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($workbook['sheets'] as $sheet) : ?>
                <tr>
                    <td><?php echo esc_html($sheet['name']); ?></td>
                    <td><?php echo (int) $sheet['rows']; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
        self::section_close();
    }

    /**
     * The Categories section (design spec §7) — the one genuinely new piece
     * of data this task adds. New terms as a tree for product_cat (parent →
     * child, each child carrying the number of products landing in it), flat
     * for the two flat taxonomies; removed terms; and the in-use warning,
     * rendered louder than everything else on this screen because it is the
     * one thing here that needs a person, not a nod.
     *
     * @param array<string, array{new: list<array<string, mixed>>, removed: list<array<string, mixed>>, in_use: list<array<string, mixed>>}> $term_diff
     * @param array{new:int, removed:int, in_use:int} $totals
     */
    private static function section_categories(array $term_diff, array $totals): void
    {
        self::section_open('categories');
        ?>
        <h2 id="cadco-heading-categories">
            <?php esc_html_e('Categories', 'cadco-theme'); ?>
            <span class="count<?php echo $totals['new'] === 0 ? ' is-zero' : ''; ?>"><?php echo (int) $totals['new']; ?></span>
        </h2>
        <p class="description">
            <?php
            printf(
                /* translators: 1: number of new terms, 2: number of removed terms */
                esc_html__('%1$d new · %2$d removed', 'cadco-theme'),
                (int) $totals['new'],
                (int) $totals['removed']
            );
            ?>
        </p>
        <?php
        $labels = [
            'product_cat'   => __('Product categories', 'cadco-theme'),
            'product_tag'   => __('Tags', 'cadco-theme'),
            'product_brand' => __('Brands', 'cadco-theme'),
        ];

        foreach ($labels as $taxonomy => $label) :
            $bucket = $term_diff[$taxonomy] ?? ['new' => [], 'removed' => [], 'in_use' => []];
            ?>
            <h3><?php echo esc_html($label); ?></h3>
            <p class="cadco-import-term-group-label"><?php esc_html_e('New', 'cadco-theme'); ?></p>
            <?php
            if ($taxonomy === 'product_cat') {
                self::category_tree($bucket['new']);
            } else {
                self::flat_term_list($bucket['new'], __('No new terms.', 'cadco-theme'));
            }
            ?>
            <p class="cadco-import-term-group-label"><?php esc_html_e('Removed (hold no products)', 'cadco-theme'); ?></p>
            <?php self::removed_term_list($bucket['removed']); ?>
            <?php self::in_use_term_list($bucket['in_use']); ?>
        <?php endforeach; ?>
        <?php
        self::section_close();
    }

    /**
     * The new product_cat tree: parent → children, each child carrying the
     * number of products that will land in it (design spec §7). Capped at 50
     * parents and 50 children per parent — generous against the real
     * workbook's 4 parents, but a table approving a plan must still say what
     * a cap hid rather than silently drop rows (task brief).
     *
     * @param list<array{name:string, parent:string, products:int, children:list<array{name:string,products:int}>}> $parents
     */
    private static function category_tree(array $parents): void
    {
        if ($parents === []) {
            printf('<p class="description">%s</p>', esc_html__('No new categories.', 'cadco-theme'));

            return;
        }
        ?>
        <ul class="cadco-import-term-tree">
            <?php foreach (array_slice($parents, 0, 50) as $parent) : ?>
                <li>
                    <span class="cadco-import-term-new">+ <?php echo esc_html($parent['name']); ?></span>
                    <ul>
                        <?php foreach (array_slice($parent['children'], 0, 50) as $child) : ?>
                            <li>
                                <span class="cadco-import-term-new">+ <?php echo esc_html($child['name']); ?></span>
                                <span class="cadco-import-term-count">
                                    <?php
                                    printf(
                                        /* translators: %d: number of products landing in this new category */
                                        esc_html(_n('%d item', '%d items', (int) $child['products'], 'cadco-theme')),
                                        (int) $child['products']
                                    );
                                    ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if (count($parent['children']) > 50) : ?>
                        <p class="description">
                            <?php printf(esc_html__('%d more not shown.', 'cadco-theme'), count($parent['children']) - 50); ?>
                        </p>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php if (count($parents) > 50) : ?>
            <p class="description">
                <?php printf(esc_html__('%d more not shown.', 'cadco-theme'), count($parents) - 50); ?>
            </p>
        <?php endif; ?>
        <?php
    }

    /**
     * @param list<array{name:string}> $items
     */
    private static function flat_term_list(array $items, string $empty): void
    {
        if ($items === []) {
            printf('<p class="description">%s</p>', esc_html($empty));

            return;
        }
        ?>
        <ul class="cadco-import-term-list">
            <?php foreach (array_slice($items, 0, 100) as $item) : ?>
                <li>+ <?php echo esc_html($item['name']); ?></li>
            <?php endforeach; ?>
        </ul>
        <?php if (count($items) > 100) : ?>
            <p class="description">
                <?php printf(esc_html__('%d more not shown.', 'cadco-theme'), count($items) - 100); ?>
            </p>
        <?php endif; ?>
        <?php
    }

    /**
     * @param list<array{name:string, term_id:int}> $items
     */
    private static function removed_term_list(array $items): void
    {
        if ($items === []) {
            printf('<p class="description">%s</p>', esc_html__('Nothing will be removed.', 'cadco-theme'));

            return;
        }
        ?>
        <ul class="cadco-import-term-list cadco-import-term-removed">
            <?php foreach (array_slice($items, 0, 100) as $item) : ?>
                <li>&minus; <?php echo esc_html($item['name']); ?></li>
            <?php endforeach; ?>
        </ul>
        <?php if (count($items) > 100) : ?>
            <p class="description">
                <?php printf(esc_html__('%d more not shown.', 'cadco-theme'), count($items) - 100); ?>
            </p>
        <?php endif; ?>
        <?php
    }

    /**
     * The loud block (design spec §7): a term the workbook no longer
     * implies, but which still holds products, so the applier will not
     * remove it. Rendered only when non-empty — the section's own zero state
     * already covers "nothing to see here" for the taxonomy as a whole.
     *
     * @param list<array{name:string, term_id:int, products:int}> $items
     */
    private static function in_use_term_list(array $items): void
    {
        if ($items === []) {
            return;
        }
        ?>
        <div class="cadco-import-in-use" role="note">
            <p class="cadco-import-in-use-heading">
                <?php esc_html_e('Still in use — will NOT be removed', 'cadco-theme'); ?>
            </p>
            <p class="description">
                <?php esc_html_e('These no longer appear in the workbook, but products still hold them, so the applier leaves them exactly as they are. Only a person can resolve this disagreement between the workbook and the site.', 'cadco-theme'); ?>
            </p>
            <ul>
                <?php foreach (array_slice($items, 0, 100) as $item) : ?>
                    <li>
                        <strong><?php echo esc_html($item['name']); ?></strong>
                        <?php
                        printf(
                            /* translators: %d: number of products still assigned to this term */
                            esc_html(_n('%d product', '%d products', (int) $item['products'], 'cadco-theme')),
                            (int) $item['products']
                        );
                        ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if (count($items) > 100) : ?>
                <p class="description">
                    <?php printf(esc_html__('%d more not shown.', 'cadco-theme'), count($items) - 100); ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function section_products(CADCO_Import_Plan $plan): void
    {
        self::section_open('products');
        self::create_table($plan);
        self::section_close();
    }

    private static function section_updates(CADCO_Import_Plan $plan): void
    {
        self::section_open('updates');
        self::update_table($plan);
        self::section_close();
    }

    private static function section_renames(CADCO_Import_Plan $plan): void
    {
        self::section_open('renames');
        self::renames_table($plan);
        self::section_close();
    }

    private static function section_removals(CADCO_Import_Plan $plan): void
    {
        self::section_open('removals');
        self::trash_table($plan);
        self::section_close();
    }

    private static function section_cleaned_up(array $changes): void
    {
        self::section_open('cleaned-up');
        self::cleaned_up_table($changes);
        self::section_close();
    }

    /**
     * The header count and the navigator badge (see review()) both read
     * from $forecast — how many redirects *this plan* will produce — not
     * from count($redirect_map), which is how many exist on the site right
     * now (see legacy_redirect_forecast()'s docblock for why that was
     * wrong on a first import). The table below $redirect_map still shows
     * the real, currently-downloadable map when one exists; the two are
     * different facts and both are worth showing, just not conflated into
     * one count.
     *
     * @param array<string, string> $redirect_map
     * @param array{will_export:int, skipped:list<string>, collisions:array<string,list<string>>} $forecast
     */
    private static function section_redirects(array $redirect_map, array $forecast): void
    {
        self::section_open('redirects');
        $will_export = $forecast['will_export'];
        ?>
        <h2 id="cadco-heading-redirects">
            <?php esc_html_e('Redirects', 'cadco-theme'); ?>
            <span class="count<?php echo $will_export === 0 ? ' is-zero' : ''; ?>"><?php echo (int) $will_export; ?></span>
        </h2>
        <?php if ($redirect_map === []) : ?>
            <p class="description"><?php esc_html_e('No redirects exist yet for this catalogue.', 'cadco-theme'); ?></p>
        <?php else : ?>
            <table class="widefat striped">
                <thead><tr>
                    <th><?php esc_html_e('Old path', 'cadco-theme'); ?></th>
                    <th><?php esc_html_e('New URL', 'cadco-theme'); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach (array_slice($redirect_map, 0, 200, true) as $from => $to) : ?>
                    <tr>
                        <td><code><?php echo esc_html((string) $from); ?></code></td>
                        <td><a href="<?php echo esc_url((string) $to); ?>"><?php echo esc_html((string) $to); ?></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php self::truncation_note(count($redirect_map)); ?>
        <?php endif; ?>
        <?php if ($redirect_map !== []) : ?>
            <p>
                <a class="button" href="<?php echo esc_url(wp_nonce_url(
                    admin_url('edit.php?post_type=product&page=cadco-import&action=export-redirects'),
                    CADCO_Import_Admin::NONCE
                )); ?>">
                    <?php esc_html_e('Download redirect map', 'cadco-theme'); ?>
                </a>
            </p>
        <?php endif; ?>
        <?php self::legacy_redirect_preview($forecast); ?>
        <?php
        self::section_close();
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
    private static function update_table(CADCO_Import_Plan $plan): void
    {
        $updates = $plan->updates();
        ?>
        <h2 id="cadco-heading-updates"><?php esc_html_e('Products to update', 'cadco-theme'); ?> <span class="count<?php echo $updates === [] ? ' is-zero' : ''; ?>"><?php echo count($updates); ?></span></h2>
        <?php
        if ($updates === []) {
            printf('<p class="description">%s</p>', esc_html__('No products need updating in this plan.', 'cadco-theme'));

            return;
        }

        $rows = [];

        foreach ($updates as $update) {
            $sku = (string) ($update['row']['Model #'] ?? '');

            if ($update['diff'] === []) {
                // Two different situations both leave diff() with nothing to
                // show, and they call for different messages: a snapshot
                // that simply doesn't exist yet (this product predates diff
                // tracking) is not the same claim as a snapshot that exists
                // but came back unreadable (see
                // CADCO_Import_Repository::current_products()) — the second
                // is exactly the state a corrupted stored value would leave
                // behind, and telling the operator it "predates diff
                // tracking" would be false.
                $rows[] = [
                    'sku'    => $sku,
                    'note'   => !empty($update['snapshot_unreadable']) ? 'unreadable' : 'no_snapshot',
                    'field'  => '',
                    'before' => '',
                    'after'  => '',
                ];
                continue;
            }

            foreach ($update['diff'] as $field => $pair) {
                $rows[] = [
                    'sku'    => $sku,
                    'note'   => null,
                    'field'  => (string) $field,
                    'before' => (string) $pair[0],
                    'after'  => (string) $pair[1],
                ];
            }
        }
        ?>
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
                    <?php if ($row['note'] === 'no_snapshot') : ?>
                        <td colspan="3"><em><?php esc_html_e('no previous snapshot — this product predates diff tracking', 'cadco-theme'); ?></em></td>
                    <?php elseif ($row['note'] === 'unreadable') : ?>
                        <td colspan="3"><em><?php esc_html_e('previous snapshot could not be read — it will be rebuilt on this import', 'cadco-theme'); ?></em></td>
                    <?php else : ?>
                        <td><?php echo esc_html($row['field']); ?></td>
                        <td><code><?php echo esc_html($row['before']); ?></code></td>
                        <td><code><?php echo esc_html($row['after']); ?></code></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php self::truncation_note(count($rows)); ?>
        <?php
    }

    /**
     * Model #, name, categories and brand for every product this plan will
     * create (design spec §6.2) — the categories/brand columns are new in
     * this task; the Model #/name pair already existed.
     */
    private static function create_table(CADCO_Import_Plan $plan): void
    {
        $creates = $plan->creates();
        ?>
        <h2 id="cadco-heading-products"><?php esc_html_e('Products to create', 'cadco-theme'); ?> <span class="count<?php echo $creates === [] ? ' is-zero' : ''; ?>"><?php echo count($creates); ?></span></h2>
        <?php
        if ($creates === []) {
            printf('<p class="description">%s</p>', esc_html__('No new products in this plan.', 'cadco-theme'));

            return;
        }
        ?>
        <table class="widefat striped">
            <thead><tr>
                <th><?php esc_html_e('Model #', 'cadco-theme'); ?></th>
                <th><?php esc_html_e('Product Name', 'cadco-theme'); ?></th>
                <th><?php esc_html_e('Categories', 'cadco-theme'); ?></th>
                <th><?php esc_html_e('Brand', 'cadco-theme'); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach (array_slice($creates, 0, 200) as $create) :
                $categories = array_map(
                    static fn (array $pair): string => $pair['parent'] . ' > ' . $pair['child'],
                    CADCO_Import_Plan::categories_for($create['row'])
                );
                ?>
                <tr>
                    <td><code><?php echo esc_html((string) ($create['row']['Model #'] ?? '')); ?></code></td>
                    <td><?php echo esc_html((string) ($create['row']['Product Name'] ?? '')); ?></td>
                    <td><?php echo esc_html(implode(', ', $categories)); ?></td>
                    <td><?php echo esc_html((string) ($create['row']['Brand Name'] ?? '')); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php self::truncation_note(count($creates)); ?>
        <?php
    }

    private static function renames_table(CADCO_Import_Plan $plan): void
    {
        $renames = $plan->renames();
        ?>
        <h2 id="cadco-heading-renames"><?php esc_html_e('Renames', 'cadco-theme'); ?> <span class="count<?php echo $renames === [] ? ' is-zero' : ''; ?>"><?php echo count($renames); ?></span></h2>
        <?php
        if ($renames === []) {
            printf('<p class="description">%s</p>', esc_html__('No renames in this plan.', 'cadco-theme'));

            return;
        }
        ?>
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
            <?php foreach (array_slice($renames, 0, 200) as $rename) : ?>
                <tr>
                    <td><input type="checkbox" class="cadco-rename" value="<?php echo esc_attr($rename['upc']); ?>" checked></td>
                    <td><code><?php echo esc_html($rename['old_sku']); ?></code></td>
                    <td><code><?php echo esc_html($rename['new_sku']); ?></code></td>
                    <td><?php echo esc_html($rename['upc']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php self::truncation_note(count($renames)); ?>
        <?php
    }

    private static function trash_table(CADCO_Import_Plan $plan): void
    {
        $trashes = $plan->trashes();
        ?>
        <h2 id="cadco-heading-removals"><?php esc_html_e('Products to trash', 'cadco-theme'); ?> <span class="count<?php echo $trashes === [] ? ' is-zero' : ''; ?>"><?php echo count($trashes); ?></span></h2>
        <?php
        if ($trashes === []) {
            printf('<p class="description">%s</p>', esc_html__('Nothing will be trashed in this plan.', 'cadco-theme'));

            return;
        }
        ?>
        <p class="description">
            <?php esc_html_e('These products are trashed, not deleted, and can be restored.', 'cadco-theme'); ?>
        </p>
        <table class="widefat striped">
            <thead><tr>
                <th><?php esc_html_e('Model #', 'cadco-theme'); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach (array_slice($trashes, 0, 200) as $trash) : ?>
                <tr>
                    <td><code><?php echo esc_html((string) $trash['sku']); ?></code></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php self::truncation_note(count($trashes)); ?>
        <?php
    }

    private static function cleaned_up_table(array $changes): void
    {
        ?>
        <h2 id="cadco-heading-cleaned-up"><?php esc_html_e('Values cleaned up automatically', 'cadco-theme'); ?> <span class="count<?php echo $changes === [] ? ' is-zero' : ''; ?>"><?php echo count($changes); ?></span></h2>
        <?php
        if ($changes === []) {
            printf('<p class="description">%s</p>', esc_html__('Nothing needed cleaning up in this plan.', 'cadco-theme'));

            return;
        }
        ?>
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
        <?php self::truncation_note(count($changes)); ?>
        <?php
    }

    /**
     * How many of the rows just validated carry a legacy URL that will
     * become a redirect once this plan is applied, which ones don't
     * resolve to a product page, and which resolved paths collide.
     *
     * This existed only as CADCO_Import_Admin::redirect_map()'s "existing
     * redirects on the site" count until review()'s navigator badge is what
     * caught the gap it left: redirect_map() derives from *published
     * products*, so on a first import — 0 products, the exact scenario this
     * whole feature exists for — it is empty and the badge read "0" while
     * the sentence directly underneath it said "236 legacy URLs will
     * redirect…". A section about to describe 236 forthcoming redirects
     * must not be the one section the navigator calls muted and marks
     * non-interactive. Design spec §6.1's own mock agrees: it shows
     * "Redirects 225" beside "Products +236" on what is unmistakably a
     * first-import plan — the only number that can be 225 there is this
     * forecast, not existing state.
     *
     * @param list<array<string, mixed>> $rows
     * @return array{will_export:int, skipped:list<string>, collisions:array<string,list<string>>}
     */
    private static function legacy_redirect_forecast(array $rows): array
    {
        $will_export = 0;
        $skipped     = [];
        $by_path     = [];

        foreach ($rows as $row) {
            $url = trim((string) ($row['Website URL'] ?? ''));

            if ($url === '') {
                continue;
            }

            $path  = CADCO_Import_Planner::legacy_path($url);
            $model = (string) ($row['Model #'] ?? '');

            if ($path === '') {
                $skipped[] = $model;
                continue;
            }

            $will_export++;
            $by_path[$path][] = $model;
        }

        $collisions = array_filter($by_path, static fn (array $models): bool => count($models) > 1);

        return ['will_export' => $will_export, 'skipped' => $skipped, 'collisions' => $collisions];
    }

    /**
     * Forecast, from the rows just validated, what the legacy half of the
     * redirect map (see CADCO_Import_Admin::redirect_map()) will contain once
     * this plan is applied — before anything is written, so the operator sees
     * it on the same screen as the rest of the plan rather than discovering
     * it only after the fact on the download.
     *
     * Two things are worth flagging here, both real findings in the actual
     * corrected workbook rather than hypothetical:
     *
     * - Two rows (VK-VH-FK, PS-TBS-HD) put a spec-sheet PDF in 'Website URL'
     *   instead of a product page — a data-entry error, not this system's to
     *   silently drop without a trace.
     * - Seven legacy paths are each claimed by two different products (e.g.
     *   '/product/xhc-020-p1' by both XHC-020-P1 and XHC-020-S1).
     *   redirect_map() is keyed by path, so a path claimed twice can only
     *   ever redirect to one of the two — the count exported is smaller than
     *   the count of rows with a valid-looking legacy URL for exactly this
     *   reason. Which one wins is no longer arbitrary — see below — but it
     *   is still a workbook-data problem worth CADCO's attention, so it is
     *   surfaced here rather than silently resolved with nothing said.
     *
     * The winner shown for each collision is computed the same way
     * CADCO_Import_Repository::legacy_urls()'s `ORDER BY sku ASC` resolves
     * it for the real export — the alphabetically-last Model # — so this
     * preview states the actual outcome, not just the conflict. It is
     * computed independently here (sorting the model numbers already
     * collected from $rows) rather than by calling redirect_map(), because
     * this preview runs before anything has been written to the database at
     * all; on a first import there is no product yet for legacy_urls() to
     * find.
     *
     * Both are named rather than just counted: with only a handful of each,
     * a list is more useful than a count, and it is what lets an operator
     * actually go fix (or explain) the workbook.
     *
     * The computation itself lives in legacy_redirect_forecast() so the
     * navigator badge and the Redirects section's own heading count can
     * share the exact same numbers this paragraph states, rather than each
     * computing "how many redirects" its own way and risking disagreement
     * (see review()/section_redirects()).
     *
     * @param array{will_export:int, skipped:list<string>, collisions:array<string,list<string>>} $forecast
     */
    private static function legacy_redirect_preview(array $forecast): void
    {
        $will_export = $forecast['will_export'];
        $skipped     = $forecast['skipped'];
        $collisions  = $forecast['collisions'];

        if ($will_export === 0 && $skipped === [] && $collisions === []) {
            return;
        }
        ?>
        <p class="description">
            <?php
            printf(
                /* translators: %d: number of legacy URLs that will become redirects once this plan is applied */
                esc_html(_n(
                    '%d legacy URL will redirect to its new product page once this plan is applied.',
                    '%d legacy URLs will redirect to their new product pages once this plan is applied.',
                    $will_export,
                    'cadco-theme'
                )),
                $will_export
            );
            ?>
        </p>
        <?php if ($skipped !== []) : ?>
            <p class="description">
                <?php
                printf(
                    /* translators: %s: comma-separated list of model numbers whose Website URL is not a product page */
                    esc_html__('Website URL is not a product page, so no redirect will be made for: %s', 'cadco-theme'),
                    esc_html(implode(', ', $skipped))
                );
                ?>
            </p>
        <?php endif; ?>
        <?php if ($collisions !== []) : ?>
            <p class="description">
                <?php
                printf(
                    /* translators: %d: number of legacy paths claimed by more than one product */
                    esc_html(_n(
                        '%d legacy URL is claimed by more than one product. A redirect can only point to one destination, so the alphabetically-last Model # wins — CADCO should confirm that is right:',
                        '%d legacy URLs are each claimed by more than one product. A redirect can only point to one destination, so the alphabetically-last Model # wins in each case — CADCO should confirm that is right:',
                        count($collisions),
                        'cadco-theme'
                    )),
                    count($collisions)
                );
                ?>
            </p>
            <ul>
                <?php foreach (array_slice($collisions, 0, 20, true) as $path => $models) : ?>
                    <?php
                    // Mirrors legacy_urls()'s 'ORDER BY sku ASC' + redirect_map()'s
                    // last-write-wins merge: sorted ascending, the last
                    // element is the one whose permalink the real export
                    // will use.
                    $ranked = $models;
                    sort($ranked);
                    $winner = (string) end($ranked);
                    ?>
                    <li>
                        <code><?php echo esc_html($path); ?></code>
                        — <?php echo esc_html(implode(', ', $models)); ?>
                        — <strong><?php echo esc_html($winner); ?></strong>
                        <?php esc_html_e('wins', 'cadco-theme'); ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if (count($collisions) > 20) : ?>
                <p class="description">
                    <?php
                    printf(
                        /* translators: %d: number of collisions not shown */
                        esc_html__('%d more not shown.', 'cadco-theme'),
                        count($collisions) - 20
                    );
                    ?>
                </p>
            <?php endif; ?>
        <?php endif; ?>
        <?php
    }

    /**
     * A silent cap on a plan the operator is approving would be worse than no
     * cap at all — so whenever array_slice(..., 0, 200) actually cut
     * something, say how many rows were hidden.
     */
    private static function truncation_note(int $total): void
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

    public static function notice(string $type, string $message): void
    {
        printf(
            '<div class="notice notice-%s"><p>%s</p></div>',
            esc_attr($type),
            esc_html($message)
        );
    }
}
