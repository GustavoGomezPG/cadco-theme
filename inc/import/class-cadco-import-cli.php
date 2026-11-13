<?php

/**
 * The importer on the command line.
 *
 *   wp cadco-import check <workbook.xlsx>
 *   wp cadco-import run   <workbook.xlsx> [--approve-renames=<upcs|all>] [--refresh-media] [--skip-media] [--yes]
 *   wp cadco-import media <workbook.xlsx> [--refresh]
 *
 * The same pipeline as Products > Import, unit for unit: read, normalise,
 * validate, plan, apply, media. Nothing here decides anything the admin
 * screen does not — it exists so an import can be run where a browser cannot
 * wait (a large first media download, a server shell, a test suite), and so a
 * run is scriptable end to end.
 *
 * A run is archived exactly like one started from the screen, so it appears
 * in the History tab and can be restored from there.
 */

declare(strict_types=1);

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

final class CADCO_Import_CLI
{
    /**
     * Check a workbook: validation, the plan, and what the media stage would
     * do. Writes nothing.
     *
     * ## OPTIONS
     *
     * <file>
     * : Path to the product workbook (.xlsx).
     *
     * [--notices]
     * : List every media notice, not just the counts.
     *
     * ## EXAMPLES
     *
     *     wp cadco-import check "Product Index Spreadsheet.xlsx"
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function check(array $args, array $assoc): void
    {
        $result = $this->pipeline($args[0] ?? '');

        if (!$result['report']->passed()) {
            $this->print_issues($result['report']);
            WP_CLI::error(sprintf('The workbook has %d issue(s). Nothing can be imported until they are fixed.', $result['report']->count()));
        }

        $this->print_plan($result['plan']);

        $media = CADCO_Import_Media_Plan::build($result['rows']);
        $this->print_media($media, CADCO_Import_Media_Applier::preview($media), isset($assoc['notices']));

        WP_CLI::success('The workbook is valid. Nothing was written.');
    }

    /**
     * Import a workbook: products, then media.
     *
     * ## OPTIONS
     *
     * <file>
     * : Path to the product workbook (.xlsx).
     *
     * [--approve-renames=<upcs>]
     * : Comma-separated UPCs of the renames to apply, or "all". Renames are
     * never applied without approval.
     *
     * [--refresh-media]
     * : Download every file again and pick up any CADCO replaced behind the
     * same link.
     *
     * [--skip-media]
     * : Products only.
     *
     * [--yes]
     * : Do not ask for confirmation.
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function run(array $args, array $assoc): void
    {
        $path   = $this->workbook($args[0] ?? '');
        $result = $this->pipeline($path);

        if (!$result['report']->passed() || $result['plan'] === null) {
            $this->print_issues($result['report']);
            WP_CLI::error(sprintf('The workbook has %d issue(s). Nothing was written.', $result['report']->count()));
        }

        $plan = $result['plan'];
        $this->approve($plan, (string) ($assoc['approve-renames'] ?? ''));
        $this->print_plan($plan);

        $skip_media = isset($assoc['skip-media']);
        $media      = CADCO_Import_Media_Plan::build($result['rows']);

        if (!$skip_media) {
            $this->print_media($media, CADCO_Import_Media_Applier::preview($media), false);
        }

        WP_CLI::confirm('Apply this import?', $assoc);

        $archive = $this->archive($path, $result);

        CADCO_Import_Applier::prepare_terms($result['rows']);

        $queue = $skip_media
            ? CADCO_Import_Applier::build_queue($plan)
            : CADCO_Import_Applier::build_run_queue($plan, $result['rows'], isset($assoc['refresh-media']));

        $failures = $this->apply($queue);

        foreach (CADCO_Import_Applier::finalise() as $removed) {
            WP_CLI::log('Removed unused term: ' . $removed);
        }

        CADCO_Import_Archive::mark_applied($archive['dir']);
        WP_CLI::log('Archived as run ' . $archive['run_id']);

        $this->finish($failures);
    }

    /**
     * Run only the media stage for a workbook the catalogue already matches —
     * to retry downloads that failed, or to refresh files.
     *
     * ## OPTIONS
     *
     * <file>
     * : Path to the product workbook (.xlsx).
     *
     * [--refresh]
     * : Download every file again and pick up any CADCO replaced behind the
     * same link.
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function media(array $args, array $assoc): void
    {
        $result = $this->pipeline($args[0] ?? '');

        if (!$result['report']->passed()) {
            $this->print_issues($result['report']);
            WP_CLI::error('The workbook does not validate.');
        }

        $media = CADCO_Import_Media_Plan::build($result['rows']);
        $this->print_media($media, CADCO_Import_Media_Applier::preview($media), false);

        $this->finish($this->apply(CADCO_Import_Media_Applier::queue($media, isset($assoc['refresh']))));
    }

    /**
     * @return array{report:CADCO_Import_Report,plan:?CADCO_Import_Plan,rows:array,changes:array}
     */
    private function pipeline(string $path): array
    {
        $path       = $this->workbook($path);
        $read       = CADCO_Import_Reader::read($path);
        $normalised = CADCO_Import_Normaliser::normalise($read['rows']);
        $report     = CADCO_Import_Validator::validate($normalised['rows'], $read['errors']);

        return [
            'report'  => $report,
            'plan'    => $report->passed()
                ? CADCO_Import_Planner::plan($normalised['rows'], CADCO_Import_Repository::current_products())
                : null,
            'rows'    => $normalised['rows'],
            'changes' => $normalised['changes'],
        ];
    }

    private function workbook(string $path): string
    {
        if ($path === '' || !is_readable($path)) {
            WP_CLI::error('Pass the path of a readable workbook.');
        }

        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'xlsx') {
            WP_CLI::error('The workbook must be an .xlsx file.');
        }

        return (string) realpath($path);
    }

    private function approve(CADCO_Import_Plan $plan, string $approved): void
    {
        $wanted = array_filter(array_map('trim', explode(',', $approved)));

        foreach ($plan->renames() as $i => $rename) {
            if ($approved === 'all' || in_array((string) $rename['upc'], $wanted, true)) {
                $plan->approve_rename((int) $i);
            } else {
                WP_CLI::warning(sprintf(
                    'Rename %s -> %s (UPC %s) is not approved and will not be applied. Pass --approve-renames=%s to apply it.',
                    $rename['old_sku'],
                    $rename['new_sku'],
                    $rename['upc'],
                    $rename['upc']
                ));
            }
        }
    }

    /**
     * @param array{report:CADCO_Import_Report,plan:?CADCO_Import_Plan,rows:array,changes:array} $result
     * @return array{run_id:string, dir:string}
     */
    private function archive(string $path, array $result): array
    {
        $archive = CADCO_Import_Archive::create(get_current_user_id());

        copy($path, $archive['dir'] . '/workbook.xlsx');
        CADCO_Import_Archive::write_run($archive, $result, basename($path));
        CADCO_Import_Archive::prune(20);

        return $archive;
    }

    /**
     * @param list<array<string, mixed>> $queue
     * @return array{products:list<string>,media:list<string>}
     */
    private function apply(array $queue): array
    {
        $failures = ['products' => [], 'media' => []];
        $total    = count($queue);
        $offset   = 0;

        while ($offset < $total) {
            $batch = CADCO_Import_Applier::apply_jobs($queue, $offset, 25);

            foreach ($batch['log'] as $line) {
                if (str_starts_with($line, 'FAILED')) {
                    $failures['products'][] = $line;
                    WP_CLI::warning($line);
                } elseif (str_starts_with($line, 'MEDIA FAILED')) {
                    $failures['media'][] = $line;
                    WP_CLI::warning($line);
                } else {
                    WP_CLI::log($line);
                }
            }

            // Product and media caches are per request on the web; here the
            // whole run is one process, so the object cache would otherwise
            // grow with every product written.
            if (function_exists('wp_cache_flush_runtime')) {
                wp_cache_flush_runtime();
            }

            $offset = $batch['done'];
            WP_CLI::log(sprintf('— %d / %d', $offset, $total));
        }

        return $failures;
    }

    /**
     * @param array{products:list<string>,media:list<string>} $failures
     */
    private function finish(array $failures): void
    {
        if ($failures['products'] !== []) {
            WP_CLI::error(sprintf('%d product(s) failed to import. See the lines above.', count($failures['products'])));
        }

        if ($failures['media'] !== []) {
            WP_CLI::warning(sprintf('%d media item(s) could not be imported. The products are in; run "wp cadco-import media" once the links are fixed.', count($failures['media'])));
        }

        WP_CLI::success('Import complete.');
    }

    private function print_issues(CADCO_Import_Report $report): void
    {
        foreach (array_slice($report->all(), 0, 50) as $issue) {
            WP_CLI::log(sprintf('[%s] %s row %s, %s: %s %s', $issue->tier, $issue->sheet, $issue->row ?? '-', $issue->column, $issue->message, $issue->fix));
        }

        if ($report->count() > 50) {
            WP_CLI::log(sprintf('... and %d more.', $report->count() - 50));
        }
    }

    private function print_plan(?CADCO_Import_Plan $plan): void
    {
        if ($plan === null) {
            return;
        }

        $c = $plan->counts();
        WP_CLI::log(sprintf(
            'Products: %d to create, %d to update, %d to rename, %d to trash, %d to restore, %d unchanged.',
            $c['create'],
            $c['update'],
            $c['rename'],
            $c['trash'],
            $c['untrash'],
            $c['skip']
        ));
    }

    /**
     * @param array{downloads:int,images:int,resources_new:int,resources_changed:int,resources_removed:int} $preview
     */
    private function print_media(CADCO_Import_Media_Plan $media, array $preview, bool $list): void
    {
        $c = $media->counts();

        WP_CLI::log(sprintf(
            'Media: %d files cited (%d to download), %d videos, %d resources (%d new, %d changed, %d to remove), product images changing on %d existing products.',
            $c['files'],
            $preview['downloads'],
            $c['videos'],
            $c['resources'],
            $preview['resources_new'],
            $preview['resources_changed'],
            $preview['resources_removed'],
            $preview['images']
        ));

        if ($c['notices'] === 0) {
            return;
        }

        WP_CLI::log(sprintf('%d media link(s) cannot be used and are skipped (they do not block the import).', $c['notices']));

        if ($list) {
            foreach ($media->notices() as $notice) {
                WP_CLI::log(sprintf('  %s row %s, %s: %s', $notice->sheet, $notice->row ?? '-', $notice->column, $notice->message));
            }
        }
    }
}

WP_CLI::add_command('cadco-import', CADCO_Import_CLI::class);
