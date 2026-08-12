<?php

declare(strict_types=1);

namespace CADCO\Tests\Fixtures;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Builds small XLSX files for tests.
 *
 * Fixtures are generated rather than committed as binaries so that the test
 * data is visible in a diff and can be varied per test.
 */
final class FixtureBuilder
{
    /**
     * @param array<string, list<array<string, string>>> $sheets
     *        Sheet name => list of rows. Each row's keys are header names.
     *        A sheet's header order is taken from its first row, so different
     *        sheets can deliberately disagree on column order.
     */
    public static function write(string $path, array $sheets): void
    {
        $book = new Spreadsheet();
        $book->removeSheetByIndex(0);

        foreach ($sheets as $name => $rows) {
            $sheet   = $book->createSheet();
            $sheet->setTitle($name);
            $headers = $rows === [] ? [] : array_keys($rows[0]);

            foreach ($headers as $i => $header) {
                $sheet->setCellValue([$i + 1, 1], $header);
            }

            foreach ($rows as $r => $row) {
                foreach ($headers as $i => $header) {
                    $sheet->setCellValue([$i + 1, $r + 2], $row[$header] ?? '');
                }
            }
        }

        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();
    }

    /**
     * All four canonical sheets, each holding one minimal valid row.
     *
     * The Reader treats a canonical sheet's absence as a Tier A error (a
     * missing sheet would otherwise read as "every product on it was
     * deleted" downstream), so any test that asserts a clean read needs a
     * structurally complete workbook. This lets a test that only cares about
     * one sheet write that one sheet and get the other three for free.
     *
     * Requires `cadco_import_sheets()` (from inc/import/field-map.php) to
     * already be loaded.
     *
     * @param array<string, list<array<string, string>>> $overrides
     *        Sheet name => rows. Replaces a canonical sheet's default row(s)
     *        when the name matches one of the four; adds a sheet otherwise.
     * @return array<string, list<array<string, string>>>
     */
    public static function completeSheets(array $overrides = []): array
    {
        $sheets = [];

        foreach (cadco_import_sheets() as $i => $name) {
            $sheets[$name] = [self::row([
                'Model #' => sprintf('FIXTURE-%d', $i + 1),
                'UPC#'    => sprintf('654796-90000-%d', $i + 1),
            ])];
        }

        return $overrides + $sheets;
    }

    /**
     * A minimal valid row. Override any field by passing it in $overrides.
     */
    public static function row(array $overrides = []): array
    {
        return $overrides + [
            'UPC#'                                    => '654796-52113-5',
            'Model #'                                 => 'BLC-113',
            'Product Name'                            => 'Half Size Convection Oven',
            'Brand Name'                              => 'Cadco',
            'Type'                                    => 'Bakerlux Classic',
            'Lead Time'                               => '1-3 business days',
            'Primary Description'                     => 'Half size convection oven',
            'Supplier Specifications - Bullet Points' => '•Heavy duty',
            'Specialties'                             => '•Hotels',
            'Height'                                  => '18.3',
            'Width'                                   => '23.6',
            'Depth'                                   => '26.375',
            'Weight'                                  => '51',
            'Material'                                => 'Stainless Steel',
            'Color'                                   => 'Stainless',
            'Voltage'                                 => '120',
            'Wattage'                                 => '1440',
            'Amps'                                    => '12',
            'Plug Type'                               => 'NEMA 5-15P',
            'Certifications'                          => 'NSF',
            'Freight Class'                           => '200',
            '(UPS/FedEx or LTL?)'                     => 'LTL',
            'Package Height'                          => '21',
            'Package Width'                           => '28',
            'Package Length'                          => '31',
            'Package Weight'                          => '70',
            'Country Of Origin'                       => 'IT',
            'Affected by Prop 65 Yes or No'           => 'yes',
        ];
    }
}
