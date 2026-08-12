<?php

/**
 * Generates small, purpose-built .xlsx workbooks for scenarios the real
 * 236-product workbook cannot exercise cheaply: a title containing a script
 * tag, and a rename whose UPC matches a product seeded directly in the
 * database. Reuses tests/fixtures/FixtureBuilder.php — the same generator
 * the PHP unit suite already trusts — so these workbooks are structurally
 * identical to the ones ReaderTest/PlannerTest build, just with one
 * deliberately unusual row.
 *
 * Run via `wp eval-file tests/e2e/fixtures/build.php <scenario> <output-path>`.
 * $args is populated by WP-CLI from the positional arguments.
 *
 * No `declare(strict_types=1)` here: WP-CLI's `eval-file` runs the file's
 * contents through eval() rather than include(), and strict_types is one of
 * the declares PHP refuses inside eval()'d code.
 */

$theme = dirname(__DIR__, 3);

require_once $theme . '/vendor/autoload.php';
require_once $theme . '/inc/import/field-map.php';
require_once $theme . '/tests/fixtures/FixtureBuilder.php';

use CADCO\Tests\Fixtures\FixtureBuilder;

[$scenario, $out] = $args;

$sheets = cadco_import_sheets();
$target = $sheets[0]; // 'CONVECTION OVENS'

switch ($scenario) {
    case 'xss':
        FixtureBuilder::write($out, FixtureBuilder::completeSheets([
            $target => [FixtureBuilder::row([
                'Model #'      => 'E2E-XSS-1',
                'UPC#'         => '654796-99030-1',
                'Product Name' => 'XSS <script>window.__xss=1</script> Probe',
            ])],
        ]));
        break;

    case 'trash-full':
        // Two independent products. Paired with 'trash-reduced' below, which
        // carries only the first: applying 'trash-reduced' after this one
        // must trash the second rather than touching the first.
        FixtureBuilder::write($out, FixtureBuilder::completeSheets([
            $target => [
                FixtureBuilder::row([
                    'Model #'      => 'E2E-TRASH-1',
                    'UPC#'         => '654796-99040-1',
                    'Product Name' => 'Trash Test — kept',
                ]),
                FixtureBuilder::row([
                    'Model #'      => 'E2E-TRASH-2',
                    'UPC#'         => '654796-99040-2',
                    'Product Name' => 'Trash Test — removed',
                ]),
            ],
        ]));
        break;

    case 'trash-reduced':
        // Identical first row to 'trash-full', second row simply absent —
        // the plan this produces against a catalogue built from
        // 'trash-full' must offer exactly one trash and zero creates.
        FixtureBuilder::write($out, FixtureBuilder::completeSheets([
            $target => [FixtureBuilder::row([
                'Model #'      => 'E2E-TRASH-1',
                'UPC#'         => '654796-99040-1',
                'Product Name' => 'Trash Test — kept',
            ])],
        ]));
        break;

    case 'xss-review':
        // Covers the Review screen itself, before any apply — the existing
        // 'xss' scenario only ever proved the *front-end product page*
        // strips markup post-apply (CADCO_Import_Applier::write_product()
        // running Product Name through wp_strip_all_tags()). Nothing had
        // exercised the review tables (Task 7) that render straight from
        // the still-raw, unstripped workbook row: create_table()'s Product
        // Name column and section_categories()'s new-category tree, both of
        // which rely on esc_html() rather than stripping. The payload lands
        // in both a product name and a Type (category), one row, so a
        // single upload exercises both.
        FixtureBuilder::write($out, FixtureBuilder::completeSheets([
            $target => [FixtureBuilder::row([
                'Model #'      => 'E2E-XSS-REVIEW-1',
                'UPC#'         => '654796-99031-1',
                'Product Name' => 'XSS Review <script>window.__xssReview=1</script> Probe',
                'Type'         => 'XssCategory <script>window.__xssReviewCat=1</script> Marker',
            ])],
        ]));
        break;

    case 'rename':
        // UPC must match the product build.php's caller seeds directly in
        // the database beforehand (see helpers.js: seedRenameSource()); the
        // model number deliberately differs so the planner offers a rename
        // rather than a plain update.
        FixtureBuilder::write($out, FixtureBuilder::completeSheets([
            $target => [FixtureBuilder::row([
                'Model #'      => 'E2E-RENAME-NEW-1',
                'UPC#'         => '654796-99020-1',
                'Product Name' => 'Rename Test — after',
            ])],
        ]));
        break;

    case 'history-run':
        // A single plain, valid row — used wherever a test only needs a
        // fast, clean check to produce one History-tab archive entry, with
        // nothing scenario-specific about its contents.
        FixtureBuilder::write($out, FixtureBuilder::completeSheets([
            $target => [FixtureBuilder::row([
                'Model #'      => 'E2E-HISTORY-1',
                'UPC#'         => '654796-99060-1',
                'Product Name' => 'History Test',
            ])],
        ]));
        break;

    case 'category-tree':
        // Two rows on the same sheet, two distinct 'Type' values — the
        // sheet name becomes the new parent category, each distinct Type
        // becomes one of its new children (CADCO_Import_Plan::categories_for()).
        // 'Ranges'/'Griddles' rather than near-spellings of each other:
        // the validator's Tier B consistency check would otherwise flag two
        // Type values that differ by one letter as the same misspelt value
        // and fail the workbook before Review is ever reached.
        FixtureBuilder::write($out, FixtureBuilder::completeSheets([
            $target => [
                FixtureBuilder::row([
                    'Model #'      => 'E2E-CATTREE-1',
                    'UPC#'         => '654796-99090-1',
                    'Product Name' => 'Category Tree Test — Ranges',
                    'Type'         => 'Ranges',
                ]),
                FixtureBuilder::row([
                    'Model #'      => 'E2E-CATTREE-2',
                    'UPC#'         => '654796-99090-2',
                    'Product Name' => 'Category Tree Test — Griddles',
                    'Type'         => 'Griddles',
                ]),
            ],
        ]));
        break;

    case 'in-use-a':
        // First half of the in-use-warning scenario: applied for real, so
        // 'Warmers' becomes a real product_cat term with a real product
        // count once this is applied.
        FixtureBuilder::write($out, FixtureBuilder::completeSheets([
            $target => [FixtureBuilder::row([
                'Model #'      => 'E2E-INUSE-1',
                'UPC#'         => '654796-99070-1',
                'Product Name' => 'In Use Test',
                'Type'         => 'Warmers',
            ])],
        ]));
        break;

    case 'in-use-b':
        // Second half: same product, re-categorised under 'Fryers' — never
        // applied, only checked, so 'Warmers' stays on the catalogue's one
        // real product while this workbook no longer implies it at all. The
        // term diff must report 'Warmers' as still in use, not removed.
        FixtureBuilder::write($out, FixtureBuilder::completeSheets([
            $target => [FixtureBuilder::row([
                'Model #'      => 'E2E-INUSE-1',
                'UPC#'         => '654796-99070-1',
                'Product Name' => 'In Use Test',
                'Type'         => 'Fryers',
            ])],
        ]));
        break;

    case 'restore-a':
        // The run a restore test restores FROM: one product, applied for
        // real so it exists on the catalogue and this run's own History
        // entry is a genuine applied run.
        FixtureBuilder::write($out, FixtureBuilder::completeSheets([
            $target => [FixtureBuilder::row([
                'Model #'      => 'E2E-RESTORE-1',
                'UPC#'         => '654796-99050-1',
                'Product Name' => 'Restore Test',
            ])],
        ]));
        break;

    case 'restore-b':
        // Applied after 'restore-a': a different product entirely, so
        // E2E-RESTORE-1 (missing from this workbook) is trashed by this
        // import — the state a restore of 'restore-a' must undo, in place.
        FixtureBuilder::write($out, FixtureBuilder::completeSheets([
            $target => [FixtureBuilder::row([
                'Model #'      => 'E2E-RESTORE-BG-1',
                'UPC#'         => '654796-99050-2',
                'Product Name' => 'Restore Test — background',
            ])],
        ]));
        break;

    case 'sections-v1':
        // First half of the "every review section" fixture pair: applied
        // for real, so sections-v2 (below) has real prior state to diff an
        // update and a removal against.
        FixtureBuilder::write($out, FixtureBuilder::completeSheets([
            $target => [
                FixtureBuilder::row([
                    'Model #'      => 'E2E-SECT-KEEP-1',
                    'UPC#'         => '654796-99080-1',
                    'Product Name' => 'Sections Test — kept',
                    'Type'         => 'Ranges',
                    'Weight'       => '10',
                ]),
                FixtureBuilder::row([
                    'Model #'      => 'E2E-SECT-GONE-1',
                    'UPC#'         => '654796-99080-2',
                    'Product Name' => 'Sections Test — removed',
                    'Type'         => 'Ranges',
                ]),
            ],
        ]));
        break;

    case 'sections-v2':
        // Checked (never applied) against the catalogue sections-v1 left
        // behind. Every one of the eight Review sections has something
        // real to show:
        //  - Workbook: rows read (always non-zero).
        //  - Categories: 'Griddles' is a brand new child.
        //  - Products: E2E-SECT-NEW2-1 is a new create.
        //  - Updates: E2E-SECT-KEEP-1's Weight changed 10 -> 11, and its
        //    Website URL changed blank -> filled. Its Product Name is NOT
        //    a genuine diff despite the double space below: the
        //    normaliser's whitespace pass collapses 'Sections  Test —
        //    kept' back to the exact string sections-v1 already wrote,
        //    'Sections Test — kept'.
        //  - Renames: matches the UPC seedRenameSource() seeds directly in
        //    the database under a different model number (see helpers.js).
        //  - Removals: E2E-SECT-GONE-1 is missing from this workbook.
        //  - Cleaned up: the double space in this row's Product Name is
        //    collapsed by the normaliser's whitespace pass.
        //  - Redirects: a 'Website URL' shaped like a real legacy product
        //    page (CADCO_Import_Planner::legacy_path()).
        FixtureBuilder::write($out, FixtureBuilder::completeSheets([
            $target => [
                FixtureBuilder::row([
                    'Model #'      => 'E2E-SECT-KEEP-1',
                    'UPC#'         => '654796-99080-1',
                    'Product Name' => 'Sections  Test — kept',
                    'Type'         => 'Ranges',
                    'Weight'       => '11',
                    'Website URL'  => 'http://www.cadco-ltd.com/product/e2e-sect-keep-1',
                ]),
                FixtureBuilder::row([
                    'Model #'      => 'E2E-SECT-NEW2-1',
                    'UPC#'         => '654796-99080-4',
                    'Product Name' => 'Sections Test — new',
                    'Type'         => 'Griddles',
                ]),
                FixtureBuilder::row([
                    'Model #'      => 'E2E-SECT-RENAME-NEW-1',
                    'UPC#'         => '654796-99080-3',
                    'Product Name' => 'Sections Test — renamed',
                    'Type'         => 'Ranges',
                ]),
            ],
        ]));
        break;

    default:
        fwrite(STDERR, "Unknown fixture scenario: {$scenario}\n");
        exit(1);
}

echo "wrote {$out}\n";
