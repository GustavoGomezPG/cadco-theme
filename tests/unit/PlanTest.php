<?php

declare(strict_types=1);

namespace CADCO\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PlanTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../inc/import/field-map.php';
        require_once __DIR__ . '/../../inc/import/class-cadco-import-normaliser.php';
        require_once __DIR__ . '/../../inc/import/class-cadco-import-plan.php';
    }

    public function test_a_new_plan_is_empty(): void
    {
        $plan = new \CADCO_Import_Plan();

        self::assertTrue($plan->is_empty());
        self::assertSame(0, $plan->total_writes());
    }

    public function test_counts_reflect_what_was_added(): void
    {
        $plan = new \CADCO_Import_Plan();
        $plan->add_create(self::row());
        $plan->add_update(self::row(), 12, ['Weight' => ['51', '52']]);
        $plan->add_rename(self::row(), 13, 'XAF-113');
        $plan->add_trash(14, 'GONE-1');
        $plan->add_skip('UNCHANGED-1');

        self::assertSame(
            ['create' => 1, 'update' => 1, 'rename' => 1, 'trash' => 1, 'untrash' => 0, 'skip' => 1],
            $plan->counts()
        );
        // Skips are not writes — an unchanged row costs nothing.
        self::assertSame(4, $plan->total_writes());
        self::assertFalse($plan->is_empty());
    }

    public function test_an_untrash_is_counted_and_is_a_write(): void
    {
        $plan = new \CADCO_Import_Plan();
        $plan->add_untrash(self::row(), 7, 'XAF-113', 'upc');

        self::assertSame(1, $plan->counts()['untrash']);
        self::assertSame(1, $plan->total_writes(), 'restoring a trashed product is a write');
        self::assertSame(7, $plan->untrashes()[0]['post_id']);
        self::assertSame('XAF-113', $plan->untrashes()[0]['old_sku']);
        self::assertSame('upc', $plan->untrashes()[0]['matched_by']);
    }

    public function test_category_is_derived_from_sheet_name_and_type(): void
    {
        $pairs = \CADCO_Import_Plan::categories_for(self::row([
            '__sheet' => 'CONVECTION OVENS',
            'Type'    => 'Bakerlux Classic',
        ]));

        self::assertSame([['parent' => 'Convection Ovens', 'child' => 'Bakerlux Classic']], $pairs);
    }

    public function test_sheet_and_type_are_title_cased(): void
    {
        $pairs = \CADCO_Import_Plan::categories_for(self::row([
            '__sheet' => 'FOODSERVICE CARTS',
            'Type'    => 'convection oven accessory',
        ]));

        self::assertSame('Foodservice Carts', $pairs[0]['parent']);
        self::assertSame('Convection Oven Accessory', $pairs[0]['child']);
    }

    public function test_brand_capitalisation_inside_a_type_is_preserved(): void
    {
        $pairs = \CADCO_Import_Plan::categories_for(self::row([
            '__sheet' => 'FOODSERVICE CARTS',
            'Type'    => 'ACCESSORIES for MobileServ® Carts',
        ]));

        self::assertSame('Accessories for MobileServ® Carts', $pairs[0]['child']);
    }

    public function test_a_comma_separated_type_yields_several_categories(): void
    {
        $pairs = \CADCO_Import_Plan::categories_for(self::row([
            '__sheet' => 'COUNTERTOP EQUIPMENT',
            'Type'    => 'Buffet Server & Warming Shelf, ACCESSORIES for Demo / Sampling Carts',
        ]));

        self::assertCount(2, $pairs);
        self::assertSame('Countertop Equipment', $pairs[0]['parent']);
        self::assertSame('Countertop Equipment', $pairs[1]['parent']);
        self::assertSame('Buffet Server & Warming Shelf', $pairs[0]['child']);
    }

    public function test_tags_come_from_specialties_one_per_line(): void
    {
        $tags = \CADCO_Import_Plan::tags_for(self::row([
            'Specialties' => "•Hotels\n•Country Clubs",
        ]));

        self::assertSame(['Hotels', 'Country Clubs'], $tags);
    }

    public function test_all_terms_are_deduplicated_across_rows(): void
    {
        $terms = \CADCO_Import_Plan::all_terms([
            self::row(['Type' => 'Bakerlux Classic', 'Specialties' => '•Hotels']),
            self::row(['Type' => 'Bakerlux Classic', 'Specialties' => "•Hotels\n•Catering"]),
        ]);

        self::assertCount(1, $terms['categories']);
        self::assertSame(['Hotels', 'Catering'], $terms['tags']);
        self::assertSame(['Cadco'], $terms['brands']);
    }

    private static function row(array $overrides = []): array
    {
        return $overrides + [
            '__sheet'     => 'CONVECTION OVENS',
            '__row'       => 2,
            'Model #'     => 'BLC-113',
            'Type'        => 'Bakerlux Classic',
            'Specialties' => '•Hotels',
            'Brand Name'  => 'Cadco',
        ];
    }
}
