<?php

declare(strict_types=1);

namespace CADCO\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class TermDiffTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../inc/import/field-map.php';
        require_once __DIR__ . '/../../inc/import/class-cadco-import-normaliser.php';
        require_once __DIR__ . '/../../inc/import/class-cadco-import-plan.php';
        require_once __DIR__ . '/../../inc/import/class-cadco-import-term-diff.php';
    }

    public function test_new_categories_are_nested_parent_to_child(): void
    {
        $diff = \CADCO_Import_Term_Diff::compare([
            self::row('BLC-113', 'CONVECTION | COOK & HOLD OVENS', 'Bakerlux Classic'),
            self::row('BLC-133', 'CONVECTION | COOK & HOLD OVENS', 'Bakerlux Classic'),
            self::row('BLS-113', 'CONVECTION | COOK & HOLD OVENS', 'Bakerlux Station'),
        ], []);

        $new = $diff['product_cat']['new'];

        self::assertCount(1, $new, 'one top-level category');
        self::assertSame('Convection & Cook-Hold Ovens', $new[0]['name']);
        self::assertCount(2, $new[0]['children']);
        self::assertSame('Bakerlux Classic', $new[0]['children'][0]['name']);
        self::assertSame(2, $new[0]['children'][0]['products'], 'two products land in it');
        self::assertSame(1, $new[0]['children'][1]['products']);
    }

    public function test_a_wholly_new_parent_is_marked_parent_is_new(): void
    {
        // Nothing existing at all, so "Convection & Cook-Hold Ovens" itself is being
        // created, not just gaining a child.
        $diff = \CADCO_Import_Term_Diff::compare([
            self::row('BLC-113', 'CONVECTION | COOK & HOLD OVENS', 'Bakerlux Classic'),
        ], []);

        self::assertTrue($diff['product_cat']['new'][0]['parent_is_new']);
    }

    public function test_a_new_child_under_an_existing_parent_does_not_mark_the_parent_new(): void
    {
        // "Convection & Cook-Hold Ovens" already exists on the site; only its child
        // "Bakerlux Station" is new. The parent entry that carries that new
        // child must not be counted as a new parent itself — a caller
        // summing "how many terms will this plan create" needs to tell the
        // two situations apart (see CADCO_Import_View::term_diff_totals()).
        $diff = \CADCO_Import_Term_Diff::compare(
            [
                self::row('BLC-113', 'CONVECTION | COOK & HOLD OVENS', 'Bakerlux Classic'),
                self::row('BLS-113', 'CONVECTION | COOK & HOLD OVENS', 'Bakerlux Station'),
            ],
            [
                ['taxonomy' => 'product_cat', 'term_id' => 22, 'name' => 'Convection & Cook-Hold Ovens', 'parent' => 0, 'count' => 0],
                ['taxonomy' => 'product_cat', 'term_id' => 26, 'name' => 'Bakerlux Classic', 'parent' => 22, 'count' => 0],
            ]
        );

        $new = $diff['product_cat']['new'];

        self::assertCount(1, $new, 'one parent entry, carrying only the new child');
        self::assertSame('Convection & Cook-Hold Ovens', $new[0]['name']);
        self::assertFalse($new[0]['parent_is_new'], 'the parent already existed');
        self::assertCount(1, $new[0]['children']);
        self::assertSame('Bakerlux Station', $new[0]['children'][0]['name']);
    }

    public function test_an_existing_term_is_not_reported_as_new(): void
    {
        $diff = \CADCO_Import_Term_Diff::compare(
            [self::row('BLC-113', 'CONVECTION | COOK & HOLD OVENS', 'Bakerlux Classic')],
            [
                ['taxonomy' => 'product_cat', 'term_id' => 22, 'name' => 'Convection & Cook-Hold Ovens', 'parent' => 0, 'count' => 0],
                ['taxonomy' => 'product_cat', 'term_id' => 26, 'name' => 'Bakerlux Classic', 'parent' => 22, 'count' => 0],
            ]
        );

        self::assertSame([], $diff['product_cat']['new']);
    }

    public function test_a_term_the_workbook_no_longer_implies_and_holding_no_products_is_removed(): void
    {
        $diff = \CADCO_Import_Term_Diff::compare(
            [self::row('BLC-113', 'CONVECTION | COOK & HOLD OVENS', 'Bakerlux Classic')],
            [
                ['taxonomy' => 'product_cat', 'term_id' => 29, 'name' => 'Caldolux Cook & Hold', 'parent' => 0, 'count' => 0],
            ]
        );

        self::assertCount(1, $diff['product_cat']['removed']);
        self::assertSame('Caldolux Cook & Hold', $diff['product_cat']['removed'][0]['name']);
        self::assertSame([], $diff['product_cat']['in_use']);
    }

    public function test_a_term_the_workbook_no_longer_implies_but_still_holding_products_is_in_use(): void
    {
        // The loud case. The applier's orphan pass will NOT remove this, because
        // products still reference it — usually a renamed Type that left its old
        // term behind. Only a person can resolve that, so it must be surfaced.
        $diff = \CADCO_Import_Term_Diff::compare(
            [self::row('BLC-113', 'CONVECTION | COOK & HOLD OVENS', 'Bakerlux Classic')],
            [
                ['taxonomy' => 'product_cat', 'term_id' => 46, 'name' => 'Warming Cabinets', 'parent' => 0, 'count' => 4],
            ]
        );

        self::assertSame([], $diff['product_cat']['removed'], 'a term with products is never a removal');
        self::assertCount(1, $diff['product_cat']['in_use']);
        self::assertSame('Warming Cabinets', $diff['product_cat']['in_use'][0]['name']);
        self::assertSame(4, $diff['product_cat']['in_use'][0]['products']);
    }

    public function test_uncategorized_is_never_reported(): void
    {
        // WooCommerce recreates it as the default term, so reporting it as a
        // removal every single run would be noise the operator learns to ignore.
        $diff = \CADCO_Import_Term_Diff::compare(
            [self::row('BLC-113', 'CONVECTION | COOK & HOLD OVENS', 'Bakerlux Classic')],
            [
                ['taxonomy' => 'product_cat', 'term_id' => 15, 'name' => 'Uncategorized', 'parent' => 0, 'count' => 0],
            ]
        );

        self::assertSame([], $diff['product_cat']['removed']);
        self::assertSame([], $diff['product_cat']['in_use']);
    }

    public function test_tags_and_brands_are_diffed_too(): void
    {
        $diff = \CADCO_Import_Term_Diff::compare(
            [self::row('BLC-113', 'CONVECTION | COOK & HOLD OVENS', 'Bakerlux Classic')],
            []
        );

        self::assertSame(['Hotels'], array_column($diff['product_tag']['new'], 'name'));
        self::assertSame(['Cadco'], array_column($diff['product_brand']['new'], 'name'));
    }

    public function test_a_numeric_type_does_not_crash_the_diff(): void
    {
        // PHP coerces canonical integer strings to int as array keys, which has
        // caused TypeErrors in this codebase before.
        $diff = \CADCO_Import_Term_Diff::compare(
            [self::row('X-1', 'CONVECTION | COOK & HOLD OVENS', '2024')],
            []
        );

        self::assertSame('2024', $diff['product_cat']['new'][0]['children'][0]['name']);
    }

    private static function row(string $model, string $sheet, string $type): array
    {
        return [
            '__sheet'     => $sheet,
            '__row'       => 2,
            'Model #'     => $model,
            'Type'        => $type,
            'Specialties' => '•Hotels',
            'Brand Name'  => 'Cadco',
        ];
    }
}
