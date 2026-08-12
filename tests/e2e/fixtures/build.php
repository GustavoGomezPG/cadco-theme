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

    default:
        fwrite(STDERR, "Unknown fixture scenario: {$scenario}\n");
        exit(1);
}

echo "wrote {$out}\n";
