<?php

declare(strict_types=1);

namespace CADCO\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ArchiveTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../inc/import/class-cadco-import-archive.php';
    }

    public function test_a_well_formed_run_id_is_accepted(): void
    {
        self::assertTrue(\CADCO_Import_Archive::is_valid_run_id('2026-08-12-091412-1-a7Kd93mQx0Lp'));
    }

    public function test_traversal_and_malformed_ids_are_rejected(): void
    {
        foreach ([
            '../../../etc/passwd',
            '2026-08-12-091412-1-a7Kd93mQx0Lp/../..',
            '2026-08-12-091412-1-short',
            '2026-08-12-091412-1-a7Kd93mQx0Lp.txt',
            'not-a-run-id',
            '',
            '2026-08-12-091412-1-a7Kd93mQx0L/',
        ] as $bad) {
            self::assertFalse(\CADCO_Import_Archive::is_valid_run_id($bad), "must reject: $bad");
        }
    }
}
