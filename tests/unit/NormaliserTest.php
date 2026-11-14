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

    public function test_a_cell_holding_only_a_no_break_space_becomes_blank(): void
    {
        // Reads as empty in Excel but is a one-character value: trim() strips
        // ASCII whitespace only. Material is an attribute column, so this
        // would have created a filter term named with one invisible
        // character, and the Tier C check would have counted it as answered.
        $result = \CADCO_Import_Normaliser::normalise([self::row('CMLB-CSG', '•Hotels', [
            'Material' => "\u{00A0}",
            'Capacity' => "\u{00A0}",
        ])]);

        self::assertSame('', $result['rows'][0]['Material']);
        self::assertSame('', $result['rows'][0]['Capacity']);
    }

    public function test_invisible_spaces_are_stripped_from_the_ends_of_prose(): void
    {
        $result = \CADCO_Import_Normaliser::normalise([self::row('CPG-10', '•Hotels', [
            'Primary Description' => "Single Glass-Ceramic Panini Grill\u{00A0}",
        ])]);

        self::assertSame(
            'Single Glass-Ceramic Panini Grill',
            $result['rows'][0]['Primary Description']
        );
    }

    public function test_a_no_break_space_between_words_becomes_a_normal_space(): void
    {
        $result = \CADCO_Import_Normaliser::normalise([self::row('CPG-10', '•Hotels', [
            'Primary Description' => "Panini\u{00A0}Grill",
        ])]);

        self::assertSame('Panini Grill', $result['rows'][0]['Primary Description']);
    }

    public function test_brand_standard_applies_inside_prose_and_bullets(): void
    {
        // Four spellings in the 2 November workbook: VariKwik, Varikwik,
        // Vari-Kwik and VariKwiK. Specialties and Type are both website
        // filters, so two spellings mean two filter terms for one brand.
        $result = \CADCO_Import_Normaliser::normalise([self::row('CCP-VK', "•VariKwik Speed Ovens\n•Hotels", [
            'Type'                => 'Varikwik accessory',
            'Primary Description' => 'Designed for Vari-Kwik ovens.',
        ])]);

        $row = $result['rows'][0];

        self::assertSame('VariKwik accessory', $row['Type']);
        self::assertSame('Designed for VariKwik ovens.', $row['Primary Description']);
    }

    public function test_brand_standard_collapses_a_hyphenated_specialty_tag(): void
    {
        // 'Vari-Kwik' and 'VariKwik' never share a canonical key, because
        // canonical_key() folds case but not punctuation inside a word, so
        // the frequency pass cannot merge them. Before the brand pass this
        // was a Tier B issue CADCO had to fix by hand every revision.
        $result = \CADCO_Import_Normaliser::normalise([
            self::row('CWB-VK', "•Vari-Kwik\n•Food Truck"),
            self::row('VK-120', "•VariKwik\n•Hotels"),
        ]);

        self::assertSame("•VariKwik\n•Food Truck", $result['rows'][0]['Specialties']);
        self::assertSame("•VariKwik\n•Hotels", $result['rows'][1]['Specialties']);
    }

    public function test_brand_standard_leaves_urls_alone(): void
    {
        // A share token is case-sensitive and opaque, and 'Website URL' is
        // the left-hand side of the legacy redirect map.
        $url = 'https://cadcoltd232.sharepoint.com/:b:/s/Lib/VariKwiK-spec.pdf';

        $result = \CADCO_Import_Normaliser::normalise([self::row('VK-120', '•Hotels', [
            'Spec Sheet URL' => $url,
            'Website URL'    => 'https://cadco-ltd.com/vari-kwik/',
        ])]);

        self::assertSame($url, $result['rows'][0]['Spec Sheet URL']);
        self::assertSame('https://cadco-ltd.com/vari-kwik/', $result['rows'][0]['Website URL']);
    }

    public function test_colour_and_material_standardise_in_opposite_directions(): void
    {
        // The same word is canonical in one column and an alias in the other:
        // Color is the finish, Material the substance. A single global map
        // would have to choose one and corrupt the other.
        $result = \CADCO_Import_Normaliser::normalise([self::row('WTBS-2N-HD', '•Hotels', [
            'Color'    => 'Stainless Steel',
            'Material' => 'Stainless',
        ])]);

        self::assertSame('Stainless', $result['rows'][0]['Color']);
        self::assertSame('Stainless Steel', $result['rows'][0]['Material']);
    }

    public function test_british_grey_becomes_gray_in_colour(): void
    {
        $result = \CADCO_Import_Normaliser::normalise([self::row('CTW-4M', '•Hotels', [
            'Color' => 'Stainless/ Metallic Grey',
        ])]);

        self::assertSame('Stainless/ Metallic Gray', $result['rows'][0]['Color']);
    }

    public function test_every_standardisation_is_recorded_as_a_change(): void
    {
        // Nothing may happen invisibly: the change list is what gets reported
        // back to CADCO so the spelling also gets fixed at source.
        $result = \CADCO_Import_Normaliser::normalise([self::row('CCP-VK', '•Hotels', [
            'Type'  => 'Varikwik accessory',
            'Color' => 'Stainless Steel',
        ])]);

        $columns = array_column($result['changes'], 'column');

        self::assertContains('Type', $columns);
        self::assertContains('Color', $columns);
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

    public function test_a_bullet_carried_onto_a_new_line_is_joined_back(): void
    {
        // XAFT-04HS-TR in the 13 Nov workbook.
        $cell = "•USB programming capability & WIFI\n•Includes electro valve for direct plumbed water\nconnection\n•208-240V, 2900-3800W, 16A";

        self::assertSame([
            'USB programming capability & WIFI',
            'Includes electro valve for direct plumbed water connection',
            '208-240V, 2900-3800W, 16A',
        ], \CADCO_Import_Normaliser::bullets($cell));
    }

    public function test_a_cell_without_markers_is_one_bullet_per_line(): void
    {
        self::assertSame(['Heavy duty', 'Stainless'], \CADCO_Import_Normaliser::bullets("Heavy duty\nStainless"));
    }
}
