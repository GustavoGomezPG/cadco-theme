<?php

declare(strict_types=1);

namespace CADCO\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PlannerTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../inc/import/field-map.php';
        require_once __DIR__ . '/../../inc/import/class-cadco-import-normaliser.php';
        require_once __DIR__ . '/../../inc/import/class-cadco-import-plan.php';
        require_once __DIR__ . '/../../inc/import/class-cadco-import-planner.php';
    }

    public function test_an_unknown_model_is_created(): void
    {
        $plan = \CADCO_Import_Planner::plan([self::row()], []);

        self::assertSame(1, $plan->counts()['create']);
        self::assertSame('BLC-113', $plan->creates()[0]['row']['Model #']);
    }

    public function test_an_unchanged_row_is_skipped_and_writes_nothing(): void
    {
        $row  = self::row();
        $plan = \CADCO_Import_Planner::plan([$row], [
            self::current(7, 'BLC-113', '654796-52113-5', \CADCO_Import_Planner::hash($row)),
        ]);

        self::assertSame(1, $plan->counts()['skip']);
        self::assertSame(0, $plan->total_writes(), 'a re-run of an unchanged workbook must write nothing');
    }

    public function test_a_changed_row_is_updated_with_a_field_diff(): void
    {
        $plan = \CADCO_Import_Planner::plan([self::row(['Weight' => '52'])], [
            self::current(7, 'BLC-113', '654796-52113-5', \CADCO_Import_Planner::hash(self::row())),
        ]);

        self::assertSame(1, $plan->counts()['update']);
        self::assertSame(7, $plan->updates()[0]['post_id']);
    }

    public function test_a_new_model_with_a_known_upc_is_a_rename_not_a_delete_and_create(): void
    {
        // The real case: XAF-113 became BLC-113 while keeping its UPC. Keyed on
        // model number alone this looks like one deletion and one addition,
        // which would destroy the page, its address and its images.
        $plan = \CADCO_Import_Planner::plan([self::row()], [
            self::current(7, 'XAF-113', '654796-52113-5', 'stale-hash'),
        ]);

        self::assertSame(1, $plan->counts()['rename']);
        self::assertSame(0, $plan->counts()['create']);
        self::assertSame(0, $plan->counts()['trash']);

        $rename = $plan->renames()[0];
        self::assertSame('XAF-113', $rename['old_sku']);
        self::assertSame('BLC-113', $rename['new_sku']);
        self::assertSame(7, $rename['post_id']);
    }

    public function test_a_rename_is_never_pre_approved(): void
    {
        $plan = \CADCO_Import_Planner::plan([self::row()], [
            self::current(7, 'XAF-113', '654796-52113-5', 'stale-hash'),
        ]);

        self::assertFalse($plan->renames()[0]['approved'], 'renames require explicit operator approval');
    }

    public function test_a_product_missing_from_the_workbook_is_trashed(): void
    {
        $plan = \CADCO_Import_Planner::plan([self::row()], [
            self::current(7, 'BLC-113', '654796-52113-5', \CADCO_Import_Planner::hash(self::row())),
            self::current(9, 'DISCONTINUED-1', '654796-99999-9', 'whatever'),
        ]);

        self::assertSame(1, $plan->counts()['trash']);
        self::assertSame('DISCONTINUED-1', $plan->trashes()[0]['sku']);
        self::assertSame(9, $plan->trashes()[0]['post_id']);
    }

    public function test_sku_match_wins_over_upc_match(): void
    {
        // If the SKU already matches, a coincidental UPC match elsewhere must
        // not turn a plain update into a rename.
        $plan = \CADCO_Import_Planner::plan([self::row(['Weight' => '52'])], [
            self::current(7, 'BLC-113', '654796-52113-5', 'stale'),
            self::current(8, 'OTHER-1', '654796-52113-5', 'stale'),
        ]);

        self::assertSame(1, $plan->counts()['update']);
        self::assertSame(0, $plan->counts()['rename']);
        self::assertSame(7, $plan->updates()[0]['post_id']);
    }

    public function test_hash_ignores_columns_that_are_not_imported(): void
    {
        // Notes is stored but internal; a Notes-only edit still counts as a
        // change because it is imported to meta. Position does not.
        $a = \CADCO_Import_Planner::hash(self::row(['__row' => 2]));
        $b = \CADCO_Import_Planner::hash(self::row(['__row' => 90]));

        self::assertSame($a, $b, 'moving a row in the sheet is not a content change');
    }

    public function test_hash_changes_when_content_changes(): void
    {
        self::assertNotSame(
            \CADCO_Import_Planner::hash(self::row()),
            \CADCO_Import_Planner::hash(self::row(['Weight' => '52']))
        );
    }

    private static function current(int $id, string $sku, string $upc, string $hash): array
    {
        return ['post_id' => $id, 'sku' => $sku, 'upc' => $upc, 'hash' => $hash];
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
        ];
    }
}
