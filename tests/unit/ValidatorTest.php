<?php

declare(strict_types=1);

namespace CADCO\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../inc/import/field-map.php';
        require_once __DIR__ . '/../../inc/import/class-cadco-import-normaliser.php';
        require_once __DIR__ . '/../../inc/import/class-cadco-import-issue.php';
        require_once __DIR__ . '/../../inc/import/class-cadco-import-report.php';
        require_once __DIR__ . '/../../inc/import/class-cadco-import-validator.php';
    }

    public function test_a_clean_workbook_passes(): void
    {
        $report = \CADCO_Import_Validator::validate([self::row()]);

        self::assertTrue($report->passed(), self::describe($report));
    }

    public function test_structural_errors_are_carried_into_the_report(): void
    {
        $report = \CADCO_Import_Validator::validate([self::row()], [[
            'tier'    => 'A',
            'sheet'   => 'LIST PRICING',
            'row'     => null,
            'column'  => '',
            'message' => 'Unrecognised sheet "LIST PRICING".',
        ]]);

        self::assertFalse($report->passed());
        self::assertSame(['A' => 1], $report->tier_counts());
    }

    public function test_duplicate_model_number_is_tier_a(): void
    {
        $report = \CADCO_Import_Validator::validate([
            self::row(['Model #' => 'OP-4', 'UPC#' => '654796-54513-7']),
            self::row(['Model #' => 'OP-4', 'UPC#' => '654796-54513-7', '__sheet' => 'FAST COOKING OVENS']),
        ]);

        self::assertFalse($report->passed());
        self::assertSame('A', $report->all()[0]->tier);
        self::assertStringContainsString('OP-4', $report->all()[0]->found);
    }

    public function test_duplicate_upc_on_different_models_is_tier_a(): void
    {
        $report = \CADCO_Import_Validator::validate([
            self::row(['Model #' => 'BLS-4HLD-2', 'UPC#' => '654796-55145-3']),
            self::row(['Model #' => 'PGW-10', 'UPC#' => '654796-55145-3']),
        ]);

        $a = $report->by_tier()['A'] ?? [];

        self::assertNotSame([], $a);
        self::assertStringContainsString('654796-55145-3', $a[0]->found);
    }

    public function test_malformed_upc_is_tier_a(): void
    {
        // The real workbook contained a corrupted formula artifact here.
        $report = \CADCO_Import_Validator::validate([
            self::row(['UPC#' => '654796-+28:3052193-7']),
        ]);

        $a = $report->by_tier()['A'] ?? [];

        self::assertNotSame([], $a);
        self::assertStringContainsString('654796-+28:3052193-7', $a[0]->found);
    }

    public function test_unresolvable_parent_product_is_tier_a(): void
    {
        $report = \CADCO_Import_Validator::validate([
            self::row(['Model #' => 'WTBS-2N', 'Parent Product' => 'DOES-NOT-EXIST']),
        ]);

        $a = $report->by_tier()['A'] ?? [];

        self::assertNotSame([], $a);
        self::assertStringContainsString('DOES-NOT-EXIST', $a[0]->found);
    }

    public function test_a_resolvable_parent_product_passes(): void
    {
        $report = \CADCO_Import_Validator::validate([
            self::row(['Model #' => 'WTBS-2N-HD', 'UPC#' => '654796-11111-1']),
            self::row(['Model #' => 'WTBS-2N', 'UPC#' => '654796-22222-2', 'Parent Product' => 'WTBS-2N-HD']),
        ]);

        self::assertTrue($report->passed(), self::describe($report));
    }

    public function test_punctuation_only_variants_are_tier_b(): void
    {
        // Same letters, different spacing. The normaliser's canonical key does
        // not merge these, so the validator has to catch them.
        $report = \CADCO_Import_Validator::validate([
            self::row(['Model #' => 'A', 'UPC#' => '654796-11111-1', 'Certifications' => 'MET (=UL & CSA)']),
            self::row(['Model #' => 'B', 'UPC#' => '654796-22222-2', 'Certifications' => 'MET (= UL & CSA)']),
        ]);

        $b = $report->by_tier()['B'] ?? [];

        self::assertNotSame([], $b);
        self::assertSame('Certifications', $b[0]->column);
    }

    public function test_a_near_miss_typo_is_tier_b(): void
    {
        $report = \CADCO_Import_Validator::validate([
            self::row(['Model #' => 'A', 'UPC#' => '654796-11111-1', 'Specialties' => '•Healthcare Facilities']),
            self::row(['Model #' => 'B', 'UPC#' => '654796-22222-2', 'Specialties' => '•Healthcare facilties']),
        ]);

        $b = $report->by_tier()['B'] ?? [];

        self::assertNotSame([], $b, 'a one-letter misspelling should be reported');
        self::assertSame('Specialties', $b[0]->column);
    }

    public function test_values_differing_by_a_digit_are_not_variants(): void
    {
        // 'NEMA 5-15P' and 'NEMA 6-15P' are one character apart but are
        // genuinely different plugs. Flagging them would train CADCO to
        // ignore the report.
        $report = \CADCO_Import_Validator::validate([
            self::row(['Model #' => 'A', 'UPC#' => '654796-11111-1', 'Plug Type' => 'NEMA 5-15P']),
            self::row(['Model #' => 'B', 'UPC#' => '654796-22222-2', 'Plug Type' => 'NEMA 6-15P']),
        ]);

        self::assertTrue($report->passed(), self::describe($report));
    }

    public function test_unrelated_values_are_not_variants(): void
    {
        $report = \CADCO_Import_Validator::validate([
            self::row(['Model #' => 'A', 'UPC#' => '654796-11111-1', 'Specialties' => '•Hotels']),
            self::row(['Model #' => 'B', 'UPC#' => '654796-22222-2', 'Specialties' => '•Catering']),
        ]);

        self::assertTrue($report->passed(), self::describe($report));
    }

    public function test_different_certification_bodies_are_not_merged(): void
    {
        // MET and ETL are different laboratories. Their fuzzy keys are only
        // two edits apart ('metulcsa' / 'etlulcsa'), which is exactly why the
        // edit-distance threshold is 1 and not 2.
        $report = \CADCO_Import_Validator::validate([
            self::row(['Model #' => 'A', 'UPC#' => '654796-11111-1', 'Certifications' => 'MET (=UL & CSA)']),
            self::row(['Model #' => 'B', 'UPC#' => '654796-22222-2', 'Certifications' => 'ETL (=UL & CSA)']),
        ]);

        self::assertTrue($report->passed(), self::describe($report));
    }

    public function test_short_colour_variants_are_still_caught(): void
    {
        // 'Grey' and 'Gray' are only four characters, so the minimum-length
        // guard has to be low enough to include them.
        $report = \CADCO_Import_Validator::validate([
            self::row(['Model #' => 'A', 'UPC#' => '654796-11111-1', 'Color' => 'Grey']),
            self::row(['Model #' => 'B', 'UPC#' => '654796-22222-2', 'Color' => 'Gray']),
        ]);

        $b = $report->by_tier()['B'] ?? [];

        self::assertNotSame([], $b);
        self::assertSame('Color', $b[0]->column);
    }

    public function test_blank_required_field_is_tier_c(): void
    {
        $report = \CADCO_Import_Validator::validate([self::row(['Weight' => ''])]);

        $c = $report->by_tier()['C'] ?? [];

        self::assertNotSame([], $c);
        self::assertSame('Weight', $c[0]->column);
    }

    public function test_blank_na_field_is_tier_c_but_the_literal_na_passes(): void
    {
        $blank = \CADCO_Import_Validator::validate([self::row(['Certifications' => ''])]);
        self::assertFalse($blank->passed());

        $na = \CADCO_Import_Validator::validate([self::row(['Certifications' => 'n/a'])]);
        self::assertTrue($na->passed(), self::describe($na));
    }

    public function test_every_issue_names_sheet_row_and_a_fix(): void
    {
        $report = \CADCO_Import_Validator::validate([self::row(['Weight' => ''])]);

        foreach ($report->all() as $issue) {
            self::assertNotSame('', $issue->sheet);
            self::assertNotNull($issue->row);
            self::assertNotSame('', $issue->fix, 'every issue must say how to fix it');
        }
    }

    private static function describe(\CADCO_Import_Report $report): string
    {
        return implode("\n", array_map(
            static fn (\CADCO_Import_Issue $i): string => "{$i->tier} {$i->sheet}:{$i->row} {$i->column} — {$i->message}",
            $report->all()
        ));
    }

    private static function row(array $overrides = []): array
    {
        return $overrides + [
            '__sheet'                                 => 'CONVECTION OVENS',
            '__row'                                   => 2,
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
            'Parent Product'                          => '',
        ];
    }
}
