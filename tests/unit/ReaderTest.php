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
        FixtureBuilder::write($this->path, FixtureBuilder::completeSheets([
            'CONVECTION | COOK & HOLD OVENS' => [FixtureBuilder::row()],
        ]));

        $result = \CADCO_Import_Reader::read($this->path);

        self::assertSame([], $result['errors']);
        self::assertCount(4, $result['rows']);

        $bySku = array_column($result['rows'], null, 'Model #');
        self::assertSame('BLC-113', $bySku['BLC-113']['Model #']);
        self::assertSame('CONVECTION | COOK & HOLD OVENS', $bySku['BLC-113']['__sheet']);
        self::assertSame(2, $bySku['BLC-113']['__row']);
    }

    public function test_column_order_may_differ_between_sheets(): void
    {
        // CONVECTION OVENS carries a leading Notes column; FAST COOKING OVENS
        // does not and carries a trailing Other column instead. This is the
        // real shape of the workbook, and it is why columns are read by name.
        FixtureBuilder::write($this->path, FixtureBuilder::completeSheets([
            'CONVECTION | COOK & HOLD OVENS'   => [['Notes' => 'internal'] + FixtureBuilder::row()],
            'FAST COOKING OVENS' => [FixtureBuilder::row([
                'Model #' => 'VK-SK',
                'UPC#'    => '654796-54400-4',
            ]) + ['Other' => '']],
        ]));

        $result = \CADCO_Import_Reader::read($this->path);

        self::assertSame([], $result['errors']);
        self::assertCount(4, $result['rows']);

        $bySku = array_column($result['rows'], null, 'Model #');
        self::assertSame('internal', $bySku['BLC-113']['Notes']);
        self::assertSame('', $bySku['VK-SK']['Notes'] ?? '');
    }

    public function test_sheets_starting_with_underscore_are_skipped(): void
    {
        FixtureBuilder::write($this->path, FixtureBuilder::completeSheets([
            'CONVECTION | COOK & HOLD OVENS' => [FixtureBuilder::row()],
            '_CORRECTIONS'     => [['Sheet' => 'CONVECTION | COOK & HOLD OVENS', 'Why' => 'test']],
        ]));

        $result = \CADCO_Import_Reader::read($this->path);

        self::assertSame([], $result['errors']);
        self::assertCount(4, $result['rows']);
    }

    public function test_an_unrecognised_sheet_is_a_tier_a_error(): void
    {
        FixtureBuilder::write($this->path, FixtureBuilder::completeSheets([
            'LIST PRICING' => [['Model #' => 'BLC-113', 'Price' => '100']],
        ]));

        $result = \CADCO_Import_Reader::read($this->path);

        self::assertCount(1, $result['errors']);
        self::assertSame('A', $result['errors'][0]['tier']);
        self::assertStringContainsString('LIST PRICING', $result['errors'][0]['message']);
    }

    public function test_a_missing_expected_sheet_is_a_tier_a_error(): void
    {
        $sheets = FixtureBuilder::completeSheets();
        unset($sheets['FOODSERVICE CARTS']);

        FixtureBuilder::write($this->path, $sheets);

        $result = \CADCO_Import_Reader::read($this->path);

        self::assertCount(1, $result['errors']);
        self::assertSame('A', $result['errors'][0]['tier']);
        self::assertStringContainsString('FOODSERVICE CARTS', $result['errors'][0]['message']);
    }

    public function test_a_missing_required_header_is_a_tier_a_error(): void
    {
        $row = FixtureBuilder::row();
        unset($row['UPC#']);

        FixtureBuilder::write($this->path, ['CONVECTION | COOK & HOLD OVENS' => [$row]]);

        $result = \CADCO_Import_Reader::read($this->path);

        self::assertNotSame([], $result['errors']);
        self::assertStringContainsString('UPC#', $result['errors'][0]['message']);
    }

    public function test_blank_rows_are_skipped_and_values_are_trimmed(): void
    {
        FixtureBuilder::write($this->path, FixtureBuilder::completeSheets([
            'CONVECTION | COOK & HOLD OVENS' => [
                FixtureBuilder::row(['Lead Time' => '  1-3 business days  ']),
                array_map(static fn () => '', FixtureBuilder::row()),
            ],
        ]));

        $result = \CADCO_Import_Reader::read($this->path);

        self::assertSame([], $result['errors']);

        $convection = array_values(array_filter(
            $result['rows'],
            static fn (array $row): bool => $row['__sheet'] === 'CONVECTION | COOK & HOLD OVENS'
        ));

        self::assertCount(1, $convection);
        self::assertSame('1-3 business days', $convection[0]['Lead Time']);
    }

    public function test_a_missing_file_is_reported_not_fatal(): void
    {
        $result = \CADCO_Import_Reader::read('/no/such/file.xlsx');

        self::assertSame([], $result['rows']);
        self::assertNotSame([], $result['errors']);
    }

    public function test_a_former_tab_name_is_read_under_its_canonical_name(): void
    {
        // CADCO retitled this tab in the 12 August revision. An older
        // workbook, or a reverted one, must still import.
        $sheets = FixtureBuilder::completeSheets();
        $sheets['CONVECTION OVENS'] = $sheets['CONVECTION | COOK & HOLD OVENS'];
        unset($sheets['CONVECTION | COOK & HOLD OVENS']);

        FixtureBuilder::write($this->path, $sheets);

        $result = \CADCO_Import_Reader::read($this->path);

        self::assertSame([], $result['errors'], 'a former tab name is not an error');

        $sheetNames = array_unique(array_column($result['rows'], '__sheet'));
        self::assertContains('CONVECTION | COOK & HOLD OVENS', $sheetNames);
        self::assertNotContains('CONVECTION OVENS', $sheetNames, 'rows carry the canonical name');
    }

    public function test_a_column_alias_is_read_under_its_canonical_heading(): void
    {
        // The convection sheet spells it 'Install / Manual URL'; every other
        // sheet says 'Manual URL'. Before the alias, 73 links a run were
        // dropped without an error because the column is not required.
        FixtureBuilder::write($this->path, FixtureBuilder::completeSheets([
            'CONVECTION | COOK & HOLD OVENS' => [
                FixtureBuilder::row(['Install / Manual URL' => 'https://example.com/manual.pdf']),
            ],
        ]));

        $result = \CADCO_Import_Reader::read($this->path);

        self::assertSame([], $result['errors']);

        $bySku = array_column($result['rows'], null, 'Model #');
        self::assertSame('https://example.com/manual.pdf', $bySku['BLC-113']['Manual URL']);
        self::assertArrayNotHasKey('Install / Manual URL', $bySku['BLC-113']);
    }

    public function test_an_alias_never_overwrites_a_real_column_of_the_same_name(): void
    {
        // Both headings on one sheet: renaming the alias would make one
        // column silently clobber the other, so the alias stands down.
        FixtureBuilder::write($this->path, FixtureBuilder::completeSheets([
            'CONVECTION | COOK & HOLD OVENS' => [
                FixtureBuilder::row([
                    'Manual URL'           => 'https://example.com/canonical.pdf',
                    'Install / Manual URL' => 'https://example.com/alias.pdf',
                ]),
            ],
        ]));

        $result = \CADCO_Import_Reader::read($this->path);
        $bySku  = array_column($result['rows'], null, 'Model #');

        self::assertSame('https://example.com/canonical.pdf', $bySku['BLC-113']['Manual URL']);
        self::assertSame('https://example.com/alias.pdf', $bySku['BLC-113']['Install / Manual URL']);
    }
}
