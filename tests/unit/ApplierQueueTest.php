<?php

declare(strict_types=1);

namespace CADCO\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The order build_queue() emits jobs in.
 *
 * Only the queue is tested here; running the jobs needs WordPress and is
 * covered end to end.
 */
final class ApplierQueueTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../wp-stubs.php';
        require_once __DIR__ . '/../../inc/import/field-map.php';
        require_once __DIR__ . '/../../inc/import/class-cadco-import-plan.php';
        require_once __DIR__ . '/../../inc/import/class-cadco-import-applier.php';
    }

    public function test_trashes_run_before_creates_updates_and_renames(): void
    {
        $plan = new \CADCO_Import_Plan();
        $plan->add_create(['Model #' => 'NEW-1']);
        $plan->add_update(['Model #' => 'UPD-1'], 11, []);
        $plan->add_rename(['Model #' => 'REN-NEW', 'UPC#' => '654796-00001-1'], 12, 'REN-OLD');
        $plan->approve_rename(0);
        $plan->add_trash(13, 'GONE-1');

        $ops = array_column(\CADCO_Import_Applier::build_queue($plan), 'op');

        self::assertSame(['trash', 'create', 'update', 'rename'], $ops);
    }

    public function test_an_unapproved_rename_is_left_out_of_the_queue(): void
    {
        $plan = new \CADCO_Import_Plan();
        $plan->add_rename(['Model #' => 'REN-NEW', 'UPC#' => '654796-00001-1'], 12, 'REN-OLD');

        self::assertSame([], \CADCO_Import_Applier::build_queue($plan));
    }
}
