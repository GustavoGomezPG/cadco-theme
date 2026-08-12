<?php

declare(strict_types=1);

namespace CADCO\Tests\Unit;

use CADCO\Tests\Fixtures\FixtureBuilder;
use PHPUnit\Framework\TestCase;

final class ReaderTest extends TestCase
{
    private string $path = '';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../inc/import/field-map.php';
        require_once __DIR__ . '/../../inc/import/class-cadco-import-reader.php';
    }

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/cadco-reader-' . uniqid() . '.xlsx';
    }

    protected function tearDown(): void
    {
        if ($this->path !== '' && file_exists($this->path)) {
            unlink($this->path);
        }
    }

    public function test_reads_rows_keyed_by_header_name(): void
    {
        FixtureBuilder::write($this->path, [
            'CONVECTION OVENS' => [FixtureBuilder::row()],
        ]);

        $result = \CADCO_Import_Reader::read($this->path);

        self::assertSame([], $result['errors']);
        self::assertCount(1, $result['rows']);
        self::assertSame('BLC-113', $result['rows'][0]['Model #']);
        self::assertSame('CONVECTION OVENS', $result['rows'][0]['__sheet']);
        self::assertSame(2, $result['rows'][0]['__row']);
    }

    public function test_column_order_may_differ_between_sheets(): void
    {
        // CONVECTION OVENS carries a leading Notes column; FAST COOKING OVENS
        // does not and carries a trailing Other column instead. This is the
        // real shape of the workbook, and it is why columns are read by name.
        FixtureBuilder::write($this->path, [
            'CONVECTION OVENS'   => [['Notes' => 'internal'] + FixtureBuilder::row()],
            'FAST COOKING OVENS' => [FixtureBuilder::row([
                'Model #' => 'VK-SK',
                'UPC#'    => '654796-54400-4',
            ]) + ['Other' => '']],
        ]);

        $result = \CADCO_Import_Reader::read($this->path);

        self::assertSame([], $result['errors']);
        self::assertCount(2, $result['rows']);

        $bySku = array_column($result['rows'], null, 'Model #');
        self::assertSame('internal', $bySku['BLC-113']['Notes']);
        self::assertSame('', $bySku['VK-SK']['Notes'] ?? '');
    }

    public function test_sheets_starting_with_underscore_are_skipped(): void
    {
        FixtureBuilder::write($this->path, [
            'CONVECTION OVENS' => [FixtureBuilder::row()],
            '_CORRECTIONS'     => [['Sheet' => 'CONVECTION OVENS', 'Why' => 'test']],
        ]);

        $result = \CADCO_Import_Reader::read($this->path);

        self::assertSame([], $result['errors']);
        self::assertCount(1, $result['rows']);
    }

    public function test_an_unrecognised_sheet_is_a_tier_a_error(): void
    {
        FixtureBuilder::write($this->path, [
            'CONVECTION OVENS' => [FixtureBuilder::row()],
            'LIST PRICING'     => [['Model #' => 'BLC-113', 'Price' => '100']],
        ]);

        $result = \CADCO_Import_Reader::read($this->path);

        self::assertCount(1, $result['errors']);
        self::assertSame('A', $result['errors'][0]['tier']);
        self::assertStringContainsString('LIST PRICING', $result['errors'][0]['message']);
    }

    public function test_a_missing_required_header_is_a_tier_a_error(): void
    {
        $row = FixtureBuilder::row();
        unset($row['UPC#']);

        FixtureBuilder::write($this->path, ['CONVECTION OVENS' => [$row]]);

        $result = \CADCO_Import_Reader::read($this->path);

        self::assertNotSame([], $result['errors']);
        self::assertStringContainsString('UPC#', $result['errors'][0]['message']);
    }

    public function test_blank_rows_are_skipped_and_values_are_trimmed(): void
    {
        FixtureBuilder::write($this->path, [
            'CONVECTION OVENS' => [
                FixtureBuilder::row(['Lead Time' => '  1-3 business days  ']),
                array_map(static fn () => '', FixtureBuilder::row()),
            ],
        ]);

        $result = \CADCO_Import_Reader::read($this->path);

        self::assertCount(1, $result['rows']);
        self::assertSame('1-3 business days', $result['rows'][0]['Lead Time']);
    }

    public function test_a_missing_file_is_reported_not_fatal(): void
    {
        $result = \CADCO_Import_Reader::read('/no/such/file.xlsx');

        self::assertSame([], $result['rows']);
        self::assertNotSame([], $result['errors']);
    }
}
