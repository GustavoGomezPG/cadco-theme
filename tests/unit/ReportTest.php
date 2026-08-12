<?php

declare(strict_types=1);

namespace CADCO\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ReportTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../inc/import/class-cadco-import-issue.php';
        require_once __DIR__ . '/../../inc/import/class-cadco-import-report.php';
    }

    public function test_an_empty_report_passes(): void
    {
        $report = new \CADCO_Import_Report();

        self::assertTrue($report->passed());
        self::assertSame(0, $report->count());
    }

    public function test_any_issue_in_any_tier_fails_the_report(): void
    {
        // The policy is all-or-nothing: a Tier C completeness gap blocks the
        // import exactly as hard as a Tier A duplicate identifier.
        $report = new \CADCO_Import_Report();
        $report->add(new \CADCO_Import_Issue('C', 'CONVECTION OVENS', 49, 'Wattage', '', 'Blank.', 'Write n/a'));

        self::assertFalse($report->passed());
        self::assertSame(1, $report->count());
    }

    public function test_issues_group_by_tier_in_order(): void
    {
        $report = new \CADCO_Import_Report();
        $report->add(new \CADCO_Import_Issue('C', 'S', 3, 'Wattage', '', 'c', ''));
        $report->add(new \CADCO_Import_Issue('A', 'S', 1, 'UPC#', 'x', 'a', ''));
        $report->add(new \CADCO_Import_Issue('B', 'S', 2, 'Color', 'Grey', 'b', ''));

        self::assertSame(['A', 'B', 'C'], array_keys($report->by_tier()));
        self::assertSame(['A' => 1, 'B' => 1, 'C' => 1], $report->tier_counts());
    }

    public function test_csv_has_a_header_and_one_line_per_issue(): void
    {
        $report = new \CADCO_Import_Report();
        $report->add(new \CADCO_Import_Issue('A', 'CONVECTION OVENS', 82, 'UPC#', '654796-55145-3', 'Duplicate UPC.', 'Assign a unique UPC'));

        $lines = array_values(array_filter(explode("\n", trim($report->to_csv()))));

        self::assertCount(2, $lines);
        self::assertStringContainsString('Tier', $lines[0]);
        self::assertStringContainsString('654796-55145-3', $lines[1]);
    }

    public function test_csv_escapes_embedded_commas_and_quotes(): void
    {
        $report = new \CADCO_Import_Report();
        $report->add(new \CADCO_Import_Issue('B', 'S', 1, 'Specialties', 'Kitchen Equipment"', 'Variant, spelled two ways.', ''));

        $csv = $report->to_csv();

        self::assertStringContainsString('"Kitchen Equipment"""', $csv);
        self::assertStringContainsString('"Variant, spelled two ways."', $csv);
    }
}
