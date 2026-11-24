<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * What the Apply screen says while a run is in progress: which phase it is
 * in, how far through that phase, and the job it is working on next.
 */
final class ApplierProgressTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../wp-stubs.php';
        require_once __DIR__ . '/../../inc/import/field-map.php';
        require_once __DIR__ . '/../../inc/import/class-cadco-import-plan.php';
        require_once __DIR__ . '/../../inc/import/class-cadco-import-applier.php';
    }

    /** @return list<array<string, mixed>> */
    private function queue(): array
    {
        return [
            ['op' => 'trash', 'post_id' => 9, 'sku' => 'OLD-1'],
            ['op' => 'create', 'row' => ['Model #' => 'NEW-1'], 'post_id' => null],
            ['op' => 'update', 'row' => ['Model #' => 'CAP-F'], 'post_id' => 4],
            ['op' => 'rename', 'row' => ['Model #' => 'VK-120+'], 'post_id' => 5, 'old_sku' => 'VK-120'],
            ['op' => 'untrash', 'row' => ['Model #' => 'BACK-1'], 'post_id' => 6],
            ['op' => 'media-fetch', 'key' => 'k1', 'fetch' => 'x', 'url' => 'x', 'roles' => ['manual'], 'models' => ['CAP-F', 'CAP-H', 'CAP-Q']],
            ['op' => 'media-fetch', 'key' => 'k2', 'fetch' => 'x', 'url' => 'x', 'roles' => ['spec-sheet', 'image'], 'models' => ['CPG-15FC']],
            ['op' => 'media-products', 'products' => [['model' => 'A'], ['model' => 'B'], ['model' => 'C']]],
            ['op' => 'media-resources', 'entries' => [], 'only' => ['a', 'b']],
            ['op' => 'media-cleanup', 'entries' => []],
        ];
    }

    public function test_each_job_says_what_it_does(): void
    {
        $labels = array_map(
            static fn (array $job): string => CADCO_Import_Applier::describe_job($job)['label'],
            $this->queue()
        );

        self::assertSame([
            'Moving OLD-1 to the trash',
            'Creating NEW-1',
            'Updating CAP-F',
            'Renaming VK-120 to VK-120+',
            'Restoring BACK-1',
            'Downloading the manual for CAP-F, CAP-H and CAP-Q',
            'Downloading the spec sheet and image for CPG-15FC',
            'Setting product images for A to C (3 products)',
            'linking 2 documents and videos to their products',
            'Removing resources the workbook no longer lists',
        ], $labels);
    }

    public function test_many_models_are_summarised(): void
    {
        $job = ['op' => 'media-fetch', 'roles' => ['manual'], 'models' => ['A', 'B', 'C', 'D', 'E']];

        self::assertSame(
            'Downloading the manual for A, B and 3 more',
            CADCO_Import_Applier::describe_job($job)['label']
        );
    }

    public function test_jobs_fall_into_phases(): void
    {
        $phases = array_map(
            static fn (array $job): string => CADCO_Import_Applier::describe_job($job)['phase'],
            $this->queue()
        );

        self::assertSame(
            ['products', 'products', 'products', 'products', 'products', 'files', 'files', 'images', 'resources', 'cleanup'],
            $phases
        );
    }

    public function test_progress_names_the_next_job_and_counts_within_its_phase(): void
    {
        $p = CADCO_Import_Applier::progress($this->queue(), 6);

        self::assertSame('files', $p['phase']);
        self::assertSame('Downloading files', $p['phase_label']);
        self::assertSame(1, $p['phase_done']);
        self::assertSame(2, $p['phase_total']);
        self::assertSame('Downloading the spec sheet and image for CPG-15FC', $p['next']);

        // Every phase present in the queue, in run order, with its own done count.
        self::assertSame(['products', 'files', 'images', 'resources', 'cleanup'], array_column($p['phases'], 'key'));
        self::assertSame([5, 1, 0, 0, 0], array_column($p['phases'], 'done'));
        self::assertSame([5, 2, 1, 1, 1], array_column($p['phases'], 'total'));
    }

    public function test_progress_at_the_end_has_no_next_job(): void
    {
        $p = CADCO_Import_Applier::progress($this->queue(), 10);

        self::assertSame('', $p['next']);
        self::assertSame('done', $p['phase']);
        self::assertSame([5, 2, 1, 1, 1], array_column($p['phases'], 'done'));
    }

    public function test_phases_absent_from_the_queue_are_left_out(): void
    {
        $queue = [['op' => 'update', 'row' => ['Model #' => 'A'], 'post_id' => 1]];

        $p = CADCO_Import_Applier::progress($queue, 0);

        self::assertSame(['products'], array_column($p['phases'], 'key'));
        self::assertSame('Updating products', $p['phase_label']);
    }
}
