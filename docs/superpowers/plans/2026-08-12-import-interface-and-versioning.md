# Import Interface and Versioning Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the importer's single-file-input screen with a three-stage wizard that shows every consequence of a plan before it is applied, and add import versioning so any archived workbook can be re-applied as a restore point.

**Architecture:** The 1,146-line admin class splits into a controller (routing, requests, AJAX), a view (the shell and every review section), an archive (run directories, manifests, restore, retention) and a term-diff unit. The pure pipeline from the previous plan is untouched except for two additive changes: the planner accepts injected trashed candidates, and the applier gains an untrash job type.

**Tech Stack:** PHP 8.2, WordPress, WooCommerce 11.0.0, PhpSpreadsheet, PHPUnit 10, Playwright.

## Global Constraints

- **Design spec:** `docs/superpowers/specs/2026-08-12-import-interface-and-versioning-design.md`. Read it once before Task 1.
- **Branch:** `feat/product-import`. Do not create a worktree or switch branches.
- **Working directory:** `/Users/gustavogomez/Local Sites/cadco/app/public/wp-content/themes/cadco-theme` (the git repository root).
- PHP 8.2, `declare(strict_types=1)` on every new PHP file.
- **Every value rendered originates from an uploaded spreadsheet and is untrusted.** `esc_html()` for text, `esc_attr()` for attributes, `esc_url()` for links. Two XSS vectors and one CSV-injection vector have already been found in this system.
- **No AI/tool attribution in any commit message.** Plain `<type>(<scope>): <subject>`.
- Do not modify `inc/cadco-woocommerce.php` or weaken the commerce-off layer. Nothing may become purchasable.
- Products are **trashed**, never permanently deleted, except by the archive pruner acting on run directories.
- **The planner must stay pure** — no WordPress calls, no database. Trashed candidates are injected.
- Leave the site at 0 products, 0 terms, 0 `pa_*` attribute taxonomies and no leftover `cadco_*` options after every task's verification.
- `composer test` and `npm run test:e2e` must both be green at the end of every task.

## Environment (verified — do not re-derive)

- Site `https://cadco.local`, self-signed cert. WooCommerce 11.0.0. Admin user `cadcodev`.
- `wp` on PATH is a Local-aware wrapper. **`wp eval`, `wp eval-file`, `wp post list`, `wp term list`, `wp option` all work. `wp db query` does NOT** — it uses the wrong socket. Use `wp eval` with `$wpdb`.
- Composer is NOT on PATH: `/Applications/Local.app/Contents/Resources/extraResources/bin/composer/posix/composer`
- Baseline: `composer test` → 91 tests, 211 assertions. `npm run test:e2e` → 17 tests, ~2.7 min.
- Workbooks: `/Users/gustavogomez/Documents/Projects/CADCO/Products Excel Spreadsheet latest/` — `..._CORRECTED.xlsx` passes (236 products, 0 issues); `Product Index Spreadsheet 2026_Website_.xlsx` fails (266 issues).
- `CADCO_Import_Admin` is only loaded when `is_admin()`, so a `wp eval-file` script that needs it must `require_once get_stylesheet_directory() . '/inc/import/class-cadco-import-admin.php';` first.

## Existing interfaces you build on

```
CADCO_Import_Planner::plan(array $rows, array $current): CADCO_Import_Plan
CADCO_Import_Planner::comparable/hash/diff/legacy_path/path_of
CADCO_Import_Plan::categories_for/tags_for/all_terms/counts/creates/updates/renames/trashes/skips
CADCO_Import_Repository::current_products/legacy_urls/find_by_sku/category_terms/orphan_terms
CADCO_Import_Applier::prepare_terms/build_queue/apply_jobs/write_product/finalise
CADCO_Import_Admin::run_pipeline/redirect_map/maybe_export_redirects/maybe_export_report
CADCO_Import_Report::passed/count/by_tier/tier_counts/to_csv/csv_safe
```

## File structure

| File | Responsibility |
|---|---|
| `inc/import/class-cadco-import-term-diff.php` | **new** — category/tag/brand tree diff |
| `inc/import/class-cadco-import-archive.php` | **new** — run directories, manifests, labels, restore, retention |
| `inc/import/class-cadco-import-view.php` | **new** — the shell, stage bar, sidebar, every review section |
| `inc/import/class-cadco-import-admin.php` | trimmed to routing, request handling, AJAX |
| `inc/import/class-cadco-import-planner.php` | `plan()` gains an injected `$trashed` argument |
| `inc/import/class-cadco-import-repository.php` | gains `trashed_products()` |
| `inc/import/class-cadco-import-applier.php` | gains the `untrash` job type |
| `assets/css/import-admin.css` | the wizard shell, sidebar, sections |
| `assets/js/import-admin.js` | section navigation, label editing, existing apply loop |

---

## Task 1: The term diff

Pure logic, no WordPress, so it is unit-tested directly. Everything the Categories section shows comes from here.

**Files:**
- Create: `inc/import/class-cadco-import-term-diff.php`
- Test: `tests/unit/TermDiffTest.php`

**Interfaces:**
- Consumes: `CADCO_Import_Plan::all_terms()` and `::categories_for()` (existing).
- Produces: `CADCO_Import_Term_Diff::compare(array $rows, array $existing): array` returning

```php
[
    'product_cat' => [
        'new'     => [ ['name' => 'Convection Ovens', 'parent' => '', 'products' => 0,
                        'children' => [ ['name' => 'Bakerlux Classic', 'products' => 7], ... ]], ... ],
        'removed' => [ ['name' => 'Caldolux Cook & Hold', 'term_id' => 29], ... ],
        'in_use'  => [ ['name' => 'Warming Cabinets', 'term_id' => 46, 'products' => 4], ... ],
    ],
    'product_tag'   => [ 'new' => [...], 'removed' => [...], 'in_use' => [...] ],
    'product_brand' => [ ... ],
]
```

`$existing` is a list of `['taxonomy' => string, 'term_id' => int, 'name' => string, 'parent' => int, 'count' => int]`, injected so this unit needs no database.

- [ ] **Step 1: Write the failing test**

Create `tests/unit/TermDiffTest.php`:

```php
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
            self::row('BLC-113', 'CONVECTION OVENS', 'Bakerlux Classic'),
            self::row('BLC-133', 'CONVECTION OVENS', 'Bakerlux Classic'),
            self::row('BLS-113', 'CONVECTION OVENS', 'Bakerlux Station'),
        ], []);

        $new = $diff['product_cat']['new'];

        self::assertCount(1, $new, 'one top-level category');
        self::assertSame('Convection Ovens', $new[0]['name']);
        self::assertCount(2, $new[0]['children']);
        self::assertSame('Bakerlux Classic', $new[0]['children'][0]['name']);
        self::assertSame(2, $new[0]['children'][0]['products'], 'two products land in it');
        self::assertSame(1, $new[0]['children'][1]['products']);
    }

    public function test_an_existing_term_is_not_reported_as_new(): void
    {
        $diff = \CADCO_Import_Term_Diff::compare(
            [self::row('BLC-113', 'CONVECTION OVENS', 'Bakerlux Classic')],
            [
                ['taxonomy' => 'product_cat', 'term_id' => 22, 'name' => 'Convection Ovens', 'parent' => 0, 'count' => 0],
                ['taxonomy' => 'product_cat', 'term_id' => 26, 'name' => 'Bakerlux Classic', 'parent' => 22, 'count' => 0],
            ]
        );

        self::assertSame([], $diff['product_cat']['new']);
    }

    public function test_a_term_the_workbook_no_longer_implies_and_holding_no_products_is_removed(): void
    {
        $diff = \CADCO_Import_Term_Diff::compare(
            [self::row('BLC-113', 'CONVECTION OVENS', 'Bakerlux Classic')],
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
            [self::row('BLC-113', 'CONVECTION OVENS', 'Bakerlux Classic')],
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
            [self::row('BLC-113', 'CONVECTION OVENS', 'Bakerlux Classic')],
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
            [self::row('BLC-113', 'CONVECTION OVENS', 'Bakerlux Classic')],
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
            [self::row('X-1', 'CONVECTION OVENS', '2024')],
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
```

- [ ] **Step 2: Run the test and watch it fail**

Run: `composer test -- --filter TermDiffTest`
Expected: FAIL — `class-cadco-import-term-diff.php` does not exist.

- [ ] **Step 3: Implement `CADCO_Import_Term_Diff`**

Build the implied set from `CADCO_Import_Plan::all_terms($rows)` for tags and brands, and from `CADCO_Import_Plan::categories_for($row)` per row for categories (which also gives you the per-child product counts). Compare against `$existing` by **name within taxonomy**, matching how `ensure_term()` resolves terms.

Three rules the tests pin:
- A term the workbook implies and the site lacks → `new`.
- A term the site has, the workbook does not imply, `count === 0` → `removed`.
- Same but `count > 0` → `in_use`, never `removed`.

Skip `Uncategorized` entirely. Cast every name to `(string)` before it reaches a typed parameter or an array of strings.

- [ ] **Step 4: Run the test and watch it pass**

Run: `composer test -- --filter TermDiffTest`
Expected: PASS — 7 tests.

- [ ] **Step 5: Verify against the real workbook**

Create `/tmp/termdiff.php`:

```php
<?php
require_once get_stylesheet_directory() . '/inc/import/class-cadco-import-term-diff.php';
$p    = "/Users/gustavogomez/Documents/Projects/CADCO/Products Excel Spreadsheet latest/Product Index Spreadsheet 2026_Website_CORRECTED.xlsx";
$read = CADCO_Import_Reader::read($p);
$norm = CADCO_Import_Normaliser::normalise($read['rows']);

$existing = [];
foreach (['product_cat', 'product_tag', 'product_brand'] as $tax) {
    foreach (get_terms(['taxonomy' => $tax, 'hide_empty' => false]) as $t) {
        $existing[] = ['taxonomy' => $tax, 'term_id' => $t->term_id, 'name' => $t->name,
                       'parent' => $t->parent, 'count' => $t->count];
    }
}

$diff = CADCO_Import_Term_Diff::compare($norm['rows'], $existing);
foreach ($diff as $tax => $d) {
    printf("%-14s new=%d removed=%d in_use=%d\n", $tax, count($d['new']), count($d['removed']), count($d['in_use']));
}
echo "\ncategory tree:\n";
foreach ($diff['product_cat']['new'] as $parent) {
    printf("  + %s\n", $parent['name']);
    foreach ($parent['children'] as $child) {
        printf("      + %-45s %d items\n", $child['name'], $child['products']);
    }
}
```

Run: `wp eval-file /tmp/termdiff.php`

Expected against an empty site: 4 top-level categories with their children, 26 new tags, 6 new brands, 0 removed, 0 in use. Put the real output in your report. `rm /tmp/termdiff.php` afterwards.

- [ ] **Step 6: Commit**

```bash
git add inc/import/class-cadco-import-term-diff.php tests/unit/TermDiffTest.php
git commit -m "feat(import): diff the derived term tree against the site"
```

---

## Task 2: Restore mode in the planner

Additive and pure. The planner learns to match a workbook row against trashed products, but only when the caller supplies them.

**Files:**
- Modify: `inc/import/class-cadco-import-planner.php`
- Modify: `inc/import/class-cadco-import-plan.php`
- Test: `tests/unit/PlannerTest.php`

**Interfaces:**
- `CADCO_Import_Planner::plan(array $rows, array $current, array $trashed = []): CADCO_Import_Plan`
- `CADCO_Import_Plan::add_untrash(array $row, int $post_id): void`, `->untrashes(): array`, and `untrash` included in `counts()` and `total_writes()`.
- `$trashed` entries have the same shape as `$current`: `['post_id' => int, 'sku' => string, 'upc' => string, 'hash' => string, 'snapshot' => array]`.

- [ ] **Step 1: Write the failing tests**

Add to `tests/unit/PlannerTest.php`:

```php
    public function test_a_normal_import_ignores_trashed_products(): void
    {
        // The asymmetry is deliberate: a product deliberately removed must stay
        // removed. Only an explicit restore may bring one back.
        $plan = \CADCO_Import_Planner::plan([self::row()], []);

        self::assertSame(1, $plan->counts()['create']);
        self::assertSame(0, $plan->counts()['untrash']);
    }

    public function test_restore_untrashes_a_matching_product_by_sku(): void
    {
        $plan = \CADCO_Import_Planner::plan([self::row()], [], [
            self::current(7, 'BLC-113', '654796-52113-5', 'stale'),
        ]);

        self::assertSame(0, $plan->counts()['create'], 'it must be reused, not recreated');
        self::assertSame(1, $plan->counts()['untrash']);
        self::assertSame(7, $plan->untrashes()[0]['post_id']);
    }

    public function test_restore_untrashes_a_matching_product_by_upc_when_the_sku_changed(): void
    {
        $plan = \CADCO_Import_Planner::plan([self::row()], [], [
            self::current(7, 'XAF-113', '654796-52113-5', 'stale'),
        ]);

        self::assertSame(1, $plan->counts()['untrash']);
        self::assertSame(7, $plan->untrashes()[0]['post_id']);
    }

    public function test_a_live_match_always_wins_over_a_trashed_one(): void
    {
        $plan = \CADCO_Import_Planner::plan(
            [self::row(['Weight' => '52'])],
            [self::current(7, 'BLC-113', '654796-52113-5', 'stale')],
            [self::current(9, 'BLC-113', '654796-52113-5', 'stale')]
        );

        self::assertSame(1, $plan->counts()['update']);
        self::assertSame(0, $plan->counts()['untrash']);
        self::assertSame(7, $plan->updates()[0]['post_id']);
    }

    public function test_an_ambiguous_trashed_upc_never_untrashes(): void
    {
        // Same rule as live products: a UPC held by more than one product cannot
        // identify one of them.
        $plan = \CADCO_Import_Planner::plan([self::row()], [], [
            self::current(7, 'OLD-A', '654796-52113-5', 'stale'),
            self::current(8, 'OLD-B', '654796-52113-5', 'stale'),
        ]);

        self::assertSame(0, $plan->counts()['untrash']);
        self::assertSame(1, $plan->counts()['create']);
    }

    public function test_untrashes_count_as_writes(): void
    {
        $plan = \CADCO_Import_Planner::plan([self::row()], [], [
            self::current(7, 'BLC-113', '654796-52113-5', 'stale'),
        ]);

        self::assertSame(1, $plan->total_writes());
    }
```

- [ ] **Step 2: Run and watch them fail**

Run: `composer test -- --filter PlannerTest`
Expected: FAIL on the missing third argument and the missing `untrash` count key.

- [ ] **Step 3: Implement**

Add `add_untrash()` / `untrashes()` to the plan, include `untrash` in `counts()` and in `total_writes()`. In the planner, after the SKU and live-UPC cascade fails and before falling through to `add_create()`, try `$trashed` by SKU then by UPC — using the same `array_key_exists` ambiguity sentinel the live UPC index already uses, so a shared UPC yields no match.

Document at the top of the trashed lookup **why** it is opt-in.

- [ ] **Step 4: Run and watch them pass**

Run: `composer test`
Expected: PASS — 97 tests (91 + 6).

- [ ] **Step 5: Commit**

```bash
git add inc/import/class-cadco-import-planner.php inc/import/class-cadco-import-plan.php tests/unit/PlannerTest.php
git commit -m "feat(import): let a restore reuse a trashed product in place"
```

---

## Task 3: Untrash in the applier and repository

Makes the `untrash` job real, and gives the controller a way to fetch trashed candidates.

**Files:**
- Modify: `inc/import/class-cadco-import-applier.php`
- Modify: `inc/import/class-cadco-import-repository.php`
- Test: live verification via `wp eval-file`

**Interfaces:**
- `CADCO_Import_Repository::trashed_products(): array` — same shape as `current_products()`, for `post_status = 'trash'`.
- `CADCO_Import_Applier::build_queue()` emits `['op' => 'untrash', 'row' => array, 'post_id' => int]`.
- `run_job()` handles `untrash`: `wp_untrash_post()`, force status to `publish`, then `write_product($row, $post_id)`.

- [ ] **Step 1: Add `trashed_products()` to the repository**

Copy `current_products()`'s query, changing the status clause to `p.post_status = 'trash'`. Keep the `wc_product_meta_lookup` join and the `(string)` casts — a trashed product's SKU and UPC can be numeric just as a live one's can.

- [ ] **Step 2: Handle `untrash` in the applier**

In `build_queue()`, emit untrash jobs from `$plan->untrashes()`. In `run_job()`:

```php
        if ($job['op'] === 'untrash') {
            wp_untrash_post((int) $job['post_id']);

            // wp_untrash_post() restores the *previous* status, which for a
            // product trashed while published is 'draft' on modern WordPress.
            // A restore must republish, so the status is set explicitly rather
            // than trusted.
            wp_update_post(['ID' => (int) $job['post_id'], 'post_status' => 'publish']);

            $id = self::write_product($job['row'], (int) $job['post_id']);

            return sprintf('Restored %s (#%d)', $job['row']['Model #'], $id);
        }
```

Keep it inside the existing per-job `try/catch` so a failure logs and the batch continues.

- [ ] **Step 3: Verify live that a restore reuses the post ID**

Create `/tmp/untrash.php`:

```php
<?php
$row = ['__sheet' => 'CONVECTION OVENS', '__row' => 2, 'Model #' => 'UNTRASH-1',
        'UPC#' => '654796-00055-5', 'Product Name' => 'Untrash probe', 'Brand Name' => 'Cadco',
        'Type' => 'Bakerlux Classic', 'Lead Time' => '1-3 business days',
        'Primary Description' => 'probe', 'Supplier Specifications - Bullet Points' => '•probe',
        'Specialties' => '•Hotels', 'Height' => '1', 'Width' => '1', 'Depth' => '1', 'Weight' => '1'];

$id = CADCO_Import_Applier::write_product($row, null);
echo "created post: $id\n";
wp_trash_post($id);
echo "trashed. live products: ", count(CADCO_Import_Repository::current_products()),
     "  trashed: ", count(CADCO_Import_Repository::trashed_products()), "\n";

$plan  = CADCO_Import_Planner::plan([$row], CADCO_Import_Repository::current_products(),
                                    CADCO_Import_Repository::trashed_products());
echo "plan: ", wp_json_encode($plan->counts()), "\n";

$queue = CADCO_Import_Applier::build_queue($plan);
CADCO_Import_Applier::apply_jobs($queue, 0, 10);

$after = CADCO_Import_Repository::current_products();
echo "after restore: live=", count($after), "  same post id: ",
     ((int) ($after[0]['post_id'] ?? 0) === (int) $id ? 'YES' : 'NO'), "\n";
echo "status: ", get_post_status($id), "\n";

wp_delete_post($id, true);
foreach (['product_cat', 'product_tag', 'product_brand'] as $t) {
    foreach (get_terms(['taxonomy' => $t, 'hide_empty' => false]) as $term) {
        if ($term->slug !== 'uncategorized') { wp_delete_term($term->term_id, $t); }
    }
}
delete_option('cadco_import_taxonomy_reset');
echo "cleanup: ", count(CADCO_Import_Repository::current_products()), " products\n";
```

Run: `wp eval-file /tmp/untrash.php`

Expected: `plan` shows `untrash: 1, create: 0`; `same post id: YES`; `status: publish`. If the post ID differs, the untrash path is not being taken — stop and report rather than adjusting. `rm /tmp/untrash.php` afterwards.

- [ ] **Step 4: Confirm nothing regressed**

Run: `composer test` (97 green) and `npm run test:e2e` (17 green).

- [ ] **Step 5: Commit**

```bash
git add inc/import/class-cadco-import-applier.php inc/import/class-cadco-import-repository.php
git commit -m "feat(import): restore a trashed product in place instead of recreating it"
```

---

## Task 4: The archive

Run directories become a versioned history: manifests, labels, restore, count-based retention.

**Files:**
- Create: `inc/import/class-cadco-import-archive.php`
- Modify: `inc/import/class-cadco-import-admin.php` (write the manifest; delegate pruning)
- Test: `tests/unit/ArchiveTest.php` for the pure parts

**Interfaces:**
- `CADCO_Import_Archive::create(string $filename, int $user_id): array` — makes the directory, returns `['run_id' => string, 'dir' => string]`.
- `CADCO_Import_Archive::write_manifest(string $dir, array $manifest): void`
- `CADCO_Import_Archive::mark_applied(string $dir): void`
- `CADCO_Import_Archive::all(): array` — every run's manifest, newest first.
- `CADCO_Import_Archive::get(string $run_id): ?array`
- `CADCO_Import_Archive::set_label(string $run_id, string $label): bool`
- `CADCO_Import_Archive::prune(int $keep = 20): array` — deletes beyond the newest `$keep`, returns the deleted run ids.
- `CADCO_Import_Archive::is_valid_run_id(string $id): bool` — the anchored pattern guard.

- [ ] **Step 1: Write the failing test**

`is_valid_run_id()` is the security-critical piece — it is what stops a crafted id reaching the filesystem. Test it directly:

```php
<?php

declare(strict_types=1);

namespace CADCO\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ArchiveTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../inc/import/class-cadco-import-archive.php';
    }

    public function test_a_well_formed_run_id_is_accepted(): void
    {
        self::assertTrue(\CADCO_Import_Archive::is_valid_run_id('2026-08-12-091412-1-a7Kd93mQx0Lp'));
    }

    public function test_traversal_and_malformed_ids_are_rejected(): void
    {
        foreach ([
            '../../../etc/passwd',
            '2026-08-12-091412-1-a7Kd93mQx0Lp/../..',
            '2026-08-12-091412-1-short',
            '2026-08-12-091412-1-a7Kd93mQx0Lp.txt',
            'not-a-run-id',
            '',
            '2026-08-12-091412-1-a7Kd93mQx0L/',
        ] as $bad) {
            self::assertFalse(\CADCO_Import_Archive::is_valid_run_id($bad), "must reject: $bad");
        }
    }
}
```

- [ ] **Step 2: Run and watch it fail**

Run: `composer test -- --filter ArchiveTest`

- [ ] **Step 3: Implement the archive**

Move `guard_imports_dir()` and the directory-naming logic out of the admin class into `create()`. Keep writing `index.php` and `.htaccess` guards.

`prune()` replaces the 7-day age-based collector. Sort run directories by name descending — the `Y-m-d-His` prefix sorts chronologically — keep the first `$keep`, delete the rest. Carry over the existing safety properties verbatim: the anchored name pattern, skipping symlinks at both the directory and file level, no recursion into unexpected subdirectories, and defensive handling of every failing syscall.

`all()` reads only `manifest.json` files, never workbooks.

- [ ] **Step 4: Run and watch it pass**

Run: `composer test` — 99 green.

- [ ] **Step 5: Wire the manifest into the upload path**

In `handle_upload()`, use `CADCO_Import_Archive::create()`, write the manifest with `applied: false` after the pipeline runs, and call `prune(20)` instead of the old collector. Call `mark_applied()` when the final batch completes.

- [ ] **Step 6: Verify retention live**

Create 25 fake run directories with valid names and manifests, call `prune(20)`, and confirm exactly the 5 oldest are deleted and the 20 newest survive. Also confirm a directory whose name does not match the pattern is untouched. Put the real output in your report.

- [ ] **Step 7: Commit**

```bash
git add inc/import/class-cadco-import-archive.php inc/import/class-cadco-import-admin.php tests/unit/ArchiveTest.php
git commit -m "feat(import): keep the last 20 runs as a versioned archive"
```

---

## Task 5: The view layer

All rendering moves out of the controller. No behaviour change — this is a pure extraction, verified by the E2E suite staying green.

**Files:**
- Create: `inc/import/class-cadco-import-view.php`
- Modify: `inc/import/class-cadco-import-admin.php`

**Interfaces:**
- `CADCO_Import_View::shell(string $state, array $context): void` — tabs, stage bar, CTA.
- `CADCO_Import_View::upload_form(): void`
- `CADCO_Import_View::report(CADCO_Import_Report $report): void`
- `CADCO_Import_View::review(array $context): void` — sidebar plus the selected section.
- `CADCO_Import_View::history(array $runs): void`

- [ ] **Step 1: Move rendering verbatim**

Move `render_form()`, `render_report()`, `render_plan()`, the update/create/trash tables, the renames table and the changes table into the view **unchanged**. The controller keeps `render()` as a state resolver that calls the view.

Do not redesign anything in this task. The point is to land the extraction with the suite proving nothing broke.

- [ ] **Step 2: Verify nothing changed**

Run: `composer test` (99) and `npm run test:e2e` (17). Every E2E test drives the real screen, so a green suite is the proof that the extraction was faithful.

- [ ] **Step 3: Commit**

```bash
git add inc/import/class-cadco-import-view.php inc/import/class-cadco-import-admin.php
git commit -m "refactor(import): move rendering into a view class"
```

---

## Task 6: The wizard shell

**Files:**
- Modify: `inc/import/class-cadco-import-view.php`
- Modify: `assets/css/import-admin.css`
- Modify: `inc/import/class-cadco-import-admin.php` (tab routing)

- [ ] **Step 1: Build the shell**

Tabs (`Import`, `History`) using WordPress's `nav-tab` convention so it looks native. Then the stage bar:

```
 ✓ Complete          ● Current           ○ Waiting
 1. Upload           2. Review           3. Apply      [ Apply plan ]
 Product Index…xlsx  236 rows, 0 issues  nothing written yet
```

Each stage: status word, numbered title, subtitle carrying real figures. The CTA is `disabled` unless the state is `review`. On `invalid`, label it with why it cannot proceed.

- [ ] **Step 2: Style it**

The design language: generous whitespace, a clear type hierarchy, status colour used sparingly and meaningfully — green for complete, blue for current, grey for waiting, red only for blocking problems. Use WordPress's own colour variables where they exist so the screen ages with the admin rather than against it.

Keep the CSS readable and grouped by component with comments. It will grow past 68 lines; that is expected.

- [ ] **Step 3: Verify**

Load `Products → Import` and confirm the shell renders in all states: upload, invalid (upload the source workbook), review (upload the corrected workbook). Confirm the CTA is disabled on invalid and enabled on review.

Run both suites.

- [ ] **Step 4: Commit**

```bash
git add inc/import/class-cadco-import-view.php assets/css/import-admin.css inc/import/class-cadco-import-admin.php
git commit -m "feat(import): add the three-stage wizard shell"
```

---

## Task 7: The review sections

The heart of the feature. Sidebar navigator plus eight sections.

**Files:**
- Modify: `inc/import/class-cadco-import-view.php`
- Modify: `assets/js/import-admin.js`
- Modify: `assets/css/import-admin.css`

- [ ] **Step 1: The change navigator**

Sidebar listing every section with its count. A zero-count section renders muted and non-interactive — the absence of renames is information. Mark the active section.

Navigation is client-side: all sections render into the page, the JS shows one at a time. That keeps the plan in memory and avoids a round trip per section.

- [ ] **Step 2: The sections**

| Section | Content |
|---|---|
| Workbook | Filename, size, sheet names, rows per sheet, validation status |
| Categories | New terms as a tree with per-child product counts; removed terms; **in-use warnings** |
| Products | To create: Model #, name, categories, brand |
| Updates | Per-field diffs (already built — reuse the existing table) |
| Renames | Old → new, UPC, approval checkbox (already built) |
| Removals | To trash, with a note that trash is recoverable |
| Cleaned up | Normalisation changes (already built) |
| Redirects | Legacy path → new URL |

The Categories section is the new one. Render `in_use` terms as a distinct, visually louder block: these will **not** be removed, products still reference them, and only a person can resolve the disagreement between workbook and site.

Every table caps its rows and states how many were hidden when it truncates.

- [ ] **Step 3: Escaping pass**

Every value in every section comes from the workbook. `esc_html()` text, `esc_attr()` attributes, `esc_url()` links. Read your own diff once specifically looking for an unescaped interpolation before moving on.

- [ ] **Step 4: Verify**

Upload the corrected workbook and walk every section. Confirm the category tree shows 4 parents with their children and per-child counts.

Then construct the in-use case deliberately: import the corrected workbook, apply it, then import a **modified copy** with one `Type` renamed, and confirm the old term appears under the in-use warning with its product count rather than under removals. Put the output in your report.

Run both suites.

- [ ] **Step 5: Commit**

```bash
git add inc/import/class-cadco-import-view.php assets/js/import-admin.js assets/css/import-admin.css
git commit -m "feat(import): review every change before applying it"
```

---

## Task 8: History and restore

**Files:**
- Modify: `inc/import/class-cadco-import-view.php`
- Modify: `inc/import/class-cadco-import-admin.php`
- Modify: `assets/js/import-admin.js`

- [ ] **Step 1: The history table**

Date, label, original filename, result (counts, or the issue count for a failed run), and actions. `Restore` for a run with a workbook; `View report` for a failed one.

Read manifests only.

- [ ] **Step 2: Inline label editing**

An AJAX endpoint, nonce- and capability-checked, calling `CADCO_Import_Archive::set_label()`. Validate the run id with `is_valid_run_id()` **before** it touches the filesystem. Escape the label on output.

- [ ] **Step 3: Restore**

A `restore` action takes a run id, validates it, loads that run's archived workbook through the normal pipeline, and lands on **Review** — with `$trashed` supplied from `CADCO_Import_Repository::trashed_products()` so untrash jobs can be planned.

Show a restore banner on Review naming the run and its date, so a restore can never be mistaken for a fresh import.

- [ ] **Step 4: Verify the full loop live**

1. Import the corrected workbook. Confirm a history row appears with `applied: true`.
2. Label it. Reload. Confirm the label persisted.
3. Import a modified copy that removes a product. Apply. Confirm that product is trashed.
4. Restore the first run. Confirm Review shows the restore banner, the plan includes **1 untrash**, and applying it brings the product back **with its original post ID**.

That last assertion is the whole point of the feature — capture the post IDs before and after and put them in your report.

Run both suites.

- [ ] **Step 5: Commit**

```bash
git add inc/import/class-cadco-import-view.php inc/import/class-cadco-import-admin.php assets/js/import-admin.js
git commit -m "feat(import): restore a previous workbook as a restore point"
```

---

## Task 9: E2E coverage

**Files:**
- Modify: `tests/e2e/import.spec.js`
- Modify: `tests/e2e/helpers.js`

- [ ] **Step 1: Add tests**

- Every review section is reachable from the sidebar and renders its table.
- The category tree shows parents with nested children.
- The in-use warning appears when a term with products is no longer implied.
- The CTA is disabled on an invalid workbook and enabled on a clean one.
- The History tab lists a run after an import.
- Editing a label persists across a reload.
- Restoring a run lands on Review and shows the restore banner.
- A restore untrashes rather than duplicating: assert the post ID is unchanged.
- Retention prunes at 21 runs.

- [ ] **Step 2: Run the suite**

Run: `npm run test:e2e` — expect roughly 26 tests. All must pass. If one fails because the product code is wrong, fix the product code and say so; do not weaken the test.

- [ ] **Step 3: Commit**

```bash
git add tests/e2e/
git commit -m "test(e2e): cover the wizard, history and restore"
```

---

## Task 10: Documentation

**Files:**
- Modify: `README.md`

- [ ] **Step 1: Document the interface and versioning**

Under the existing Product import section: the three stages, what each review section shows, how restore works and what it does to trashed products, the 20-run retention, and where runs are archived.

State plainly that a restore is re-validated and re-planned against the current catalogue rather than replayed, and that the plan preview shows exactly what it will do.

- [ ] **Step 2: Commit**

```bash
git add README.md
git commit -m "docs(import): document the wizard, history and restore"
```

---

## Verification checklist

- [ ] `composer test` green (≈99 tests)
- [ ] `npm run test:e2e` green (≈26 tests)
- [ ] Corrected workbook still validates to 0 issues; source workbook still fails with 266
- [ ] The wizard renders in all states: upload, invalid, review, applying, done
- [ ] The CTA is disabled unless Review is clean
- [ ] Every review section reachable and correct
- [ ] The category tree shows parents, children and per-child product counts
- [ ] A term still holding products appears as in-use, never as a removal
- [ ] History lists runs newest first; labels persist
- [ ] Restore lands on Review with the banner, and untrashes rather than duplicating
- [ ] Retention keeps exactly 20 runs
- [ ] A crafted run id cannot escape the archive directory
- [ ] Site left at 0 products, 0 terms, 0 attribute taxonomies, no leftover options
- [ ] Nothing purchasable; `inc/cadco-woocommerce.php` untouched
- [ ] No commit message references the tooling used to write it
