<?php

declare(strict_types=1);

namespace CADCO\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class NormaliserTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../inc/import/field-map.php';
        require_once __DIR__ . '/../../inc/import/class-cadco-import-normaliser.php';
    }

    public function test_title_case_preserves_mixed_case_words(): void
    {
        // Naive ucwords() would produce 'Mobileserv®' and destroy the brand.
        self::assertSame(
            'Accessories for MobileServ® Carts',
            \CADCO_Import_Normaliser::title_case('ACCESSORIES for MobileServ® Carts')
        );
        self::assertSame('Foodservice Carts', \CADCO_Import_Normaliser::title_case('FOODSERVICE CARTS'));
        self::assertSame('Convection Oven Accessory', \CADCO_Import_Normaliser::title_case('convection oven accessory'));
        self::assertSame('VariKwik Accessory', \CADCO_Import_Normaliser::title_case('VariKwik accessory'));
    }

    public function test_title_case_lowercases_small_words_except_first(): void
    {
        self::assertSame('For the Love of Ovens', \CADCO_Import_Normaliser::title_case('FOR THE LOVE OF OVENS'));
    }

    public function test_bullets_splits_lines_and_strips_markers(): void
    {
        self::assertSame(
            ['Country Clubs', 'Hotels'],
            \CADCO_Import_Normaliser::bullets("•Country Clubs\n•Hotels")
        );
    }

    public function test_bullets_recovers_a_missing_line_break(): void
    {
        // 'Countertop & Tabletop •Cooking/Warming' is one tag whose line break
        // was lost, not two tags. The canonical spelling has no inner bullet.
        self::assertSame(
            ['Countertop & Tabletop Cooking/Warming'],
            \CADCO_Import_Normaliser::bullets('•Countertop & Tabletop •Cooking/Warming')
        );
    }

    public function test_canonical_key_ignores_case_and_slash_spacing(): void
    {
        $key = \CADCO_Import_Normaliser::canonical_key('Steam Table / Chafer Supplies');

        self::assertSame($key, \CADCO_Import_Normaliser::canonical_key('Steam Table/ Chafer Supplies'));
        self::assertSame($key, \CADCO_Import_Normaliser::canonical_key('Steam table / chafer supplies'));
        self::assertNotSame($key, \CADCO_Import_Normaliser::canonical_key('Buffet Supplies'));

        // 'Steam Tables/Chafer Supplies' (plural "Tables") deliberately does NOT
        // share the key. Case and spacing are safe to ignore; a different word is
        // not. That variant is reported to CADCO as a Tier B issue to fix at
        // source rather than guessed at here.
        self::assertNotSame($key, \CADCO_Import_Normaliser::canonical_key('Steam Tables/Chafer Supplies'));
    }

    public function test_canonical_key_ignores_stray_quotes(): void
    {
        self::assertSame(
            \CADCO_Import_Normaliser::canonical_key('Kitchen Equipment'),
            \CADCO_Import_Normaliser::canonical_key('Kitchen Equipment"')
        );
    }

    public function test_tag_variants_collapse_onto_the_most_frequent_spelling(): void
    {
        $rows = [
            self::row('A', "•Steam Table / Chafer Supplies"),
            self::row('B', "•Steam Table / Chafer Supplies"),
            self::row('C', "•Steam Table/ Chafer Supplies"),
        ];

        $result = \CADCO_Import_Normaliser::normalise($rows);

        foreach ($result['rows'] as $row) {
            self::assertSame('•Steam Table / Chafer Supplies', $row['Specialties']);
        }

        $changed = array_filter($result['changes'], static fn ($c) => $c['model'] === 'C');
        self::assertCount(1, $changed, 'the odd spelling should be recorded as a change');
    }

    public function test_declared_aliases_collapse_and_are_recorded(): void
    {
        // 'IT' and 'Italy' share no spelling, so neither the canonical key nor
        // edit distance can relate them. The alias table is the only thing
        // that can, which is why it is declared rather than inferred.
        $rows = [
            self::row('A', '•Hotels', ['Country Of Origin' => 'IT']),
            self::row('B', '•Hotels', ['Country Of Origin' => 'IT']),
            self::row('C', '•Hotels', ['Country Of Origin' => 'Italy']),
        ];

        $result = \CADCO_Import_Normaliser::normalise($rows);

        self::assertSame('IT', $result['rows'][2]['Country Of Origin']);

        $change = array_values(array_filter(
            $result['changes'],
            static fn ($c) => $c['column'] === 'Country Of Origin'
        ));
        self::assertCount(1, $change);
        self::assertSame('Italy', $change[0]['before']);
        self::assertSame('IT', $change[0]['after']);
    }

    public function test_internal_whitespace_is_collapsed(): void
    {
        $rows   = [self::row('A', '•Hotels', ['Product Name' => "Small   Oven  Pan "])];
        $result = \CADCO_Import_Normaliser::normalise($rows);

        self::assertSame('Small Oven Pan', $result['rows'][0]['Product Name']);
    }

    public function test_type_comma_spacing_is_normalised(): void
    {
        $rows   = [self::row('A', '•Hotels', ['Type' => 'Buffet Server ,  Demo Cart'])];
        $result = \CADCO_Import_Normaliser::normalise($rows);

        self::assertSame('Buffet Server, Demo Cart', $result['rows'][0]['Type']);
    }

    public function test_an_already_clean_row_produces_no_changes(): void
    {
        $result = \CADCO_Import_Normaliser::normalise([self::row('A', '•Hotels')]);

        self::assertSame([], $result['changes']);
    }

    private static function row(string $model, string $specialties, array $overrides = []): array
    {
        return $overrides + [
            '__sheet'           => 'CONVECTION OVENS',
            '__row'             => 2,
            'Model #'           => $model,
            'Product Name'      => 'Oven',
            'Type'              => 'Bakerlux Classic',
            'Specialties'       => $specialties,
            'Country Of Origin' => 'IT',
            'Color'             => 'Stainless',
            'Material'          => 'Stainless Steel',
            'Certifications'    => 'NSF',
            'Plug Type'         => 'NEMA 5-15P',
            'Lead Time'         => '1-3 business days',
        ];
    }
}
