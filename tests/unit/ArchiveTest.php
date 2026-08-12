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

    /**
     * Pins the `^` anchor specifically: without it, a leading path
     * component such as "../../../" or "x" in front of an otherwise
     * well-formed run id still matches, because preg_match() without `^`
     * only requires the pattern to match SOMEWHERE in the subject — this is
     * a genuine traversal, not a shape nitpick.
     */
    public function test_a_prefixed_id_is_rejected(): void
    {
        self::assertFalse(\CADCO_Import_Archive::is_valid_run_id('x2026-08-12-091412-1-a7Kd93mQx0Lp'));
        self::assertFalse(\CADCO_Import_Archive::is_valid_run_id('../../../2026-08-12-091412-1-a7Kd93mQx0Lp'));
    }

    /**
     * Pins the `D` modifier specifically: PCRE's `$` matches at
     * end-of-subject OR immediately before a single trailing "\n" unless
     * `D` is set, so an otherwise well-formed id with a trailing newline
     * would pass without it.
     */
    public function test_a_trailing_newline_is_rejected(): void
    {
        self::assertFalse(\CADCO_Import_Archive::is_valid_run_id("2026-08-12-091412-1-a7Kd93mQx0Lp\n"));
    }

    /**
     * Near misses: rejecting these is the anchored pattern's actual job.
     * Each one differs from a well-formed id by exactly the kind of small
     * shape change a bug in the pattern could quietly let through.
     */
    public function test_near_misses_are_rejected(): void
    {
        foreach ([
            '2026-08-12-091412-1-a7Kd93mQx0'      => '11-character suffix',
            '2026-08-12-091412-1-a7Kd93mQx0Lpx'    => '13-character suffix',
            '2026-08-12-091412-1-a7Kd93mQx0Lp.bak' => 'trailing .bak extension',
            '2026-08-12-091412-1-a7Kd93mQx0Lp.'    => 'trailing dot',
        ] as $bad => $why) {
            self::assertFalse(\CADCO_Import_Archive::is_valid_run_id($bad), "must reject ($why): $bad");
        }
    }
}
