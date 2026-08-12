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

    public function test_an_ambiguous_upc_never_triggers_a_rename(): void
    {
        // Two existing products share a UPC — a real data-entry error the
        // validator blocks upstream. If the planner is ever called directly it
        // must not guess which one a renamed row refers to.
        $plan = \CADCO_Import_Planner::plan([self::row()], [
            self::current(7, 'OLD-A', '654796-52113-5', 'stale'),
            self::current(8, 'OLD-B', '654796-52113-5', 'stale'),
        ]);

        self::assertSame(0, $plan->counts()['rename'], 'an ambiguous UPC must not be used for rename detection');
        self::assertSame(1, $plan->counts()['create']);
        self::assertSame(2, $plan->counts()['trash']);
    }

    public function test_ambiguity_is_permanent_however_many_products_share_a_upc(): void
    {
        // An odd number of collisions is the case that breaks a sentinel
        // written with isset(): the third insert sees isset() === false,
        // because the stored value is null, and overwrites the sentinel.
        foreach ([3, 4, 5] as $collisions) {
            $current = [];

            for ($i = 0; $i < $collisions; $i++) {
                $current[] = self::current(10 + $i, 'OLD-' . $i, '654796-52113-5', 'stale');
            }

            $plan = \CADCO_Import_Planner::plan([self::row()], $current);

            self::assertSame(0, $plan->counts()['rename'], "$collisions colliding products must not yield a rename");
            self::assertSame(1, $plan->counts()['create'], "$collisions colliding products: the row is a create");
            self::assertSame($collisions, $plan->counts()['trash'], "$collisions colliding products must all trash");
        }
    }

    public function test_numeric_skus_and_upcs_do_not_crash_the_planner(): void
    {
        $rows = [
            self::row(['Model #' => '1418', 'UPC#' => '65479652113']),
            self::row(['Model #' => '2024', 'UPC#' => '65479652114']),
        ];

        $plan = \CADCO_Import_Planner::plan($rows, [
            self::current(7, '1418', '65479652113', 'stale'),
        ]);

        self::assertSame(1, $plan->counts()['update']);
        self::assertSame(1, $plan->counts()['create']);
        self::assertSame(0, $plan->counts()['trash']);
    }

    public function test_a_row_matching_by_sku_never_becomes_a_rename_even_if_another_product_shares_its_upc(): void
    {
        $plan = \CADCO_Import_Planner::plan([self::row()], [
            self::current(7, 'BLC-113', '999999-99999-9', 'stale'),
            self::current(8, 'SOMETHING-ELSE', '654796-52113-5', 'stale'),
        ]);

        self::assertSame(1, $plan->counts()['update'], 'SKU match must win');
        self::assertSame(0, $plan->counts()['rename']);
        self::assertSame(7, $plan->updates()[0]['post_id']);
        self::assertSame(1, $plan->counts()['trash'], 'the unrelated product is absent from the workbook');
    }

    public function test_a_blank_upc_is_not_an_identity(): void
    {
        $plan = \CADCO_Import_Planner::plan([self::row(['Model #' => 'NEW-1', 'UPC#' => ''])], [
            self::current(7, 'OLD-1', '', 'stale'),
        ]);

        self::assertSame(0, $plan->counts()['rename'], 'a blank UPC must not be used to match');
        self::assertSame(1, $plan->counts()['create']);
        self::assertSame(1, $plan->counts()['trash']);
    }

    public function test_comparable_is_the_payload_the_hash_is_built_from(): void
    {
        $row = self::row();

        // The invariant the whole diff rests on: two rows hash the same if and
        // only if their comparable payloads are identical. If these ever come
        // apart, a product could be reported as changed with an empty diff, or
        // skipped despite having changed.
        self::assertSame(
            \CADCO_Import_Planner::hash($row),
            \CADCO_Import_Planner::hash(self::row()),
            'identical rows hash identically'
        );
        self::assertSame(
            \CADCO_Import_Planner::comparable($row),
            \CADCO_Import_Planner::comparable(self::row())
        );

        $changed = self::row(['Weight' => '52']);
        self::assertNotSame(\CADCO_Import_Planner::hash($row), \CADCO_Import_Planner::hash($changed));
        self::assertNotSame(\CADCO_Import_Planner::comparable($row), \CADCO_Import_Planner::comparable($changed));
    }

    public function test_comparable_ignores_row_position(): void
    {
        self::assertSame(
            \CADCO_Import_Planner::comparable(self::row(['__row' => 2])),
            \CADCO_Import_Planner::comparable(self::row(['__row' => 90])),
            'moving a row in the sheet is not a content change'
        );
    }

    public function test_diff_reports_only_what_changed(): void
    {
        $diff = \CADCO_Import_Planner::diff(
            ['Weight' => '51', 'Product Name' => 'Oven'],
            ['Weight' => '52', 'Product Name' => 'Oven']
        );

        self::assertSame(['Weight' => ['51', '52']], $diff);
    }

    public function test_diff_reports_added_and_removed_values(): void
    {
        $diff = \CADCO_Import_Planner::diff(
            ['Wattage' => '1440'],
            ['Voltage' => '120']
        );

        self::assertSame(
            ['Voltage' => ['', '120'], 'Wattage' => ['1440', '']],
            $diff
        );
    }

    public function test_an_update_carries_a_real_per_field_diff(): void
    {
        $before = \CADCO_Import_Planner::comparable(self::row());

        $plan = \CADCO_Import_Planner::plan([self::row(['Weight' => '52'])], [
            self::current(7, 'BLC-113', '654796-52113-5', 'stale') + ['snapshot' => $before],
        ]);

        self::assertSame(1, $plan->counts()['update']);
        self::assertSame(['Weight' => ['51', '52']], $plan->updates()[0]['diff']);
    }

    public function test_an_update_without_a_stored_snapshot_still_plans(): void
    {
        // Products imported before snapshots existed have none. The update must
        // still be planned — the operator simply cannot be shown a diff for it.
        $plan = \CADCO_Import_Planner::plan([self::row(['Weight' => '52'])], [
            self::current(7, 'BLC-113', '654796-52113-5', 'stale'),
        ]);

        self::assertSame(1, $plan->counts()['update']);
        self::assertSame([], $plan->updates()[0]['diff']);
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
