# CADCO Product Import Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build an Excel-driven product import system for the CADCO catalogue site that reports every data inconsistency, previews exactly what it will change, and applies nothing until the workbook is clean.

**Architecture:** A six-unit pipeline — Reader → Normaliser → Validator → Planner → Applier → Reporter. The first four units are pure PHP requiring no WordPress, so they are unit-tested directly. The dry run is not a separate mode: it is the pipeline stopping after Planner, so the preview and the applied result come from one code path. WordPress access is confined to a Repository (reads) and the Applier (writes).

**Tech Stack:** PHP 8.2, WordPress, WooCommerce 11.0.0, PhpSpreadsheet (XLSX parsing), PHPUnit 10 (testing), Composer.

## Global Constraints

- **Source of truth:** `Product Index Spreadsheet 2026_Website_.xlsx`. Anything edited in wp-admin is overwritten on the next import.
- **Code location:** `wp-content/themes/cadco-theme/inc/import/`. Branch: `feat/product-import`.
- **Columns are read by header name, never by index.** Sheets and columns may be reordered; they must not be renamed.
- **Sheets whose name begins with `_` are ignored.** Any other unrecognised sheet is a Tier A error.
- **Validation is all-or-nothing.** Any issue in any of the three tiers blocks the entire import. Nothing is written.
- **Identity:** `Model #` is the primary key. `UPC#` corroborates and is used only to detect renames. Renames are never applied without per-item approval.
- **Canonical UPC shape:** `NNNNNN-NNNNN-N`. Anything else is a Tier A error.
- **Categories are fully derived** from sheet name (top level) + `Type` (sub-level). All 26 pre-existing `product_cat` terms are deleted. No override file.
- **Removed products are moved to Trash**, never permanently deleted.
- **Media is out of scope.** URLs are stored as meta for a later phase; nothing is downloaded.
- **Commerce stays off.** Never re-enable purchasing, and never touch `inc/cadco-woocommerce.php` behaviour.
- **No AI attribution in any commit message or PR body.** Plain `<type>(<scope>): <subject>` only.
- **PHP 8.2**, `declare(strict_types=1)` at the top of every new PHP file.
- **Text domain:** `cadco-theme`.

## File Structure

| File | Responsibility |
|---|---|
| `composer.json` | PhpSpreadsheet + PHPUnit; PSR-4 autoload for tests |
| `.gitattributes` | `export-ignore` for tests/dev files; `vendor/` **must** ship |
| `phpunit.xml` | Test suite config |
| `inc/import/field-map.php` | Pure data: sheets, column→destination map, required/`n/a` field lists |
| `inc/import/class-cadco-import-reader.php` | XLSX → rows keyed by header name + structural errors |
| `inc/import/class-cadco-import-normaliser.php` | Trim, whitespace, bullets, title-case, multi-value split, tag canonicalisation |
| `inc/import/class-cadco-import-issue.php` | One validation issue (tier, sheet, row, column, values, message) |
| `inc/import/class-cadco-import-report.php` | Collection of issues; pass/fail; grouping |
| `inc/import/class-cadco-import-validator.php` | The three tiers |
| `inc/import/class-cadco-import-plan.php` | Creates / updates / renames / trashes / terms |
| `inc/import/class-cadco-import-planner.php` | Diffs normalised rows against injected current state |
| `inc/import/class-cadco-import-repository.php` | WordPress reads: current products, term lookups |
| `inc/import/class-cadco-import-applier.php` | Batched writes; taxonomy first, single rewrite flush last |
| `inc/import/class-cadco-import-admin.php` | Products → Import screen, upload, AJAX batching |
| `inc/import/class-cadco-product-meta-box.php` | CADCO Specifications metabox |
| `inc/import/bootstrap.php` | Loads the unit files, registers hooks |
| `tests/fixtures/make-fixtures.php` | Builds small XLSX fixtures programmatically |
| `tests/unit/*Test.php` | PHPUnit tests for the pure units |

---

## Task 1: Composer, PhpSpreadsheet and the test harness

Nothing else can be tested until this exists. This task delivers a running test suite with one passing smoke test.

**Files:**
- Create: `composer.json`
- Create: `phpunit.xml`
- Create: `.gitattributes`
- Create: `tests/unit/HarnessTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `vendor/autoload.php`; the command `composer test`; namespace `CADCO\Tests\` → `tests/`.

- [ ] **Step 1: Create `composer.json`**

```json
{
    "name": "cadco/cadco-theme",
    "description": "CADCO catalogue theme with the Excel product import system.",
    "type": "wordpress-theme",
    "license": "proprietary",
    "require": {
        "php": ">=8.2",
        "phpoffice/phpspreadsheet": "^3.4"
    },
    "require-dev": {
        "phpunit/phpunit": "^10.5"
    },
    "autoload-dev": {
        "psr-4": {
            "CADCO\\Tests\\": "tests/"
        }
    },
    "config": {
        "optimize-autoloader": true,
        "sort-packages": true
    },
    "scripts": {
        "test": "phpunit"
    }
}
```

- [ ] **Step 2: Create `phpunit.xml`**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
         failOnWarning="true"
         failOnRisky="true"
         cacheDirectory=".phpunit.cache">
    <testsuites>
        <testsuite name="unit">
            <directory>tests/unit</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

- [ ] **Step 3: Create `.gitattributes`**

`vendor/` is deliberately NOT export-ignored — the WP Engine deploy has no build step, so the dependency must ship inside the theme.

```
/tests            export-ignore
/docs             export-ignore
/phpunit.xml      export-ignore
/composer.json    export-ignore
/composer.lock    export-ignore
/.gitattributes   export-ignore
/.phpunit.cache   export-ignore
/screenshot.png   -export-ignore
```

- [ ] **Step 4: Write the failing smoke test**

Create `tests/unit/HarnessTest.php`:

```php
<?php

declare(strict_types=1);

namespace CADCO\Tests\Unit;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\TestCase;

final class HarnessTest extends TestCase
{
    public function test_phpspreadsheet_is_available(): void
    {
        $book = new Spreadsheet();
        $book->getActiveSheet()->setCellValue('A1', 'Model #');

        self::assertSame('Model #', $book->getActiveSheet()->getCell('A1')->getValue());
    }
}
```

- [ ] **Step 5: Run the test to verify it fails**

Run: `composer test`
Expected: FAIL — `composer.lock` and `vendor/` do not exist yet, so the command errors with "Composer autoload not found" or PHPUnit is missing.

- [ ] **Step 6: Install dependencies**

```bash
cd "wp-content/themes/cadco-theme"
composer install
```

Expected: `vendor/` created, `composer.lock` written.

- [ ] **Step 7: Run the test to verify it passes**

Run: `composer test`
Expected: PASS — `OK (1 test, 1 assertion)`

- [ ] **Step 8: Add a `.gitignore` entry for the PHPUnit cache**

Append to `.gitignore` (create the file if it does not exist):

```
.phpunit.cache/
.DS_Store
```

- [ ] **Step 9: Commit**

`vendor/` is committed on purpose — see Step 3.

```bash
git add composer.json composer.lock phpunit.xml .gitattributes .gitignore tests/ vendor/
git commit -m "build(import): add composer, phpspreadsheet and the phpunit harness"
```

---

## Task 2: The field map

Pure data with no logic. Every later unit reads its column rules from here, so getting the lists right now prevents them being restated (and drifting) in four places.

**Files:**
- Create: `inc/import/field-map.php`
- Test: `tests/unit/FieldMapTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `cadco_import_sheets(): array` — the 4 canonical sheet names, uppercase.
  - `cadco_import_required_fields(): array` — hard-required column names.
  - `cadco_import_na_fields(): array` — columns where `n/a` passes but blank does not.
  - `cadco_import_consistency_columns(): array` — columns checked for variant spellings.
  - `cadco_import_attribute_columns(): array` — column name → attribute slug.
  - `cadco_import_meta_columns(): array` — column name → meta key (without `_cadco_` prefix).
  - `cadco_import_ignored_columns(): array` — columns deliberately dropped.
  - `cadco_import_upc_pattern(): string` — the canonical UPC regex.

- [ ] **Step 1: Write the failing test**

Create `tests/unit/FieldMapTest.php`:

```php
<?php

declare(strict_types=1);

namespace CADCO\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class FieldMapTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../inc/import/field-map.php';
    }

    public function test_the_four_canonical_sheets_are_listed(): void
    {
        self::assertSame(
            ['CONVECTION OVENS', 'FAST COOKING OVENS', 'COUNTERTOP EQUIPMENT', 'FOODSERVICE CARTS'],
            cadco_import_sheets()
        );
    }

    public function test_identity_and_core_content_are_required(): void
    {
        $required = cadco_import_required_fields();

        foreach (['UPC#', 'Model #', 'Product Name', 'Type', 'Primary Description', 'Weight'] as $column) {
            self::assertContains($column, $required, "$column must be hard-required");
        }
    }

    public function test_required_and_na_lists_do_not_overlap(): void
    {
        self::assertSame(
            [],
            array_intersect(cadco_import_required_fields(), cadco_import_na_fields()),
            'A column cannot be both hard-required and n/a-acceptable'
        );
    }

    public function test_unit_of_measure_columns_are_ignored(): void
    {
        $ignored = cadco_import_ignored_columns();

        self::assertContains('Height Unit of Measure', $ignored);
        self::assertContains('Package Weight Unit Of Measure', $ignored);
        self::assertContains('Primary Category', $ignored);
        self::assertContains('Other', $ignored);
    }

    public function test_numeric_specs_are_meta_not_attributes(): void
    {
        $attributes = cadco_import_attribute_columns();
        $meta       = cadco_import_meta_columns();

        self::assertArrayHasKey('Package Weight', $meta);
        self::assertArrayNotHasKey('Package Weight', $attributes);
        self::assertArrayHasKey('Wattage', $meta);
        self::assertArrayNotHasKey('Wattage', $attributes);
    }

    public function test_categorical_specs_are_attributes(): void
    {
        $attributes = cadco_import_attribute_columns();

        foreach (['Material', 'Color', 'Voltage', 'Plug Type', 'Certifications', 'Lead Time'] as $column) {
            self::assertArrayHasKey($column, $attributes, "$column should be an attribute");
        }
    }

    public function test_a_column_has_exactly_one_destination(): void
    {
        $overlap = array_intersect(
            array_keys(cadco_import_attribute_columns()),
            array_keys(cadco_import_meta_columns())
        );

        self::assertSame([], $overlap, 'A column cannot be both an attribute and meta');
    }

    public function test_upc_pattern_accepts_canonical_and_rejects_malformed(): void
    {
        $pattern = cadco_import_upc_pattern();

        self::assertSame(1, preg_match($pattern, '654796-52113-5'));
        self::assertSame(0, preg_match($pattern, '654796-9991-3'), 'four-digit middle group');
        self::assertSame(0, preg_match($pattern, '654796-+28:3052193-7'), 'formula artifact');
        self::assertSame(0, preg_match($pattern, ''), 'blank');
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test -- --filter FieldMapTest`
Expected: FAIL — `failed to open stream: No such file or directory ... inc/import/field-map.php`

- [ ] **Step 3: Create `inc/import/field-map.php`**

```php
<?php

/**
 * Which spreadsheet column goes where.
 *
 * Pure data, deliberately. Every unit in the import pipeline reads its column
 * rules from this file so that the rules exist in exactly one place — the
 * validator, the planner and the applier must never restate them.
 *
 * Column names are the literal header text from the workbook, including its
 * spacing and punctuation quirks ('Model #', '(UPS/FedEx or LTL?)'). They are
 * matched by name because column *positions* differ between sheets of the same
 * file and change between revisions.
 */

declare(strict_types=1);

/**
 * The sheets the importer recognises, in canonical order.
 *
 * Matched case-insensitively. A sheet whose name begins with '_' is skipped
 * so CADCO can keep working notes in the workbook; any other unrecognised
 * sheet is a Tier A error rather than a silent skip.
 */
function cadco_import_sheets(): array
{
    return [
        'CONVECTION OVENS',
        'FAST COOKING OVENS',
        'COUNTERTOP EQUIPMENT',
        'FOODSERVICE CARTS',
    ];
}

/**
 * The canonical UPC shape: 654796-52113-5.
 *
 * Presence alone is not enough. The source workbook contained
 * '654796-+28:3052193-7' — a corrupted formula artifact — which a
 * non-empty check would have accepted and imported as a product identifier.
 */
function cadco_import_upc_pattern(): string
{
    return '/^\d{6}-\d{5}-\d$/';
}

/**
 * Columns that must carry a real value. Blank is a Tier C error.
 */
function cadco_import_required_fields(): array
{
    return [
        'UPC#',
        'Model #',
        'Product Name',
        'Type',
        'Brand Name',
        'Lead Time',
        'Primary Description',
        'Supplier Specifications - Bullet Points',
        'Height',
        'Width',
        'Depth',
        'Weight',
    ];
}

/**
 * Columns where "not applicable" is legitimate but must be said out loud.
 *
 * An explicit 'n/a' passes; a blank does not. A blank cell cannot be
 * distinguished from "nobody has filled this in yet", and the whole point of
 * the strict policy is to remove that ambiguity.
 */
function cadco_import_na_fields(): array
{
    return [
        'Certifications',
        'Wattage',
        'Voltage',
        'Amps',
        'Plug Type',
        'Freight Class',
        '(UPS/FedEx or LTL?)',
        'Package Height',
        'Package Width',
        'Package Length',
        'Package Weight',
        'Country Of Origin',
        'Material',
        'Color',
        'Affected by Prop 65 Yes or No',
    ];
}

/**
 * Columns checked for the same value spelled several ways.
 *
 * 'Specialties' is multi-valued (one tag per line) and is handled line by line.
 */
function cadco_import_consistency_columns(): array
{
    return [
        'Specialties',
        'Color',
        'Material',
        'Certifications',
        'Country Of Origin',
        'Plug Type',
        'Lead Time',
        'Type',
    ];
}

/**
 * Columns that become WooCommerce product attributes.
 *
 * Chosen because each is genuinely categorical and worth filtering by. The
 * numeric columns are deliberately absent — Package Weight alone would create
 * 114 terms nobody would ever filter on. Values are the attribute slug, which
 * WooCommerce prefixes with 'pa_'.
 */
function cadco_import_attribute_columns(): array
{
    return [
        'Material'            => 'material',
        'Color'               => 'color',
        'Voltage'             => 'voltage',
        'Plug Type'           => 'plug-type',
        'Certifications'      => 'certifications',
        'Lead Time'           => 'lead-time',
        'Country Of Origin'   => 'country-of-origin',
        'Freight Class'       => 'freight-class',
        '(UPS/FedEx or LTL?)' => 'shipping-method',
        'Size'                => 'size',
        'Capacity'            => 'capacity',
    ];
}

/**
 * Attribute columns whose cell holds several comma-separated values.
 */
function cadco_import_multi_value_attributes(): array
{
    return ['Certifications'];
}

/**
 * Columns stored as post meta, keyed by the suffix after '_cadco_'.
 */
function cadco_import_meta_columns(): array
{
    return [
        'UPC#'                           => 'upc',
        'Wattage'                        => 'wattage',
        'Amps'                           => 'amps',
        'Package Height'                 => 'package_height',
        'Package Width'                  => 'package_width',
        'Package Length'                 => 'package_length',
        'Package Weight'                 => 'package_weight',
        'Affected by Prop 65 Yes or No'  => 'prop65_affected',
        'Prop 65 Warning'                => 'prop65_warning',
        'Footnote'                       => 'footnote',
        'Description Disclaimer'         => 'disclaimer',
        'Warranty Information'           => 'warranty_info',
        'Warranty URL'                   => 'warranty_url',
        'Second Category'                => 'second_category',
        'Website URL'                    => 'legacy_url',
        'Images URL'                     => 'image_url',
        'Video URL'                      => 'video_url',
        'Spec Sheet URL'                 => 'spec_sheet_url',
        'Diagram URL'                    => 'diagram_url',
        'Manual URL'                     => 'manual_url',
        'Cubic Feet'                     => 'cubic_feet',
        'Approvals'                      => 'approvals',
        'Notes'                          => 'notes',
        'Parent Product'                 => 'parent_model',
    ];
}

/**
 * Columns read straight into native WooCommerce fields.
 */
function cadco_import_native_columns(): array
{
    return [
        'Model #'                                 => 'sku',
        'Product Name'                            => 'title',
        'Primary Description'                     => 'excerpt',
        'Supplier Specifications - Bullet Points' => 'content_primary',
        'Secondary Description (Optional)'        => 'content_secondary',
        'Height'                                  => 'height',
        'Width'                                   => 'width',
        'Depth'                                   => 'length',
        'Weight'                                  => 'weight',
        'Brand Name'                              => 'brand',
        'Type'                                    => 'category',
        'Specialties'                             => 'tags',
    ];
}

/**
 * Columns deliberately not imported.
 *
 * The eight unit-of-measure columns hold only 'Inches' or 'Pounds' across all
 * 238 rows — they are labels, not data, and WooCommerce's global unit setting
 * already covers the native dimension fields. 'Primary Category' holds two
 * values across four sheets and contradicts the sheet it appears on. 'Other'
 * is empty on every row.
 */
function cadco_import_ignored_columns(): array
{
    return [
        'Height Unit of Measure',
        'Width Unit of Measure',
        'Depth Unit of Measure',
        'Weight Unit of Measure',
        'Package Height Unit Of Measure',
        'Package Width Unit Of Measure',
        'Package Length Unit Of Measure',
        'Package Weight Unit Of Measure',
        'Primary Category',
        'Other',
    ];
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test -- --filter FieldMapTest`
Expected: PASS — `OK (7 tests, ...)`

- [ ] **Step 5: Commit**

```bash
git add inc/import/field-map.php tests/unit/FieldMapTest.php
git commit -m "feat(import): add the spreadsheet field map"
```

---

## Task 3: Test fixtures and the Reader

The Reader turns an XLSX file into rows keyed by header name. It is the only unit that touches PhpSpreadsheet. Fixtures are built programmatically rather than committed as binaries so the test data is readable in a diff.

**Files:**
- Create: `tests/fixtures/FixtureBuilder.php`
- Create: `inc/import/class-cadco-import-reader.php`
- Test: `tests/unit/ReaderTest.php`

**Interfaces:**
- Consumes: `cadco_import_sheets()` from Task 2.
- Produces:
  - `CADCO_Import_Reader::read(string $path): array` returning
    `['rows' => array, 'errors' => array]`.
  - Each row is `['__sheet' => string, '__row' => int, '<Header>' => string, ...]`. Every value is a trimmed string; empty cells are `''`, never `null`.
  - Each error is `['tier' => 'A', 'sheet' => string, 'row' => int|null, 'column' => string, 'message' => string]`.
  - `CADCO\Tests\Fixtures\FixtureBuilder::write(string $path, array $sheets): void` where `$sheets` is `['SHEET NAME' => [['Header' => 'value', ...], ...]]`.

- [ ] **Step 1: Create the fixture builder**

Create `tests/fixtures/FixtureBuilder.php`:

```php
<?php

declare(strict_types=1);

namespace CADCO\Tests\Fixtures;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Builds small XLSX files for tests.
 *
 * Fixtures are generated rather than committed as binaries so that the test
 * data is visible in a diff and can be varied per test.
 */
final class FixtureBuilder
{
    /**
     * @param array<string, list<array<string, string>>> $sheets
     *        Sheet name => list of rows. Each row's keys are header names.
     *        A sheet's header order is taken from its first row, so different
     *        sheets can deliberately disagree on column order.
     */
    public static function write(string $path, array $sheets): void
    {
        $book = new Spreadsheet();
        $book->removeSheetByIndex(0);

        foreach ($sheets as $name => $rows) {
            $sheet   = $book->createSheet();
            $sheet->setTitle($name);
            $headers = $rows === [] ? [] : array_keys($rows[0]);

            foreach ($headers as $i => $header) {
                $sheet->setCellValue([$i + 1, 1], $header);
            }

            foreach ($rows as $r => $row) {
                foreach ($headers as $i => $header) {
                    $sheet->setCellValue([$i + 1, $r + 2], $row[$header] ?? '');
                }
            }
        }

        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();
    }

    /**
     * A minimal valid row. Override any field by passing it in $overrides.
     */
    public static function row(array $overrides = []): array
    {
        return $overrides + [
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
        ];
    }
}
```

- [ ] **Step 2: Register the fixtures namespace**

Edit `composer.json`, replacing the `autoload-dev` block:

```json
    "autoload-dev": {
        "psr-4": {
            "CADCO\\Tests\\": "tests/",
            "CADCO\\Tests\\Fixtures\\": "tests/fixtures/"
        }
    },
```

Run: `composer dump-autoload`

- [ ] **Step 3: Write the failing test**

Create `tests/unit/ReaderTest.php`:

```php
<?php

declare(strict_types=1);

namespace CADCO\Tests\Unit;

use CADCO\Tests\Fixtures\FixtureBuilder;
use PHPUnit\Framework\TestCase;

final class ReaderTest extends TestCase
{
    private string $path = '';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../inc/import/field-map.php';
        require_once __DIR__ . '/../../inc/import/class-cadco-import-reader.php';
    }

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/cadco-reader-' . uniqid() . '.xlsx';
    }

    protected function tearDown(): void
    {
        if ($this->path !== '' && file_exists($this->path)) {
            unlink($this->path);
        }
    }

    public function test_reads_rows_keyed_by_header_name(): void
    {
        FixtureBuilder::write($this->path, [
            'CONVECTION OVENS' => [FixtureBuilder::row()],
        ]);

        $result = \CADCO_Import_Reader::read($this->path);

        self::assertSame([], $result['errors']);
        self::assertCount(1, $result['rows']);
        self::assertSame('BLC-113', $result['rows'][0]['Model #']);
        self::assertSame('CONVECTION OVENS', $result['rows'][0]['__sheet']);
        self::assertSame(2, $result['rows'][0]['__row']);
    }

    public function test_column_order_may_differ_between_sheets(): void
    {
        // CONVECTION OVENS carries a leading Notes column; FAST COOKING OVENS
        // does not and carries a trailing Other column instead. This is the
        // real shape of the workbook, and it is why columns are read by name.
        FixtureBuilder::write($this->path, [
            'CONVECTION OVENS'   => [['Notes' => 'internal'] + FixtureBuilder::row()],
            'FAST COOKING OVENS' => [FixtureBuilder::row([
                'Model #' => 'VK-SK',
                'UPC#'    => '654796-54400-4',
            ]) + ['Other' => '']],
        ]);

        $result = \CADCO_Import_Reader::read($this->path);

        self::assertSame([], $result['errors']);
        self::assertCount(2, $result['rows']);

        $bySku = array_column($result['rows'], null, 'Model #');
        self::assertSame('internal', $bySku['BLC-113']['Notes']);
        self::assertSame('', $bySku['VK-SK']['Notes'] ?? '');
    }

    public function test_sheets_starting_with_underscore_are_skipped(): void
    {
        FixtureBuilder::write($this->path, [
            'CONVECTION OVENS' => [FixtureBuilder::row()],
            '_CORRECTIONS'     => [['Sheet' => 'CONVECTION OVENS', 'Why' => 'test']],
        ]);

        $result = \CADCO_Import_Reader::read($this->path);

        self::assertSame([], $result['errors']);
        self::assertCount(1, $result['rows']);
    }

    public function test_an_unrecognised_sheet_is_a_tier_a_error(): void
    {
        FixtureBuilder::write($this->path, [
            'CONVECTION OVENS' => [FixtureBuilder::row()],
            'LIST PRICING'     => [['Model #' => 'BLC-113', 'Price' => '100']],
        ]);

        $result = \CADCO_Import_Reader::read($this->path);

        self::assertCount(1, $result['errors']);
        self::assertSame('A', $result['errors'][0]['tier']);
        self::assertStringContainsString('LIST PRICING', $result['errors'][0]['message']);
    }

    public function test_a_missing_required_header_is_a_tier_a_error(): void
    {
        $row = FixtureBuilder::row();
        unset($row['UPC#']);

        FixtureBuilder::write($this->path, ['CONVECTION OVENS' => [$row]]);

        $result = \CADCO_Import_Reader::read($this->path);

        self::assertNotSame([], $result['errors']);
        self::assertStringContainsString('UPC#', $result['errors'][0]['message']);
    }

    public function test_blank_rows_are_skipped_and_values_are_trimmed(): void
    {
        FixtureBuilder::write($this->path, [
            'CONVECTION OVENS' => [
                FixtureBuilder::row(['Lead Time' => '  1-3 business days  ']),
                array_map(static fn () => '', FixtureBuilder::row()),
            ],
        ]);

        $result = \CADCO_Import_Reader::read($this->path);

        self::assertCount(1, $result['rows']);
        self::assertSame('1-3 business days', $result['rows'][0]['Lead Time']);
    }

    public function test_a_missing_file_is_reported_not_fatal(): void
    {
        $result = \CADCO_Import_Reader::read('/no/such/file.xlsx');

        self::assertSame([], $result['rows']);
        self::assertNotSame([], $result['errors']);
    }
}
```

- [ ] **Step 4: Run the test to verify it fails**

Run: `composer test -- --filter ReaderTest`
Expected: FAIL — `class-cadco-import-reader.php` does not exist.

- [ ] **Step 5: Create `inc/import/class-cadco-import-reader.php`**

```php
<?php

/**
 * Turns the product workbook into plain rows.
 *
 * The only unit that knows PhpSpreadsheet exists. Everything downstream works
 * on arrays, so the parsing library can be swapped without touching any
 * business logic.
 *
 * Columns are located by their header text rather than by position. That is
 * not a stylistic preference: two sheets in the same workbook disagree about
 * column order (CONVECTION OVENS carries a leading 'Notes' column that
 * COUNTERTOP EQUIPMENT does not), and the previous revision of the workbook
 * had different sheets and a column that no longer exists.
 */

declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\IOFactory;

final class CADCO_Import_Reader
{
    /**
     * @return array{rows: list<array<string, mixed>>, errors: list<array<string, mixed>>}
     */
    public static function read(string $path): array
    {
        $rows   = [];
        $errors = [];

        if (!is_readable($path)) {
            return [
                'rows'   => [],
                'errors' => [self::error('', null, '', sprintf('The file could not be read: %s', $path))],
            ];
        }

        try {
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $book = $reader->load($path);
        } catch (\Throwable $e) {
            return [
                'rows'   => [],
                'errors' => [self::error('', null, '', 'The file could not be opened as a spreadsheet: ' . $e->getMessage())],
            ];
        }

        $known = array_map('strtoupper', cadco_import_sheets());
        $seen  = [];

        foreach ($book->getWorksheetIterator() as $sheet) {
            $title = trim($sheet->getTitle());

            // Sheets beginning with '_' are the workbook's own working notes.
            if (str_starts_with($title, '_')) {
                continue;
            }

            $canonical = array_search(strtoupper($title), $known, true);

            if ($canonical === false) {
                $errors[] = self::error(
                    $title,
                    null,
                    '',
                    sprintf('Unrecognised sheet "%s". Rename it to one of: %s, or prefix it with "_" to have it ignored.', $title, implode(', ', cadco_import_sheets()))
                );
                continue;
            }

            $name         = cadco_import_sheets()[$canonical];
            $seen[$name]  = true;
            $headers      = self::headers($sheet);
            $missing      = array_diff(cadco_import_required_fields(), $headers);

            if ($missing !== []) {
                $errors[] = self::error(
                    $name,
                    1,
                    '',
                    sprintf('Sheet "%s" is missing required column(s): %s', $name, implode(', ', $missing))
                );
                continue;
            }

            foreach (self::body($sheet, $headers, $name) as $row) {
                $rows[] = $row;
            }
        }

        $book->disconnectWorksheets();

        foreach (cadco_import_sheets() as $expected) {
            if (!isset($seen[$expected])) {
                $errors[] = self::error('', null, '', sprintf('Expected sheet "%s" is not present in the workbook.', $expected));
            }
        }

        return ['rows' => $rows, 'errors' => $errors];
    }

    /**
     * Header text from row 1, indexed by column number.
     *
     * @return array<int, string>
     */
    private static function headers(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet): array
    {
        $headers = [];
        $last    = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

        for ($c = 1; $c <= $last; $c++) {
            $value = trim((string) $sheet->getCell([$c, 1])->getValue());

            if ($value !== '') {
                $headers[$c] = $value;
            }
        }

        return $headers;
    }

    /**
     * @param array<int, string> $headers
     * @return list<array<string, mixed>>
     */
    private static function body(
        \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
        array $headers,
        string $name
    ): array {
        $rows = [];
        $last = $sheet->getHighestDataRow();

        for ($r = 2; $r <= $last; $r++) {
            $row   = [];
            $empty = true;

            foreach ($headers as $c => $header) {
                $value = $sheet->getCell([$c, $r])->getValue();
                $value = $value === null ? '' : trim((string) $value);

                if ($value !== '') {
                    $empty = false;
                }

                $row[$header] = $value;
            }

            // A wholly empty row is spreadsheet padding, not a product.
            if ($empty) {
                continue;
            }

            $row['__sheet'] = $name;
            $row['__row']   = $r;
            $rows[]         = $row;
        }

        return $rows;
    }

    private static function error(string $sheet, ?int $row, string $column, string $message): array
    {
        return [
            'tier'    => 'A',
            'sheet'   => $sheet,
            'row'     => $row,
            'column'  => $column,
            'message' => $message,
        ];
    }
}
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `composer test -- --filter ReaderTest`
Expected: PASS — 7 tests.

- [ ] **Step 7: Verify against the real workbook**

Create a throwaway script `/tmp/read-real.php`:

```php
<?php
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../inc/import/field-map.php';
require __DIR__ . '/../inc/import/class-cadco-import-reader.php';

$result = CADCO_Import_Reader::read($argv[1]);
printf("rows: %d, errors: %d\n", count($result['rows']), count($result['errors']));
foreach ($result['errors'] as $e) {
    echo '  - ' . $e['message'] . "\n";
}
```

Run from the theme directory:

```bash
php /tmp/read-real.php "/Users/gustavogomez/Documents/Projects/CADCO/Products Excel Spreadsheet latest/Product Index Spreadsheet 2026_Website_CORRECTED.xlsx"
```

Expected: `rows: 236, errors: 0`

Then run it against the uncorrected source workbook. Expected: `rows: 238, errors: 0` — the source has no structural faults; its problems are all values, which the Validator catches, not the Reader.

- [ ] **Step 8: Commit**

```bash
git add inc/import/class-cadco-import-reader.php tests/ composer.json composer.lock
git commit -m "feat(import): read the workbook by header name"
```

---

## Task 4: The Normaliser

Cleans values and records every change it makes, so the report can tell CADCO exactly what was silently different. Pure; no WordPress.

**Files:**
- Create: `inc/import/class-cadco-import-normaliser.php`
- Test: `tests/unit/NormaliserTest.php`

**Interfaces:**
- Consumes: rows from `CADCO_Import_Reader::read()`.
- Produces:
  - `CADCO_Import_Normaliser::normalise(array $rows): array` returning `['rows' => array, 'changes' => list<array>]`.
  - Each change is `['sheet' => string, 'row' => int, 'model' => string, 'column' => string, 'before' => string, 'after' => string]`.
  - `CADCO_Import_Normaliser::title_case(string $value): string` — preserves mixed-case words.
  - `CADCO_Import_Normaliser::bullets(string $value): list<string>` — splits a bullet block into lines.
  - `CADCO_Import_Normaliser::canonical_key(string $value): string` — the case- and punctuation-insensitive key used to group variant spellings.

- [ ] **Step 1: Write the failing test**

Create `tests/unit/NormaliserTest.php`:

```php
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

    public function test_attribute_variants_collapse_and_are_recorded(): void
    {
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
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test -- --filter NormaliserTest`
Expected: FAIL — `class-cadco-import-normaliser.php` does not exist.

- [ ] **Step 3: Create `inc/import/class-cadco-import-normaliser.php`**

```php
<?php

/**
 * Cleans values, and records everything it changed.
 *
 * The recording matters as much as the cleaning. Under the all-or-nothing
 * validation policy these variants also block the import, so the normaliser's
 * real job is to name each near-miss precisely — 'Steam Table/ Chafer Supplies'
 * differs from 'Steam Table / Chafer Supplies' by one space, which nobody spots
 * by eye in a 238-row sheet.
 *
 * Canonical spelling is chosen by frequency: whichever variant appears most
 * often in the workbook wins. Ties go to the first seen, which is stable
 * because rows arrive in sheet order.
 */

declare(strict_types=1);

final class CADCO_Import_Normaliser
{
    /**
     * Words kept lowercase inside a title, unless they lead it.
     */
    private const SMALL_WORDS = [
        'a', 'an', 'and', 'as', 'at', 'but', 'by', 'for', 'if', 'in',
        'of', 'on', 'or', 'the', 'to', 'v', 'via', 'vs', 'with',
    ];

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{rows: list<array<string, mixed>>, changes: list<array<string, mixed>>}
     */
    public static function normalise(array $rows): array
    {
        $changes = [];

        // Pass 1 — whitespace hygiene on every column.
        foreach ($rows as $i => $row) {
            foreach ($row as $column => $value) {
                if (str_starts_with($column, '__') || !is_string($value)) {
                    continue;
                }

                $clean = self::whitespace($value, $column);

                if ($clean !== $value) {
                    $changes[]              = self::change($row, $column, $value, $clean);
                    $rows[$i][$column]      = $clean;
                }
            }
        }

        // Pass 2 — collapse variant spellings onto the most frequent form.
        foreach (cadco_import_consistency_columns() as $column) {
            $canonical = $column === 'Specialties'
                ? self::canonical_map_multi($rows, $column)
                : self::canonical_map_single($rows, $column);

            foreach ($rows as $i => $row) {
                $value = (string) ($row[$column] ?? '');

                if ($value === '') {
                    continue;
                }

                $clean = $column === 'Specialties'
                    ? self::apply_multi($value, $canonical)
                    : ($canonical[self::canonical_key($value)] ?? $value);

                if ($clean !== $value) {
                    $changes[]         = self::change($row, $column, $value, $clean);
                    $rows[$i][$column] = $clean;
                }
            }
        }

        return ['rows' => array_values($rows), 'changes' => $changes];
    }

    /**
     * Trim, collapse runs of spaces, and tidy comma spacing in Type.
     *
     * Line breaks are preserved: the bullet columns depend on them.
     */
    private static function whitespace(string $value, string $column): string
    {
        $value = preg_replace('/[ \t]+/', ' ', $value) ?? $value;
        $value = preg_replace('/[ \t]*\n[ \t]*/', "\n", $value) ?? $value;
        $value = trim($value);

        if ($column === 'Type') {
            $value = preg_replace('/\s*,\s*/', ', ', $value) ?? $value;
        }

        return $value;
    }

    /**
     * Title-case that leaves deliberate capitalisation alone.
     *
     * A word is recapitalised only when it is entirely upper or entirely lower
     * case. 'MobileServ®' and 'VariKwik' carry internal capitals on purpose, so
     * they pass through untouched — ucwords() would flatten them.
     */
    public static function title_case(string $value): string
    {
        $words = preg_split('/(\s+)/u', $value, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $out   = '';
        $index = 0;

        foreach ($words as $word) {
            if (trim($word) === '') {
                $out .= $word;
                continue;
            }

            $bare = preg_replace('/[^\p{L}]/u', '', $word) ?? $word;
            $mixed = $bare !== ''
                && mb_strtoupper($bare) !== $bare
                && mb_strtolower($bare) !== $bare;

            if (!$mixed) {
                $lower = mb_strtolower($word);
                $word  = ($index > 0 && in_array(rtrim($lower, ".,;:"), self::SMALL_WORDS, true))
                    ? $lower
                    : mb_strtoupper(mb_substr($lower, 0, 1)) . mb_substr($lower, 1);
            }

            $out .= $word;
            $index++;
        }

        return $out;
    }

    /**
     * Split a bullet block into its individual values.
     *
     * A bullet appearing mid-line is a lost line break, not a separator between
     * two values — 'Countertop & Tabletop •Cooking/Warming' is one tag whose
     * canonical spelling has no inner bullet. It is removed rather than split
     * on, because splitting would invent a tag that does not exist elsewhere.
     *
     * @return list<string>
     */
    public static function bullets(string $value): array
    {
        $out = [];

        foreach (explode("\n", $value) as $line) {
            $line = ltrim(trim($line), "•*- \t");
            $line = preg_replace('/\s*•\s*/u', ' ', $line) ?? $line;
            $line = trim(preg_replace('/\s+/u', ' ', $line) ?? $line, " \t\"'");

            if ($line !== '' && !in_array($line, $out, true)) {
                $out[] = $line;
            }
        }

        return $out;
    }

    /**
     * The key two spellings share when they mean the same thing.
     */
    public static function canonical_key(string $value): string
    {
        $key = mb_strtolower(trim($value));
        $key = str_replace(['"', "'", '•'], '', $key);
        $key = preg_replace('/\s*\/\s*/u', '/', $key) ?? $key;
        $key = preg_replace('/\s+/u', ' ', $key) ?? $key;

        return trim($key);
    }

    /**
     * Winning spelling per canonical key, for a single-value column.
     *
     * @return array<string, string>
     */
    private static function canonical_map_single(array $rows, string $column): array
    {
        $counts = [];

        foreach ($rows as $row) {
            $value = trim((string) ($row[$column] ?? ''));

            if ($value !== '') {
                $counts[self::canonical_key($value)][$value] ??= 0;
                $counts[self::canonical_key($value)][$value]++;
            }
        }

        return self::winners($counts);
    }

    /**
     * @return array<string, string>
     */
    private static function canonical_map_multi(array $rows, string $column): array
    {
        $counts = [];

        foreach ($rows as $row) {
            foreach (self::bullets((string) ($row[$column] ?? '')) as $value) {
                $counts[self::canonical_key($value)][$value] ??= 0;
                $counts[self::canonical_key($value)][$value]++;
            }
        }

        return self::winners($counts);
    }

    /**
     * @param array<string, array<string, int>> $counts
     * @return array<string, string>
     */
    private static function winners(array $counts): array
    {
        $winners = [];

        foreach ($counts as $key => $spellings) {
            arsort($spellings);
            $winners[$key] = (string) array_key_first($spellings);
        }

        return $winners;
    }

    /**
     * @param array<string, string> $canonical
     */
    private static function apply_multi(string $value, array $canonical): string
    {
        $out = [];

        foreach (self::bullets($value) as $item) {
            $clean = $canonical[self::canonical_key($item)] ?? $item;

            if (!in_array($clean, $out, true)) {
                $out[] = $clean;
            }
        }

        return implode("\n", array_map(static fn ($v) => '•' . $v, $out));
    }

    private static function change(array $row, string $column, string $before, string $after): array
    {
        return [
            'sheet'  => (string) ($row['__sheet'] ?? ''),
            'row'    => (int) ($row['__row'] ?? 0),
            'model'  => (string) ($row['Model #'] ?? ''),
            'column' => $column,
            'before' => $before,
            'after'  => $after,
        ];
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test -- --filter NormaliserTest`
Expected: PASS — 11 tests.

- [ ] **Step 5: Commit**

```bash
git add inc/import/class-cadco-import-normaliser.php tests/unit/NormaliserTest.php
git commit -m "feat(import): normalise values and record every change"
```

---

## Task 5: Issue and Report value objects

The Validator's output type. Small, but it needs to exist before the Validator can be tested, and the admin screen renders straight from it.

**Files:**
- Create: `inc/import/class-cadco-import-issue.php`
- Create: `inc/import/class-cadco-import-report.php`
- Test: `tests/unit/ReportTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `new CADCO_Import_Issue(string $tier, string $sheet, ?int $row, string $column, string $found, string $message, string $fix = '')`
  - Public readonly properties with those names; `->to_array(): array`.
  - `new CADCO_Import_Report()`; `->add(CADCO_Import_Issue $issue): void`; `->add_many(array $issues): void`; `->passed(): bool`; `->count(): int`; `->all(): list<CADCO_Import_Issue>`; `->by_tier(): array<string, list<CADCO_Import_Issue>>`; `->tier_counts(): array<string, int>`; `->to_csv(): string`.

- [ ] **Step 1: Write the failing test**

Create `tests/unit/ReportTest.php`:

```php
<?php

declare(strict_types=1);

namespace CADCO\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ReportTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../inc/import/class-cadco-import-issue.php';
        require_once __DIR__ . '/../../inc/import/class-cadco-import-report.php';
    }

    public function test_an_empty_report_passes(): void
    {
        $report = new \CADCO_Import_Report();

        self::assertTrue($report->passed());
        self::assertSame(0, $report->count());
    }

    public function test_any_issue_in_any_tier_fails_the_report(): void
    {
        // The policy is all-or-nothing: a Tier C completeness gap blocks the
        // import exactly as hard as a Tier A duplicate identifier.
        $report = new \CADCO_Import_Report();
        $report->add(new \CADCO_Import_Issue('C', 'CONVECTION OVENS', 49, 'Wattage', '', 'Blank.', 'Write n/a'));

        self::assertFalse($report->passed());
        self::assertSame(1, $report->count());
    }

    public function test_issues_group_by_tier_in_order(): void
    {
        $report = new \CADCO_Import_Report();
        $report->add(new \CADCO_Import_Issue('C', 'S', 3, 'Wattage', '', 'c', ''));
        $report->add(new \CADCO_Import_Issue('A', 'S', 1, 'UPC#', 'x', 'a', ''));
        $report->add(new \CADCO_Import_Issue('B', 'S', 2, 'Color', 'Grey', 'b', ''));

        self::assertSame(['A', 'B', 'C'], array_keys($report->by_tier()));
        self::assertSame(['A' => 1, 'B' => 1, 'C' => 1], $report->tier_counts());
    }

    public function test_csv_has_a_header_and_one_line_per_issue(): void
    {
        $report = new \CADCO_Import_Report();
        $report->add(new \CADCO_Import_Issue('A', 'CONVECTION OVENS', 82, 'UPC#', '654796-55145-3', 'Duplicate UPC.', 'Assign a unique UPC'));

        $lines = array_values(array_filter(explode("\n", trim($report->to_csv()))));

        self::assertCount(2, $lines);
        self::assertStringContainsString('Tier', $lines[0]);
        self::assertStringContainsString('654796-55145-3', $lines[1]);
    }

    public function test_csv_escapes_embedded_commas_and_quotes(): void
    {
        $report = new \CADCO_Import_Report();
        $report->add(new \CADCO_Import_Issue('B', 'S', 1, 'Specialties', 'Kitchen Equipment"', 'Variant, spelled two ways.', ''));

        $csv = $report->to_csv();

        self::assertStringContainsString('"Kitchen Equipment"""', $csv);
        self::assertStringContainsString('"Variant, spelled two ways."', $csv);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test -- --filter ReportTest`
Expected: FAIL — the class files do not exist.

- [ ] **Step 3: Create `inc/import/class-cadco-import-issue.php`**

```php
<?php

/**
 * One thing wrong with the workbook.
 *
 * Every issue names the sheet, row and column it came from, what was found,
 * and what to change it to. That is deliberate: under the all-or-nothing
 * policy the report is a worklist CADCO has to act on, so an issue that does
 * not say where it is or what to do about it is useless.
 */

declare(strict_types=1);

final class CADCO_Import_Issue
{
    public function __construct(
        public readonly string $tier,
        public readonly string $sheet,
        public readonly ?int $row,
        public readonly string $column,
        public readonly string $found,
        public readonly string $message,
        public readonly string $fix = ''
    ) {
    }

    public function to_array(): array
    {
        return [
            'tier'    => $this->tier,
            'sheet'   => $this->sheet,
            'row'     => $this->row,
            'column'  => $this->column,
            'found'   => $this->found,
            'message' => $this->message,
            'fix'     => $this->fix,
        ];
    }
}
```

- [ ] **Step 4: Create `inc/import/class-cadco-import-report.php`**

```php
<?php

/**
 * The result of validating a workbook.
 *
 * The report is the primary deliverable of this system, not a side effect.
 * Because validation is all-or-nothing, a failing report is the entire output
 * of a run — so it has to be legible enough to hand to CADCO as-is.
 */

declare(strict_types=1);

final class CADCO_Import_Report
{
    private const TIERS = ['A', 'B', 'C'];

    /** @var list<CADCO_Import_Issue> */
    private array $issues = [];

    public function add(CADCO_Import_Issue $issue): void
    {
        $this->issues[] = $issue;
    }

    /**
     * @param list<CADCO_Import_Issue> $issues
     */
    public function add_many(array $issues): void
    {
        foreach ($issues as $issue) {
            $this->add($issue);
        }
    }

    /**
     * Any issue at all blocks the import. There is no severity threshold.
     */
    public function passed(): bool
    {
        return $this->issues === [];
    }

    public function count(): int
    {
        return count($this->issues);
    }

    /**
     * @return list<CADCO_Import_Issue>
     */
    public function all(): array
    {
        return $this->issues;
    }

    /**
     * @return array<string, list<CADCO_Import_Issue>>
     */
    public function by_tier(): array
    {
        $grouped = [];

        foreach (self::TIERS as $tier) {
            $matching = array_values(array_filter(
                $this->issues,
                static fn (CADCO_Import_Issue $i): bool => $i->tier === $tier
            ));

            if ($matching !== []) {
                $grouped[$tier] = $matching;
            }
        }

        return $grouped;
    }

    /**
     * @return array<string, int>
     */
    public function tier_counts(): array
    {
        return array_map('count', $this->by_tier());
    }

    public function to_csv(): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return '';
        }

        fputcsv($handle, ['Tier', 'Sheet', 'Row', 'Column', 'Found', 'Problem', 'How to fix'], ',', '"', '');

        foreach ($this->issues as $issue) {
            fputcsv($handle, [
                $issue->tier,
                $issue->sheet,
                $issue->row === null ? '' : (string) $issue->row,
                $issue->column,
                $issue->found,
                $issue->message,
                $issue->fix,
            ], ',', '"', '');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `composer test -- --filter ReportTest`
Expected: PASS — 5 tests.

- [ ] **Step 6: Commit**

```bash
git add inc/import/class-cadco-import-issue.php inc/import/class-cadco-import-report.php tests/unit/ReportTest.php
git commit -m "feat(import): add the issue and report value objects"
```

---

## Task 6: The Validator

The three tiers. This is the unit that decides whether anything gets written, so its tests are the most important in the suite.

**Files:**
- Create: `inc/import/class-cadco-import-validator.php`
- Test: `tests/unit/ValidatorTest.php`

**Interfaces:**
- Consumes: normalised rows (Task 4), structural errors (Task 3), `field-map.php` (Task 2), `CADCO_Import_Report` (Task 5).
- Produces: `CADCO_Import_Validator::validate(array $rows, array $structural_errors = []): CADCO_Import_Report`.

- [ ] **Step 1: Write the failing test**

Create `tests/unit/ValidatorTest.php`:

```php
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
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test -- --filter ValidatorTest`
Expected: FAIL — `class-cadco-import-validator.php` does not exist.

- [ ] **Step 3: Create `inc/import/class-cadco-import-validator.php`**

```php
<?php

/**
 * Decides whether the workbook may be imported at all.
 *
 * All three tiers block. That is a deliberate choice by the site owner: the
 * catalogue is only as trustworthy as the sheet behind it, so the sheet is
 * cleaned at source rather than patched on the way in.
 *
 * The consequence is that this class produces the run's entire output most of
 * the time. Every issue therefore carries a location and a concrete fix — a
 * report saying "12 problems" without saying where would be worthless.
 */

declare(strict_types=1);

final class CADCO_Import_Validator
{
    /**
     * @param list<array<string, mixed>> $rows normalised rows
     * @param list<array<string, mixed>> $structural_errors from the Reader
     */
    public static function validate(array $rows, array $structural_errors = []): CADCO_Import_Report
    {
        $report = new CADCO_Import_Report();

        foreach ($structural_errors as $error) {
            $report->add(new CADCO_Import_Issue(
                (string) ($error['tier'] ?? 'A'),
                (string) ($error['sheet'] ?? ''),
                isset($error['row']) ? (int) $error['row'] : null,
                (string) ($error['column'] ?? ''),
                '',
                (string) ($error['message'] ?? ''),
                'Correct the workbook structure and re-upload.'
            ));
        }

        self::tier_a($rows, $report);
        self::tier_b($rows, $report);
        self::tier_c($rows, $report);

        return $report;
    }

    /**
     * Identity. Without a unique, well-formed key per product the planner
     * cannot tell an update from a new product.
     */
    private static function tier_a(array $rows, CADCO_Import_Report $report): void
    {
        $models = [];
        $upcs   = [];

        foreach ($rows as $row) {
            $model = self::get($row, 'Model #');
            $upc   = self::get($row, 'UPC#');

            if ($model !== '') {
                $models[$model][] = $row;
            }

            if ($upc !== '') {
                $upcs[$upc][] = $row;
            }

            if ($upc !== '' && preg_match(cadco_import_upc_pattern(), $upc) !== 1) {
                $report->add(new CADCO_Import_Issue(
                    'A',
                    self::sheet($row),
                    self::row_no($row),
                    'UPC#',
                    $upc,
                    'The UPC is not in the canonical format.',
                    'Rewrite it as NNNNNN-NNNNN-N, for example 654796-52113-5.'
                ));
            }
        }

        foreach ($models as $model => $matching) {
            if (count($matching) > 1) {
                $report->add(new CADCO_Import_Issue(
                    'A',
                    self::sheet($matching[0]),
                    self::row_no($matching[0]),
                    'Model #',
                    $model,
                    sprintf('Model # appears %d times (%s).', count($matching), self::locations($matching)),
                    'List each product once. Use a comma-separated Type for several sub-categories instead of repeating the row.'
                ));
            }
        }

        foreach ($upcs as $upc => $matching) {
            $distinct = array_unique(array_map(
                static fn (array $r): string => self::get($r, 'Model #'),
                $matching
            ));

            if (count($distinct) > 1) {
                $report->add(new CADCO_Import_Issue(
                    'A',
                    self::sheet($matching[0]),
                    self::row_no($matching[0]),
                    'UPC#',
                    (string) $upc,
                    sprintf('UPC is shared by different products: %s.', implode(', ', $distinct)),
                    'Give each product its own UPC. The UPC is what lets a renamed product keep its page.'
                ));
            }
        }

        // Parent Product must name a model that exists in this workbook.
        foreach ($rows as $row) {
            $parent = self::get($row, 'Parent Product');

            if ($parent !== '' && !isset($models[$parent])) {
                $report->add(new CADCO_Import_Issue(
                    'A',
                    self::sheet($row),
                    self::row_no($row),
                    'Parent Product',
                    $parent,
                    'Parent Product names a Model # that is not in the workbook.',
                    'Correct the model number, or clear the cell.'
                ));
            }
        }
    }

    /**
     * Consistency. One real-world value written several ways becomes several
     * entries on the website.
     */
    private static function tier_b(array $rows, CADCO_Import_Report $report): void
    {
        foreach (cadco_import_consistency_columns() as $column) {
            $spellings = [];

            foreach ($rows as $row) {
                $values = $column === 'Specialties'
                    ? CADCO_Import_Normaliser::bullets(self::get($row, $column))
                    : array_filter([self::get($row, $column)], static fn ($v) => $v !== '');

                foreach ($values as $value) {
                    $spellings[$value] ??= $row;
                }
            }

            foreach (self::variant_groups(array_keys($spellings)) as $group) {
                $first = (array) $spellings[$group[0]];

                $report->add(new CADCO_Import_Issue(
                    'B',
                    self::sheet($first),
                    self::row_no($first),
                    $column,
                    implode(' | ', $group),
                    sprintf('The same value appears to be spelled %d different ways.', count($group)),
                    sprintf('Pick one spelling and use it everywhere, e.g. "%s".', $group[0])
                ));
            }
        }
    }

    /**
     * Group spellings that look like the same value written differently.
     *
     * This deliberately uses a looser test than the normaliser's canonical
     * key. The normaliser has already merged everything sharing that key, so
     * grouping by it here would never match anything — the interesting cases
     * are precisely the ones it could not merge safely.
     *
     * Two rules, both needed:
     *
     *   1. Same letters and digits, different punctuation or spacing.
     *      'MET (=UL & CSA)' and 'MET (= UL & CSA)'.
     *
     *   2. Within a ONE-character edit of each other, provided their digits
     *      are identical. That catches 'Healthcare facilties' against
     *      'Healthcare Facilities', the plural in 'Steam Tables/Chafer
     *      Supplies', and 'Grey' against 'Gray'.
     *
     * Both thresholds were tuned against the real workbook and are tighter
     * than they look like they should be. A distance of 2 merges
     * 'MET (=UL & CSA)' with 'ETL (=UL & CSA)' — two different certification
     * bodies — because deleting one letter and inserting another gets from
     * 'metulcsa' to 'etlulcsa'. The digits rule is what stops 'NEMA 5-15P'
     * matching 'NEMA 6-15P'. A report that cries wolf is a report CADCO stops
     * reading, so false positives cost more here than misses.
     *
     * @param list<string> $values distinct spellings
     * @return list<list<string>>
     */
    private static function variant_groups(array $values): array
    {
        $groups = [];
        $taken  = [];

        foreach ($values as $i => $a) {
            if (isset($taken[$a])) {
                continue;
            }

            $group = [$a];

            foreach (array_slice($values, $i + 1) as $b) {
                if (isset($taken[$b]) || !self::looks_like_variant($a, $b)) {
                    continue;
                }

                $group[]    = $b;
                $taken[$b]  = true;
            }

            if (count($group) > 1) {
                $taken[$a] = true;
                $groups[]  = $group;
            }
        }

        return $groups;
    }

    private static function looks_like_variant(string $a, string $b): bool
    {
        $ka = self::fuzzy_key($a);
        $kb = self::fuzzy_key($b);

        if ($ka === '' || $kb === '' || $ka === $kb) {
            return $ka === $kb;
        }

        if (self::digits($a) !== self::digits($b)) {
            return false;
        }

        return min(strlen($ka), strlen($kb)) > 3 && levenshtein($ka, $kb) <= 1;
    }

    /**
     * Letters and digits only, lowercased.
     */
    private static function fuzzy_key(string $value): string
    {
        return strtolower((string) preg_replace('/[^\p{L}\p{N}]/u', '', $value));
    }

    private static function digits(string $value): string
    {
        return (string) preg_replace('/\D/', '', $value);
    }

    /**
     * Completeness. A blank cell cannot be told apart from "not filled in
     * yet", so a field that does not apply must say n/a out loud.
     */
    private static function tier_c(array $rows, CADCO_Import_Report $report): void
    {
        $required = cadco_import_required_fields();
        $na       = cadco_import_na_fields();

        foreach ($rows as $row) {
            foreach ($required as $column) {
                if (self::get($row, $column) === '') {
                    $report->add(new CADCO_Import_Issue(
                        'C',
                        self::sheet($row),
                        self::row_no($row),
                        $column,
                        '',
                        sprintf('%s is required and is blank.', $column),
                        'Supply the real value. This field cannot be n/a.'
                    ));
                }
            }

            foreach ($na as $column) {
                // Only complain about a column the sheet actually carries.
                if (array_key_exists($column, $row) && self::get($row, $column) === '') {
                    $report->add(new CADCO_Import_Issue(
                        'C',
                        self::sheet($row),
                        self::row_no($row),
                        $column,
                        '',
                        sprintf('%s is blank.', $column),
                        'Write the value, or n/a if it genuinely does not apply to this product.'
                    ));
                }
            }
        }
    }

    private static function get(array $row, string $column): string
    {
        return trim((string) ($row[$column] ?? ''));
    }

    private static function sheet(array $row): string
    {
        return (string) ($row['__sheet'] ?? '');
    }

    private static function row_no(array $row): ?int
    {
        return isset($row['__row']) ? (int) $row['__row'] : null;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private static function locations(array $rows): string
    {
        return implode(', ', array_map(
            static fn (array $r): string => sprintf('%s row %d', self::sheet($r), (int) self::row_no($r)),
            $rows
        ));
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test -- --filter ValidatorTest`
Expected: PASS — 16 tests.

- [ ] **Step 5: Verify against both real workbooks**

Create `/tmp/validate-real.php`:

```php
<?php
require __DIR__ . '/../vendor/autoload.php';
foreach (['field-map.php', 'class-cadco-import-reader.php', 'class-cadco-import-normaliser.php',
          'class-cadco-import-issue.php', 'class-cadco-import-report.php',
          'class-cadco-import-validator.php'] as $file) {
    require __DIR__ . '/../inc/import/' . $file;
}

$read       = CADCO_Import_Reader::read($argv[1]);
$normalised = CADCO_Import_Normaliser::normalise($read['rows']);
$report     = CADCO_Import_Validator::validate($normalised['rows'], $read['errors']);

printf("rows: %d  changes: %d  issues: %d  passed: %s\n",
    count($normalised['rows']), count($normalised['changes']),
    $report->count(), $report->passed() ? 'yes' : 'NO');
print_r($report->tier_counts());
```

Run against the **corrected** workbook:

```bash
php /tmp/validate-real.php "/Users/gustavogomez/Documents/Projects/CADCO/Products Excel Spreadsheet latest/Product Index Spreadsheet 2026_Website_CORRECTED.xlsx"
```

Expected: `rows: 236 ... passed: yes` with no tier counts. This is the
canonical passing case — if it fails, the validator is wrong, not the workbook.

Run against the **uncorrected** source workbook. Expected: `passed: NO`, with
Tier A reporting the `OP-4`/`OP-8` duplicates, the four shared UPCs, the blank
UPC on `MTD-1418-2D`, and the malformed UPCs on `BLC-193` and `PS-TBS-HD-SS`.

- [ ] **Step 6: Commit**

```bash
git add inc/import/class-cadco-import-validator.php tests/unit/ValidatorTest.php
git commit -m "feat(import): validate the workbook across three blocking tiers"
```

---

## Task 7: The Plan value object and derived taxonomy

Holds what the import will do. The category-derivation rules live here because the Plan is what names the terms.

**Files:**
- Create: `inc/import/class-cadco-import-plan.php`
- Test: `tests/unit/PlanTest.php`

**Interfaces:**
- Consumes: `CADCO_Import_Normaliser::title_case()` and `::bullets()` (Task 4).
- Produces:
  - `new CADCO_Import_Plan()`; `->add_create(array $row)`, `->add_update(array $row, int $post_id, array $diff)`, `->add_rename(array $row, int $post_id, string $old_sku)`, `->add_trash(int $post_id, string $sku)`, `->add_skip(string $sku)`.
  - `->creates()`, `->updates()`, `->renames()`, `->trashes()`, `->skips()`, `->counts(): array{create:int,update:int,rename:int,trash:int,skip:int}`, `->is_empty(): bool`, `->total_writes(): int`.
  - `CADCO_Import_Plan::categories_for(array $row): list<array{parent:string,child:string}>` — derived category pairs.
  - `CADCO_Import_Plan::tags_for(array $row): list<string>`
  - `CADCO_Import_Plan::all_terms(array $rows): array{categories: list<array>, tags: list<string>, brands: list<string>}`

- [ ] **Step 1: Write the failing test**

Create `tests/unit/PlanTest.php`:

```php
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
            ['create' => 1, 'update' => 1, 'rename' => 1, 'trash' => 1, 'skip' => 1],
            $plan->counts()
        );
        // Skips are not writes — an unchanged row costs nothing.
        self::assertSame(4, $plan->total_writes());
        self::assertFalse($plan->is_empty());
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
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test -- --filter PlanTest`
Expected: FAIL — `class-cadco-import-plan.php` does not exist.

- [ ] **Step 3: Create `inc/import/class-cadco-import-plan.php`**

```php
<?php

/**
 * Everything a run will do, decided before anything is written.
 *
 * The plan is what the operator approves. Because the dry run is simply the
 * pipeline stopping here, whatever this object says is exactly what the
 * applier will carry out — the preview cannot drift from the result.
 *
 * The category-derivation rules live here rather than in the applier because
 * this is the object that names the terms, and the admin preview has to show
 * those names before any term exists.
 */

declare(strict_types=1);

final class CADCO_Import_Plan
{
    /** @var list<array<string, mixed>> */
    private array $creates = [];

    /** @var list<array<string, mixed>> */
    private array $updates = [];

    /** @var list<array<string, mixed>> */
    private array $renames = [];

    /** @var list<array<string, mixed>> */
    private array $trashes = [];

    /** @var list<string> */
    private array $skips = [];

    public function add_create(array $row): void
    {
        $this->creates[] = ['row' => $row];
    }

    /**
     * @param array<string, array{0: string, 1: string}> $diff column => [before, after]
     */
    public function add_update(array $row, int $post_id, array $diff): void
    {
        $this->updates[] = ['row' => $row, 'post_id' => $post_id, 'diff' => $diff];
    }

    public function add_rename(array $row, int $post_id, string $old_sku): void
    {
        $this->renames[] = [
            'row'      => $row,
            'post_id'  => $post_id,
            'old_sku'  => $old_sku,
            'new_sku'  => (string) ($row['Model #'] ?? ''),
            'upc'      => (string) ($row['UPC#'] ?? ''),
            'approved' => false,
        ];
    }

    /**
     * Mark a rename as approved by the operator.
     *
     * Renames are opt-in and the planner never sets this. An unapproved
     * rename is simply not queued, so the product is left exactly as it is
     * rather than being renamed on a guess.
     */
    public function approve_rename(int $index): void
    {
        if (isset($this->renames[$index])) {
            $this->renames[$index]['approved'] = true;
        }
    }

    public function add_trash(int $post_id, string $sku): void
    {
        $this->trashes[] = ['post_id' => $post_id, 'sku' => $sku];
    }

    public function add_skip(string $sku): void
    {
        $this->skips[] = $sku;
    }

    public function creates(): array
    {
        return $this->creates;
    }

    public function updates(): array
    {
        return $this->updates;
    }

    public function renames(): array
    {
        return $this->renames;
    }

    public function trashes(): array
    {
        return $this->trashes;
    }

    public function skips(): array
    {
        return $this->skips;
    }

    /**
     * @return array{create:int,update:int,rename:int,trash:int,skip:int}
     */
    public function counts(): array
    {
        return [
            'create' => count($this->creates),
            'update' => count($this->updates),
            'rename' => count($this->renames),
            'trash'  => count($this->trashes),
            'skip'   => count($this->skips),
        ];
    }

    /**
     * Skips are excluded: an unchanged row costs no writes at all.
     */
    public function total_writes(): int
    {
        return count($this->creates) + count($this->updates)
            + count($this->renames) + count($this->trashes);
    }

    public function is_empty(): bool
    {
        return $this->total_writes() === 0;
    }

    /**
     * The parent/child category pairs a row belongs to.
     *
     * Top level is the sheet name, sub-level is Type. A comma-separated Type
     * places the product in several sub-categories, all beneath the sheet it
     * is listed on.
     *
     * @return list<array{parent: string, child: string}>
     */
    public static function categories_for(array $row): array
    {
        $parent = CADCO_Import_Normaliser::title_case(trim((string) ($row['__sheet'] ?? '')));
        $type   = trim((string) ($row['Type'] ?? ''));

        if ($parent === '' || $type === '') {
            return [];
        }

        $pairs = [];

        foreach (explode(',', $type) as $part) {
            $child = CADCO_Import_Normaliser::title_case(trim($part));

            if ($child === '') {
                continue;
            }

            $pair = ['parent' => $parent, 'child' => $child];

            if (!in_array($pair, $pairs, true)) {
                $pairs[] = $pair;
            }
        }

        return $pairs;
    }

    /**
     * @return list<string>
     */
    public static function tags_for(array $row): array
    {
        return CADCO_Import_Normaliser::bullets((string) ($row['Specialties'] ?? ''));
    }

    /**
     * Every term the workbook implies, deduplicated.
     *
     * @param list<array<string, mixed>> $rows
     * @return array{categories: list<array{parent:string,child:string}>, tags: list<string>, brands: list<string>}
     */
    public static function all_terms(array $rows): array
    {
        $categories = [];
        $tags       = [];
        $brands     = [];

        foreach ($rows as $row) {
            foreach (self::categories_for($row) as $pair) {
                if (!in_array($pair, $categories, true)) {
                    $categories[] = $pair;
                }
            }

            foreach (self::tags_for($row) as $tag) {
                if (!in_array($tag, $tags, true)) {
                    $tags[] = $tag;
                }
            }

            $brand = trim((string) ($row['Brand Name'] ?? ''));

            if ($brand !== '' && !in_array($brand, $brands, true)) {
                $brands[] = $brand;
            }
        }

        return ['categories' => $categories, 'tags' => $tags, 'brands' => $brands];
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test -- --filter PlanTest`
Expected: PASS — 8 tests.

- [ ] **Step 5: Commit**

```bash
git add inc/import/class-cadco-import-plan.php tests/unit/PlanTest.php
git commit -m "feat(import): add the plan object and derive the category tree"
```

---

## Task 8: The Planner

Diffs the workbook against the site. Current site state is **injected** rather than queried, which keeps this unit pure and lets the rename and zero-write cases be tested without a database.

**Files:**
- Create: `inc/import/class-cadco-import-planner.php`
- Test: `tests/unit/PlannerTest.php`

**Interfaces:**
- Consumes: normalised rows (Task 4), `CADCO_Import_Plan` (Task 7).
- Produces:
  - `CADCO_Import_Planner::plan(array $rows, array $current): CADCO_Import_Plan`
  - `$current` is a list of `['post_id' => int, 'sku' => string, 'upc' => string, 'hash' => string]`.
  - `CADCO_Import_Planner::hash(array $row): string` — a stable hash of the importable content of a row.

- [ ] **Step 1: Write the failing test**

Create `tests/unit/PlannerTest.php`:

```php
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
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test -- --filter PlannerTest`
Expected: FAIL — `class-cadco-import-planner.php` does not exist.

- [ ] **Step 3: Create `inc/import/class-cadco-import-planner.php`**

```php
<?php

/**
 * Works out what changed between the workbook and the site.
 *
 * Current site state is passed in rather than queried, so this whole unit is
 * pure and the awkward cases — a rename, a re-run that should write nothing —
 * can be tested without a database.
 *
 * Matching order matters. SKU is tried first because it is the primary key;
 * UPC is consulted only when the SKU is unknown, and then solely to recognise
 * a rename. Reversing that order would let a mistyped UPC hijack a product
 * that was matching perfectly well by its model number.
 */

declare(strict_types=1);

final class CADCO_Import_Planner
{
    /**
     * @param list<array<string, mixed>> $rows    normalised, validated rows
     * @param list<array{post_id:int,sku:string,upc:string,hash:string}> $current
     */
    public static function plan(array $rows, array $current): CADCO_Import_Plan
    {
        $plan     = new CADCO_Import_Plan();
        $by_sku   = [];
        $by_upc   = [];
        $unseen   = [];

        foreach ($current as $product) {
            $sku = (string) $product['sku'];
            $upc = (string) $product['upc'];

            if ($sku !== '') {
                $by_sku[$sku] = $product;
            }

            // A UPC shared by several products is ambiguous, so it is not
            // usable for rename detection. Validation blocks that case anyway;
            // this keeps the planner safe if it is ever called directly.
            if ($upc !== '') {
                $by_upc[$upc] = isset($by_upc[$upc]) ? null : $product;
            }

            $unseen[(int) $product['post_id']] = $product;
        }

        foreach ($rows as $row) {
            $sku  = trim((string) ($row['Model #'] ?? ''));
            $upc  = trim((string) ($row['UPC#'] ?? ''));
            $hash = self::hash($row);

            if (isset($by_sku[$sku])) {
                $match = $by_sku[$sku];
                unset($unseen[(int) $match['post_id']]);

                if ((string) $match['hash'] === $hash) {
                    $plan->add_skip($sku);
                    continue;
                }

                $plan->add_update($row, (int) $match['post_id'], ['hash' => [(string) $match['hash'], $hash]]);
                continue;
            }

            if ($upc !== '' && isset($by_upc[$upc]) && $by_upc[$upc] !== null) {
                $match = $by_upc[$upc];
                unset($unseen[(int) $match['post_id']]);

                $plan->add_rename($row, (int) $match['post_id'], (string) $match['sku']);
                continue;
            }

            $plan->add_create($row);
        }

        foreach ($unseen as $product) {
            $plan->add_trash((int) $product['post_id'], (string) $product['sku']);
        }

        return $plan;
    }

    /**
     * A stable fingerprint of everything this row would write.
     *
     * Only importable columns take part, so re-ordering rows in the sheet is
     * not a content change. Keys are sorted so that a column moving left or
     * right does not alter the hash either.
     */
    public static function hash(array $row): string
    {
        $relevant = array_merge(
            array_keys(cadco_import_native_columns()),
            array_keys(cadco_import_attribute_columns()),
            array_keys(cadco_import_meta_columns()),
            ['__sheet']
        );

        $payload = [];

        foreach (array_unique($relevant) as $column) {
            $value = trim((string) ($row[$column] ?? ''));

            if ($value !== '') {
                $payload[$column] = $value;
            }
        }

        ksort($payload);

        // Plain json_encode rather than wp_json_encode: this unit is tested
        // with no WordPress loaded, so it cannot depend on WordPress helpers.
        $json = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash('sha256', $json);
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test -- --filter PlannerTest`
Expected: PASS — 9 tests.

- [ ] **Step 5: Run the whole suite**

Run: `composer test`
Expected: PASS — all suites green. The pure pipeline is now complete.

- [ ] **Step 6: Commit**

```bash
git add inc/import/class-cadco-import-planner.php tests/unit/PlannerTest.php
git commit -m "feat(import): plan creates, updates, renames and trashes"
```

---

## Task 9: The Repository

The only place that reads product state out of WordPress. Keeping it separate is what let Task 8 stay pure.

**Files:**
- Create: `inc/import/class-cadco-import-repository.php`
- Create: `inc/import/bootstrap.php`
- Modify: `functions.php` (add one `require_once` after line 9)
- Test: manual, via WP-CLI `--exec` (no WordPress test suite is installed)

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces:
  - `CADCO_Import_Repository::current_products(): array` — list of `['post_id'=>int,'sku'=>string,'upc'=>string,'hash'=>string]` for every non-trashed product.
  - `CADCO_Import_Repository::find_by_sku(string $sku): ?int`
  - `CADCO_Import_Repository::category_terms(): array` — every `product_cat` term as `['term_id'=>int,'name'=>string,'slug'=>string,'parent'=>int]`.
  - `CADCO_Import_Repository::orphan_terms(string $taxonomy): array` — terms with zero products.

- [ ] **Step 1: Create `inc/import/class-cadco-import-repository.php`**

```php
<?php

/**
 * Reads the current catalogue out of WordPress.
 *
 * Deliberately the only unit that queries the database for product state. The
 * planner takes its "current state" as an argument instead of calling this,
 * which is what allows the rename and zero-write cases to be unit-tested with
 * no WordPress at all.
 */

declare(strict_types=1);

final class CADCO_Import_Repository
{
    /**
     * Every product the importer is allowed to consider, with the three facts
     * the planner needs about each.
     *
     * Trashed products are excluded: a product removed by a previous run must
     * not be resurrected as an "update" by the next one, and a genuinely
     * returning product is correctly treated as a create.
     *
     * @return list<array{post_id:int,sku:string,upc:string,hash:string}>
     */
    public static function current_products(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT p.ID AS post_id,
                    COALESCE(sku.meta_value, '')  AS sku,
                    COALESCE(upc.meta_value, '')  AS upc,
                    COALESCE(hash.meta_value, '') AS hash
               FROM {$wpdb->posts} p
          LEFT JOIN {$wpdb->postmeta} sku  ON sku.post_id  = p.ID AND sku.meta_key  = '_sku'
          LEFT JOIN {$wpdb->postmeta} upc  ON upc.post_id  = p.ID AND upc.meta_key  = '_cadco_upc'
          LEFT JOIN {$wpdb->postmeta} hash ON hash.post_id = p.ID AND hash.meta_key = '_cadco_import_hash'
              WHERE p.post_type = 'product'
                AND p.post_status NOT IN ('trash', 'auto-draft')",
            ARRAY_A
        );

        return array_map(
            static fn (array $row): array => [
                'post_id' => (int) $row['post_id'],
                'sku'     => (string) $row['sku'],
                'upc'     => (string) $row['upc'],
                'hash'    => (string) $row['hash'],
            ],
            $rows ?: []
        );
    }

    public static function find_by_sku(string $sku): ?int
    {
        $id = wc_get_product_id_by_sku($sku);

        return $id > 0 ? $id : null;
    }

    /**
     * @return list<array{term_id:int,name:string,slug:string,parent:int}>
     */
    public static function category_terms(): array
    {
        $terms = get_terms([
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
        ]);

        if (is_wp_error($terms)) {
            return [];
        }

        return array_map(
            static fn (WP_Term $t): array => [
                'term_id' => $t->term_id,
                'name'    => $t->name,
                'slug'    => $t->slug,
                'parent'  => $t->parent,
            ],
            $terms
        );
    }

    /**
     * Terms holding no products, so a run can clean up after itself.
     *
     * 'uncategorized' is excluded: WooCommerce treats it as the default term
     * and recreates it, so deleting it achieves nothing but churn.
     *
     * @return list<array{term_id:int,name:string}>
     */
    public static function orphan_terms(string $taxonomy): array
    {
        $terms = get_terms([
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
        ]);

        if (is_wp_error($terms)) {
            return [];
        }

        $orphans = [];

        foreach ($terms as $term) {
            if ($term->count > 0 || $term->slug === 'uncategorized') {
                continue;
            }

            // A parent whose children still hold products is not an orphan.
            $children = get_term_children($term->term_id, $taxonomy);

            if (!is_wp_error($children) && $children !== []) {
                continue;
            }

            $orphans[] = ['term_id' => $term->term_id, 'name' => $term->name];
        }

        return $orphans;
    }
}
```

- [ ] **Step 2: Create `inc/import/bootstrap.php`**

```php
<?php

/**
 * Loads the import system.
 *
 * The pure units are plain includes with no side effects, so that the test
 * suite can require them individually without WordPress. Only the admin and
 * meta box register hooks, and only inside wp-admin.
 */

declare(strict_types=1);

/**
 * PhpSpreadsheet ships inside the theme — the WP Engine deploy has no build
 * step, so there is nothing to run composer install on the server.
 */
if (is_readable(__DIR__ . '/../../vendor/autoload.php')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
}

/**
 * Files are loaded if present rather than unconditionally, so that this file
 * can be committed before every unit exists and the site never fatals on a
 * half-built branch. By the end of the plan all of them are here.
 */
foreach ([
    'field-map.php',
    'class-cadco-import-reader.php',
    'class-cadco-import-normaliser.php',
    'class-cadco-import-issue.php',
    'class-cadco-import-report.php',
    'class-cadco-import-validator.php',
    'class-cadco-import-plan.php',
    'class-cadco-import-planner.php',
    'class-cadco-import-repository.php',
    'class-cadco-import-applier.php',
] as $cadco_import_file) {
    if (is_readable(__DIR__ . '/' . $cadco_import_file)) {
        require_once __DIR__ . '/' . $cadco_import_file;
    }
}
unset($cadco_import_file);

if (is_admin()) {
    foreach (['class-cadco-import-admin.php', 'class-cadco-product-meta-box.php'] as $cadco_admin_file) {
        if (is_readable(__DIR__ . '/' . $cadco_admin_file)) {
            require_once __DIR__ . '/' . $cadco_admin_file;
        }
    }
    unset($cadco_admin_file);

    if (class_exists('CADCO_Import_Admin')) {
        CADCO_Import_Admin::init();
    }

    if (class_exists('CADCO_Product_Meta_Box')) {
        CADCO_Product_Meta_Box::init();
    }
}
```

- [ ] **Step 3: Wire it into `functions.php`**

Add after the existing `cadco-woocommerce.php` require (currently line 9):

```php
require_once get_stylesheet_directory() . '/inc/import/bootstrap.php';
```

- [ ] **Step 4: Verify the repository reads a real (empty) catalogue**

The site currently has 0 products, so this asserts the shape rather than content.

```bash
cd "/Users/gustavogomez/Local Sites/cadco/app/public"
wp eval 'var_dump(CADCO_Import_Repository::current_products());'
```

Expected: `array(0) {}` and **no fatal error** — which proves bootstrap.php loads and the SQL is valid.

```bash
wp eval 'echo count(CADCO_Import_Repository::category_terms()), " category terms", PHP_EOL;'
```

Expected: `26 category terms` — the pre-existing hand-built tree.

- [ ] **Step 5: Commit**

bootstrap.php loads each unit only if the file is present, so committing it now is safe even though the applier and the admin screen do not exist yet.

```bash
git add inc/import/class-cadco-import-repository.php inc/import/bootstrap.php functions.php
git commit -m "feat(import): read current catalogue state from wordpress"
```

---

## Task 10: The Applier

Performs the writes, in batches. Order matters: all taxonomy work first, then products, then exactly one rewrite flush.

**Files:**
- Create: `inc/import/class-cadco-import-applier.php`
- Test: manual, via WP-CLI against the corrected workbook

**Interfaces:**
- Consumes: `CADCO_Import_Plan` (Task 7), `CADCO_Import_Repository` (Task 9), the field map (Task 2).
- Produces:
  - `CADCO_Import_Applier::prepare_terms(array $rows): array` — creates categories/tags/brands, returns `['categories'=>int,'tags'=>int,'brands'=>int]`.
  - `CADCO_Import_Applier::apply_batch(CADCO_Import_Plan $plan, int $offset, int $size): array` returning `['done'=>int,'total'=>int,'complete'=>bool,'log'=>list<string>]`.
  - `CADCO_Import_Applier::finalise(): void` — orphan cleanup, related-product links, single rewrite flush.
  - `CADCO_Import_Applier::write_product(array $row, ?int $post_id): int`

- [ ] **Step 1: Create `inc/import/class-cadco-import-applier.php`**

```php
<?php

/**
 * Carries out an approved plan.
 *
 * Two ordering rules are load-bearing.
 *
 * First, every term is created before any product is written, so that
 * assigning a product to its categories never has to create one mid-flight.
 *
 * Second, the rewrite rules are flushed exactly once, at the very end.
 * inc/cadco-woocommerce.php registers one literal rewrite rule per category
 * term and sets a flush flag on created_product_cat, edited_product_cat and
 * delete_product_cat. A run that touches 30 terms would otherwise rebuild the
 * whole rule set 30 times.
 */

declare(strict_types=1);

final class CADCO_Import_Applier
{
    private const META_PREFIX = '_cadco_';

    /**
     * Create every term the workbook implies, parents before children.
     *
     * @param list<array<string, mixed>> $rows
     * @return array{categories:int,tags:int,brands:int}
     */
    public static function prepare_terms(array $rows): array
    {
        $terms  = CADCO_Import_Plan::all_terms($rows);
        $counts = ['categories' => 0, 'tags' => 0, 'brands' => 0];

        foreach ($terms['categories'] as $pair) {
            $parent_id = self::ensure_term($pair['parent'], 'product_cat', 0);

            if ($parent_id > 0) {
                $counts['categories']++;
                self::ensure_term($pair['child'], 'product_cat', $parent_id);
                $counts['categories']++;
            }
        }

        foreach ($terms['tags'] as $tag) {
            if (self::ensure_term($tag, 'product_tag', 0) > 0) {
                $counts['tags']++;
            }
        }

        foreach ($terms['brands'] as $brand) {
            if (taxonomy_exists('product_brand') && self::ensure_term($brand, 'product_brand', 0) > 0) {
                $counts['brands']++;
            }
        }

        return $counts;
    }

    /**
     * One slice of the plan.
     *
     * The work is flattened into a single ordered list so that a batch can
     * span operation types and the progress bar advances evenly. Renames that
     * were not approved are dropped here rather than earlier, so the plan the
     * operator saw stays intact.
     *
     * @return array{done:int,total:int,complete:bool,log:list<string>}
     */
    public static function apply_batch(CADCO_Import_Plan $plan, int $offset, int $size): array
    {
        $queue = self::queue($plan);
        $total = count($queue);
        $log   = [];

        foreach (array_slice($queue, $offset, $size) as $job) {
            $log[] = self::run_job($job);
        }

        $done = min($offset + $size, $total);

        return [
            'done'     => $done,
            'total'    => $total,
            'complete' => $done >= $total,
            'log'      => $log,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function queue(CADCO_Import_Plan $plan): array
    {
        $queue = [];

        foreach ($plan->creates() as $item) {
            $queue[] = ['op' => 'create', 'row' => $item['row'], 'post_id' => null];
        }

        foreach ($plan->updates() as $item) {
            $queue[] = ['op' => 'update', 'row' => $item['row'], 'post_id' => (int) $item['post_id']];
        }

        foreach ($plan->renames() as $item) {
            if (!empty($item['approved'])) {
                $queue[] = [
                    'op'      => 'rename',
                    'row'     => $item['row'],
                    'post_id' => (int) $item['post_id'],
                    'old_sku' => (string) $item['old_sku'],
                ];
            }
        }

        foreach ($plan->trashes() as $item) {
            $queue[] = ['op' => 'trash', 'post_id' => (int) $item['post_id'], 'sku' => (string) $item['sku']];
        }

        return $queue;
    }

    private static function run_job(array $job): string
    {
        if ($job['op'] === 'trash') {
            wp_trash_post((int) $job['post_id']);

            return sprintf('Trashed %s (#%d)', $job['sku'], (int) $job['post_id']);
        }

        if ($job['op'] === 'rename') {
            $id = self::write_product($job['row'], (int) $job['post_id']);
            self::record_redirect((string) $job['old_sku'], $id);

            return sprintf('Renamed %s to %s (#%d)', $job['old_sku'], $job['row']['Model #'], $id);
        }

        $id = self::write_product($job['row'], $job['post_id'] === null ? null : (int) $job['post_id']);

        return sprintf('%s %s (#%d)', $job['op'] === 'create' ? 'Created' : 'Updated', $job['row']['Model #'], $id);
    }

    /**
     * Write one product. Returns its post ID.
     */
    public static function write_product(array $row, ?int $post_id): int
    {
        $sku     = trim((string) ($row['Model #'] ?? ''));
        $product = $post_id === null ? new WC_Product_Simple() : wc_get_product($post_id);

        if (!$product instanceof WC_Product) {
            $product = new WC_Product_Simple();
        }

        $product->set_name(trim((string) ($row['Product Name'] ?? '')));
        $product->set_sku($sku);
        $product->set_slug(sanitize_title($sku));
        $product->set_status('publish');
        $product->set_catalog_visibility('visible');
        $product->set_short_description(trim((string) ($row['Primary Description'] ?? '')));
        $product->set_description(self::description($row));

        foreach (['Height' => 'height', 'Width' => 'width', 'Depth' => 'length', 'Weight' => 'weight'] as $column => $setter) {
            $value = trim((string) ($row[$column] ?? ''));

            if (is_numeric($value)) {
                $product->{'set_' . $setter}($value);
            }
        }

        $product->set_category_ids(self::category_ids($row));
        $product->set_tag_ids(self::tag_ids($row));
        $product->set_attributes(self::attributes($row));

        $id = $product->save();

        self::write_meta($id, $row);
        self::write_brand($id, $row);

        return (int) $id;
    }

    /**
     * Bullet columns become real lists rather than a wall of text.
     */
    private static function description(array $row): string
    {
        $out = '';

        $primary = CADCO_Import_Normaliser::bullets((string) ($row['Supplier Specifications - Bullet Points'] ?? ''));

        if ($primary !== []) {
            $out .= "<ul>\n";

            foreach ($primary as $line) {
                $out .= '<li>' . esc_html($line) . "</li>\n";
            }

            $out .= "</ul>\n";
        }

        $secondary = CADCO_Import_Normaliser::bullets((string) ($row['Secondary Description (Optional)'] ?? ''));

        if ($secondary !== []) {
            $out .= '<h3>' . esc_html__('Additional information', 'cadco-theme') . "</h3>\n<ul>\n";

            foreach ($secondary as $line) {
                $out .= '<li>' . esc_html($line) . "</li>\n";
            }

            $out .= "</ul>\n";
        }

        return $out;
    }

    /**
     * @return list<int>
     */
    private static function category_ids(array $row): array
    {
        $ids = [];

        foreach (CADCO_Import_Plan::categories_for($row) as $pair) {
            $parent_id = self::ensure_term($pair['parent'], 'product_cat', 0);
            $child_id  = self::ensure_term($pair['child'], 'product_cat', $parent_id);

            // Only the deepest term is assigned; WooCommerce walks the ancestor
            // chain itself when it builds the URL.
            if ($child_id > 0) {
                $ids[] = $child_id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return list<int>
     */
    private static function tag_ids(array $row): array
    {
        $ids = [];

        foreach (CADCO_Import_Plan::tags_for($row) as $tag) {
            $id = self::ensure_term($tag, 'product_tag', 0);

            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return array<string, WC_Product_Attribute>
     */
    private static function attributes(array $row): array
    {
        $attributes = [];
        $position   = 0;
        $multi      = cadco_import_multi_value_attributes();

        foreach (cadco_import_attribute_columns() as $column => $slug) {
            $raw = trim((string) ($row[$column] ?? ''));

            if ($raw === '' || strcasecmp($raw, 'n/a') === 0) {
                continue;
            }

            $values = in_array($column, $multi, true)
                ? array_filter(array_map('trim', explode(',', $raw)))
                : [$raw];

            $taxonomy = wc_attribute_taxonomy_name($slug);

            if (!taxonomy_exists($taxonomy)) {
                self::ensure_attribute_taxonomy($slug, $column);
            }

            $term_ids = [];

            foreach ($values as $value) {
                $term = term_exists($value, $taxonomy);

                if ($term === null || $term === 0) {
                    $term = wp_insert_term($value, $taxonomy);
                }

                if (!is_wp_error($term)) {
                    $term_ids[] = (int) $term['term_id'];
                }
            }

            if ($term_ids === []) {
                continue;
            }

            $attribute = new WC_Product_Attribute();
            $attribute->set_id(wc_attribute_taxonomy_id_by_name($slug));
            $attribute->set_name($taxonomy);
            $attribute->set_options($term_ids);
            $attribute->set_position($position++);
            $attribute->set_visible(true);
            $attribute->set_variation(false);

            $attributes[$taxonomy] = $attribute;
        }

        return $attributes;
    }

    private static function ensure_attribute_taxonomy(string $slug, string $label): void
    {
        if (wc_attribute_taxonomy_id_by_name($slug) > 0) {
            return;
        }

        wc_create_attribute([
            'name'         => $label,
            'slug'         => $slug,
            'type'         => 'select',
            'order_by'     => 'menu_order',
            'has_archives' => false,
        ]);

        delete_transient('wc_attribute_taxonomies');
        WC_Cache_Helper::invalidate_cache_group('woocommerce-attributes');

        register_taxonomy(
            wc_attribute_taxonomy_name($slug),
            ['product'],
            ['hierarchical' => false, 'show_ui' => false, 'query_var' => false, 'rewrite' => false]
        );
    }

    private static function write_meta(int $post_id, array $row): void
    {
        foreach (cadco_import_meta_columns() as $column => $key) {
            $value = trim((string) ($row[$column] ?? ''));

            if ($value === '') {
                delete_post_meta($post_id, self::META_PREFIX . $key);
                continue;
            }

            update_post_meta($post_id, self::META_PREFIX . $key, $value);
        }

        update_post_meta($post_id, self::META_PREFIX . 'source_sheet', (string) ($row['__sheet'] ?? ''));
        update_post_meta($post_id, self::META_PREFIX . 'source_row', (int) ($row['__row'] ?? 0));
        update_post_meta($post_id, self::META_PREFIX . 'import_hash', CADCO_Import_Planner::hash($row));
    }

    private static function write_brand(int $post_id, array $row): void
    {
        $brand = trim((string) ($row['Brand Name'] ?? ''));

        if ($brand === '' || !taxonomy_exists('product_brand')) {
            return;
        }

        wp_set_object_terms($post_id, [self::ensure_term($brand, 'product_brand', 0)], 'product_brand');
    }

    /**
     * Find or create a term, returning its ID (0 on failure).
     */
    private static function ensure_term(string $name, string $taxonomy, int $parent): int
    {
        $name = trim($name);

        if ($name === '' || !taxonomy_exists($taxonomy)) {
            return 0;
        }

        $existing = get_term_by('name', $name, $taxonomy);

        if ($existing instanceof WP_Term && (int) $existing->parent === $parent) {
            return $existing->term_id;
        }

        $created = wp_insert_term($name, $taxonomy, ['parent' => $parent]);

        if (is_wp_error($created)) {
            // term_exists is the expected error when the slug is already taken
            // by a term with a different parent.
            $data = $created->get_error_data();

            return is_array($data) && isset($data['term_id']) ? (int) $data['term_id'] : 0;
        }

        return (int) $created['term_id'];
    }

    /**
     * Remember that a product used to live at a different model number.
     */
    private static function record_redirect(string $old_sku, int $post_id): void
    {
        $map               = (array) get_option('cadco_import_redirects', []);
        $map[$old_sku]     = get_permalink($post_id);

        update_option('cadco_import_redirects', $map, false);
    }

    /**
     * Everything that must happen once, after all batches.
     *
     * This is also how "delete the 26 pre-existing categories" is honoured.
     * Do NOT add a step that wipes product_cat before importing: deleting and
     * recreating terms on every run would churn term IDs and break the
     * product/term relations written by earlier batches in the same run.
     *
     * Instead, ensure_term() reuses any existing term whose name matches a
     * derived one — so 'Bakerlux Classic' keeps its curated slug — and every
     * pre-existing term the workbook does not imply ends the run with zero
     * products and is removed here. Same end state, and idempotent.
     */
    public static function finalise(): void
    {
        self::link_related_products();

        foreach (['product_cat', 'product_tag', 'product_brand'] as $taxonomy) {
            foreach (CADCO_Import_Repository::orphan_terms($taxonomy) as $orphan) {
                wp_delete_term($orphan['term_id'], $taxonomy);
            }
        }

        // Exactly one flush per run — see the class docblock.
        delete_option('cadco_flush_category_rules');
        flush_rewrite_rules(false);
    }

    /**
     * Resolve Parent Product references into related-product links.
     *
     * Both products stay simple. The link is stored on the parent as a list of
     * child post IDs and surfaced through the woocommerce_related_products
     * filter, which templates/single-product.html already renders.
     *
     * Rebuilt from scratch every run so a reference removed from the workbook
     * disappears rather than lingering.
     */
    private static function link_related_products(): void
    {
        global $wpdb;

        $wpdb->delete($wpdb->postmeta, ['meta_key' => '_cadco_related_children']);

        $children = $wpdb->get_results(
            "SELECT post_id, meta_value AS parent_sku
               FROM {$wpdb->postmeta}
              WHERE meta_key = '_cadco_parent_model'
                AND meta_value <> ''",
            ARRAY_A
        ) ?: [];

        $grouped = [];

        foreach ($children as $child) {
            $parent_id = CADCO_Import_Repository::find_by_sku((string) $child['parent_sku']);

            if ($parent_id !== null) {
                $grouped[$parent_id][] = (int) $child['post_id'];
            }
        }

        foreach ($grouped as $parent_id => $child_ids) {
            update_post_meta($parent_id, '_cadco_related_children', array_values(array_unique($child_ids)));
        }
    }
}

/**
 * Surface imported parent/child links in the Related Products block.
 *
 * WooCommerce generates related products from shared categories and tags, so
 * there is no field to write to — this filter is the supported way in.
 */
add_filter('woocommerce_related_products', static function ($related, $product_id) {
    $children = get_post_meta((int) $product_id, '_cadco_related_children', true);

    if (is_array($children) && $children !== []) {
        $related = array_values(array_unique(array_merge((array) $related, array_map('intval', $children))));
    }

    return $related;
}, 10, 2);
```

- [ ] **Step 2: Verify term creation against the corrected workbook**

```bash
cd "/Users/gustavogomez/Local Sites/cadco/app/public"
wp eval '
$path = "/Users/gustavogomez/Documents/Projects/CADCO/Products Excel Spreadsheet latest/Product Index Spreadsheet 2026_Website_CORRECTED.xlsx";
$read = CADCO_Import_Reader::read($path);
$norm = CADCO_Import_Normaliser::normalise($read["rows"]);
print_r(CADCO_Import_Applier::prepare_terms($norm["rows"]));
'
```

Expected: no fatal error, and `categories` non-zero. Then confirm the derived tree:

```bash
wp term list product_cat --fields=name,slug,parent --format=table
```

Expected: 4 top-level terms — `Convection Ovens`, `Fast Cooking Ovens`, `Countertop Equipment`, `Foodservice Carts` — plus the derived children.

- [ ] **Step 3: Verify a single product writes correctly**

```bash
wp eval '
$path = "/Users/gustavogomez/Documents/Projects/CADCO/Products Excel Spreadsheet latest/Product Index Spreadsheet 2026_Website_CORRECTED.xlsx";
$read = CADCO_Import_Reader::read($path);
$norm = CADCO_Import_Normaliser::normalise($read["rows"]);
$row  = null;
foreach ($norm["rows"] as $r) { if ($r["Model #"] === "BLC-113") { $row = $r; break; } }
$id = CADCO_Import_Applier::write_product($row, null);
echo "post: $id", PHP_EOL, "url: ", get_permalink($id), PHP_EOL;
echo "wattage: ", get_post_meta($id, "_cadco_wattage", true), PHP_EOL;
'
```

Expected: a post ID, a URL of the form
`https://cadco.local/products/convection-ovens/bakerlux-classic/blc-113/`,
and `wattage: 1440`.

- [ ] **Step 4: Clean up the trial data**

```bash
wp post delete $(wp post list --post_type=product --format=ids) --force
wp term list product_cat --field=term_id | xargs -n1 wp term delete product_cat
```

- [ ] **Step 5: Commit**

```bash
git add inc/import/class-cadco-import-applier.php
git commit -m "feat(import): apply plans in batches with a single rewrite flush"
```

---

## Task 11: The admin screen — upload and validation report

The first half of the UI: upload a workbook, see what is wrong with it. Since validation is all-or-nothing, this screen is what CADCO will use most.

**Files:**
- Create: `inc/import/class-cadco-import-admin.php`
- Create: `assets/css/import-admin.css`
- Test: manual, in wp-admin

**Interfaces:**
- Consumes: the whole pipeline (Tasks 3–8), `CADCO_Import_Repository` (Task 9).
- Produces:
  - `CADCO_Import_Admin::init(): void`
  - `CADCO_Import_Admin::run_pipeline(string $path): array` returning `['report'=>CADCO_Import_Report,'plan'=>?CADCO_Import_Plan,'rows'=>array,'changes'=>array]`.
  - Transient `cadco_import_run_{user_id}` holding the uploaded path and normalised rows between requests.

- [ ] **Step 1: Create `inc/import/class-cadco-import-admin.php`**

```php
<?php

/**
 * Products → Import.
 *
 * The screen is deliberately linear: upload, read the report, fix the sheet,
 * repeat until it passes, then review the plan and apply. Because validation
 * is all-or-nothing, most visits end at the report — so the report is the
 * part that gets the space and the detail.
 */

declare(strict_types=1);

final class CADCO_Import_Admin
{
    private const SLUG      = 'cadco-import';
    private const NONCE     = 'cadco_import';
    private const CAPABILITY = 'manage_woocommerce';

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
        add_action('wp_ajax_cadco_import_batch', [self::class, 'ajax_batch']);
    }

    public static function menu(): void
    {
        add_submenu_page(
            'edit.php?post_type=product',
            __('Import products', 'cadco-theme'),
            __('Import', 'cadco-theme'),
            self::CAPABILITY,
            self::SLUG,
            [self::class, 'render']
        );
    }

    public static function assets(string $hook): void
    {
        if (!str_contains($hook, self::SLUG)) {
            return;
        }

        wp_enqueue_style(
            'cadco-import-admin',
            get_stylesheet_directory_uri() . '/assets/css/import-admin.css',
            [],
            (string) filemtime(get_stylesheet_directory() . '/assets/css/import-admin.css')
        );
    }

    /**
     * Reader → Normaliser → Validator → Planner.
     *
     * The planner runs only when validation passes, so a failing workbook can
     * never produce a plan that somebody might approve.
     *
     * @return array{report:CADCO_Import_Report,plan:?CADCO_Import_Plan,rows:array,changes:array}
     */
    public static function run_pipeline(string $path): array
    {
        $read       = CADCO_Import_Reader::read($path);
        $normalised = CADCO_Import_Normaliser::normalise($read['rows']);
        $report     = CADCO_Import_Validator::validate($normalised['rows'], $read['errors']);

        $plan = $report->passed()
            ? CADCO_Import_Planner::plan($normalised['rows'], CADCO_Import_Repository::current_products())
            : null;

        return [
            'report'  => $report,
            'plan'    => $plan,
            'rows'    => $normalised['rows'],
            'changes' => $normalised['changes'],
        ];
    }

    public static function render(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You are not allowed to import products.', 'cadco-theme'));
        }

        echo '<div class="wrap cadco-import"><h1>' . esc_html__('Import products', 'cadco-theme') . '</h1>';

        $result = null;

        if (isset($_POST['cadco_import_upload'])) {
            check_admin_referer(self::NONCE);
            $result = self::handle_upload();
        }

        if ($result === null) {
            self::render_form();
        } else {
            self::render_result($result);
        }

        echo '</div>';
    }

    private static function render_form(): void
    {
        ?>
        <p class="description">
            <?php esc_html_e('Upload the product workbook. It is checked before anything is changed — if any problem is found, nothing at all is imported.', 'cadco-theme'); ?>
        </p>
        <form method="post" enctype="multipart/form-data">
            <?php wp_nonce_field(self::NONCE); ?>
            <input type="file" name="workbook" accept=".xlsx" required>
            <?php submit_button(__('Check workbook', 'cadco-theme'), 'primary', 'cadco_import_upload'); ?>
        </form>
        <?php
    }

    /**
     * @return array{report:CADCO_Import_Report,plan:?CADCO_Import_Plan,rows:array,changes:array}|null
     */
    private static function handle_upload(): ?array
    {
        if (!isset($_FILES['workbook']) || ($_FILES['workbook']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            self::notice('error', __('No file was uploaded.', 'cadco-theme'));

            return null;
        }

        $file  = $_FILES['workbook'];
        $check = wp_check_filetype_and_ext($file['tmp_name'], $file['name'], [
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);

        if (($check['ext'] ?? '') !== 'xlsx') {
            self::notice('error', __('That file is not an .xlsx workbook.', 'cadco-theme'));

            return null;
        }

        $dir = trailingslashit(wp_upload_dir()['basedir']) . 'cadco-imports/' . gmdate('Y-m-d-His');
        wp_mkdir_p($dir);

        $path = $dir . '/workbook.xlsx';

        if (!move_uploaded_file($file['tmp_name'], $path)) {
            self::notice('error', __('The uploaded file could not be saved.', 'cadco-theme'));

            return null;
        }

        $result = self::run_pipeline($path);

        // Archive the run: the workbook exactly as uploaded, the report, and
        // the plan it produced. When a later import surprises somebody, this
        // is the only record of what the workbook said at the time.
        file_put_contents($dir . '/report.csv', $result['report']->to_csv());

        if ($result['plan'] instanceof CADCO_Import_Plan) {
            file_put_contents($dir . '/plan.json', (string) wp_json_encode([
                'counts'  => $result['plan']->counts(),
                'creates' => array_map(static fn ($c) => $c['row']['Model #'] ?? '', $result['plan']->creates()),
                'updates' => array_map(static fn ($u) => $u['row']['Model #'] ?? '', $result['plan']->updates()),
                'renames' => array_map(
                    static fn ($r) => ['from' => $r['old_sku'], 'to' => $r['new_sku'], 'upc' => $r['upc']],
                    $result['plan']->renames()
                ),
                'trashes' => array_map(static fn ($t) => $t['sku'], $result['plan']->trashes()),
                'changes' => $result['changes'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        set_transient(
            'cadco_import_run_' . get_current_user_id(),
            ['path' => $path, 'dir' => $dir],
            HOUR_IN_SECONDS
        );

        return $result;
    }

    private static function render_result(array $result): void
    {
        $report = $result['report'];

        if (!$report->passed()) {
            self::notice(
                'error',
                sprintf(
                    /* translators: %d: number of problems found */
                    _n('%d problem found. Nothing has been imported.', '%d problems found. Nothing has been imported.', $report->count(), 'cadco-theme'),
                    $report->count()
                )
            );

            self::render_report($report);

            return;
        }

        self::notice('success', __('The workbook is clean. Review the plan below before applying it.', 'cadco-theme'));
        self::render_plan($result['plan'], $result['changes']);
    }

    private static function render_report(CADCO_Import_Report $report): void
    {
        $labels = [
            'A' => __('Identity — duplicate, missing or malformed product identifiers', 'cadco-theme'),
            'B' => __('Consistency — the same value spelled several ways', 'cadco-theme'),
            'C' => __('Completeness — blank fields that must state a value', 'cadco-theme'),
        ];

        foreach ($report->by_tier() as $tier => $issues) {
            printf('<h2>%s <span class="count">%d</span></h2>', esc_html($labels[$tier] ?? $tier), count($issues));

            echo '<table class="widefat striped"><thead><tr>';
            echo '<th>' . esc_html__('Sheet', 'cadco-theme') . '</th>';
            echo '<th>' . esc_html__('Row', 'cadco-theme') . '</th>';
            echo '<th>' . esc_html__('Column', 'cadco-theme') . '</th>';
            echo '<th>' . esc_html__('Found', 'cadco-theme') . '</th>';
            echo '<th>' . esc_html__('Problem', 'cadco-theme') . '</th>';
            echo '<th>' . esc_html__('How to fix', 'cadco-theme') . '</th>';
            echo '</tr></thead><tbody>';

            foreach ($issues as $issue) {
                printf(
                    '<tr><td>%s</td><td>%s</td><td>%s</td><td><code>%s</code></td><td>%s</td><td>%s</td></tr>',
                    esc_html($issue->sheet),
                    esc_html($issue->row === null ? '—' : (string) $issue->row),
                    esc_html($issue->column),
                    esc_html($issue->found),
                    esc_html($issue->message),
                    esc_html($issue->fix)
                );
            }

            echo '</tbody></table>';
        }
    }

    private static function render_plan(CADCO_Import_Plan $plan, array $changes): void
    {
        $counts = $plan->counts();
        ?>
        <ul class="cadco-import-counts">
            <li><strong><?php echo (int) $counts['create']; ?></strong> <?php esc_html_e('to create', 'cadco-theme'); ?></li>
            <li><strong><?php echo (int) $counts['update']; ?></strong> <?php esc_html_e('to update', 'cadco-theme'); ?></li>
            <li><strong><?php echo (int) $counts['rename']; ?></strong> <?php esc_html_e('renames to approve', 'cadco-theme'); ?></li>
            <li><strong><?php echo (int) $counts['trash']; ?></strong> <?php esc_html_e('to trash', 'cadco-theme'); ?></li>
            <li><strong><?php echo (int) $counts['skip']; ?></strong> <?php esc_html_e('unchanged', 'cadco-theme'); ?></li>
        </ul>

        <?php if ($plan->renames() !== []) : ?>
            <h2><?php esc_html_e('Renames', 'cadco-theme'); ?></h2>
            <p class="description">
                <?php esc_html_e('These products kept their UPC but changed model number. Approving one keeps the existing page, its address and its images. Leaving it unticked will trash the old product and create a new one instead.', 'cadco-theme'); ?>
            </p>
            <table class="widefat striped">
                <thead><tr>
                    <th><?php esc_html_e('Approve', 'cadco-theme'); ?></th>
                    <th><?php esc_html_e('Was', 'cadco-theme'); ?></th>
                    <th><?php esc_html_e('Becomes', 'cadco-theme'); ?></th>
                    <th><?php esc_html_e('UPC', 'cadco-theme'); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($plan->renames() as $i => $rename) : ?>
                    <tr>
                        <td><input type="checkbox" class="cadco-rename" value="<?php echo (int) $i; ?>" checked></td>
                        <td><code><?php echo esc_html($rename['old_sku']); ?></code></td>
                        <td><code><?php echo esc_html($rename['new_sku']); ?></code></td>
                        <td><?php echo esc_html($rename['upc']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if ($changes !== []) : ?>
            <h2><?php esc_html_e('Values cleaned up automatically', 'cadco-theme'); ?> <span class="count"><?php echo count($changes); ?></span></h2>
            <table class="widefat striped">
                <thead><tr>
                    <th><?php esc_html_e('Sheet', 'cadco-theme'); ?></th>
                    <th><?php esc_html_e('Row', 'cadco-theme'); ?></th>
                    <th><?php esc_html_e('Column', 'cadco-theme'); ?></th>
                    <th><?php esc_html_e('Was', 'cadco-theme'); ?></th>
                    <th><?php esc_html_e('Now', 'cadco-theme'); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach (array_slice($changes, 0, 200) as $change) : ?>
                    <tr>
                        <td><?php echo esc_html($change['sheet']); ?></td>
                        <td><?php echo (int) $change['row']; ?></td>
                        <td><?php echo esc_html($change['column']); ?></td>
                        <td><code><?php echo esc_html($change['before']); ?></code></td>
                        <td><code><?php echo esc_html($change['after']); ?></code></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <p>
            <button type="button" class="button button-primary" id="cadco-import-apply">
                <?php esc_html_e('Apply this plan', 'cadco-theme'); ?>
            </button>
        </p>
        <div id="cadco-import-progress" hidden>
            <progress value="0" max="100"></progress>
            <p class="cadco-import-status"></p>
        </div>
        <?php
        self::print_apply_script();
    }

    private static function notice(string $type, string $message): void
    {
        printf(
            '<div class="notice notice-%s"><p>%s</p></div>',
            esc_attr($type),
            esc_html($message)
        );
    }

    private static function print_apply_script(): void
    {
        $nonce = wp_create_nonce(self::NONCE);
        ?>
        <script>
        (function () {
            var button = document.getElementById('cadco-import-apply');
            if (!button) { return; }

            var box      = document.getElementById('cadco-import-progress');
            var bar      = box.querySelector('progress');
            var status   = box.querySelector('.cadco-import-status');
            var approved = function () {
                return Array.prototype.slice
                    .call(document.querySelectorAll('.cadco-rename:checked'))
                    .map(function (el) { return el.value; });
            };

            function step(offset) {
                var body = new FormData();
                body.append('action', 'cadco_import_batch');
                body.append('_wpnonce', '<?php echo esc_js($nonce); ?>');
                body.append('offset', offset);
                approved().forEach(function (i) { body.append('approved[]', i); });

                fetch(ajaxurl, { method: 'POST', body: body, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (!res.success) {
                            status.textContent = (res.data && res.data.message) || 'The import failed.';
                            return;
                        }
                        var d = res.data;
                        bar.value = d.total ? Math.round((d.done / d.total) * 100) : 100;
                        status.textContent = d.done + ' / ' + d.total;
                        if (!d.complete) { step(d.done); }
                        else { status.textContent = 'Done — ' + d.total + ' changes applied.'; }
                    })
                    .catch(function () { status.textContent = 'The import request failed.'; });
            }

            button.addEventListener('click', function () {
                button.disabled = true;
                box.hidden = false;
                step(0);
            });
        }());
        </script>
        <?php
    }

    /**
     * One batch. Re-runs the pipeline from the stored file each time so that
     * the applier always works from the same plan the operator approved.
     */
    public static function ajax_batch(): void
    {
        check_ajax_referer(self::NONCE);

        if (!current_user_can(self::CAPABILITY)) {
            wp_send_json_error(['message' => __('Not allowed.', 'cadco-theme')], 403);
        }

        $run = get_transient('cadco_import_run_' . get_current_user_id());

        if (!is_array($run) || !isset($run['path']) || !is_readable($run['path'])) {
            wp_send_json_error(['message' => __('The uploaded workbook has expired. Please upload it again.', 'cadco-theme')], 400);
        }

        $result = self::run_pipeline($run['path']);

        if (!$result['report']->passed() || $result['plan'] === null) {
            wp_send_json_error(['message' => __('The workbook no longer validates.', 'cadco-theme')], 400);
        }

        $plan     = $result['plan'];
        $approved = array_map('intval', (array) ($_POST['approved'] ?? []));

        foreach (array_keys($plan->renames()) as $i) {
            if (in_array($i, $approved, true)) {
                $plan->approve_rename((int) $i);
            }
        }

        $offset = max(0, (int) ($_POST['offset'] ?? 0));

        if ($offset === 0) {
            CADCO_Import_Applier::prepare_terms($result['rows']);
        }

        $batch = CADCO_Import_Applier::apply_batch($plan, $offset, 25);

        if ($batch['complete']) {
            CADCO_Import_Applier::finalise();
        }

        wp_send_json_success($batch);
    }
}
```

- [ ] **Step 2: Create `assets/css/import-admin.css`**

```css
.cadco-import h2 {
	margin-top: 2em;
}

.cadco-import h2 .count {
	display: inline-block;
	margin-left: .5em;
	padding: .1em .6em;
	border-radius: 10px;
	background: #d63638;
	color: #fff;
	font-size: 12px;
	vertical-align: middle;
}

.cadco-import table {
	margin-top: .5em;
}

.cadco-import td code {
	display: inline-block;
	max-width: 32em;
	word-break: break-word;
}

.cadco-import-counts {
	display: flex;
	flex-wrap: wrap;
	gap: 1.5em;
	margin: 1.5em 0;
	padding: 1em 1.25em;
	border: 1px solid #dcdcde;
	border-left: 4px solid #2271b1;
	background: #fff;
}

.cadco-import-counts li {
	margin: 0;
}

.cadco-import-counts strong {
	display: block;
	font-size: 22px;
	line-height: 1.2;
}

#cadco-import-progress progress {
	width: 100%;
	max-width: 32em;
	height: 1.4em;
}
```

- [ ] **Step 3: Manual verification — a failing workbook**

Visit **Products → Import** in wp-admin and upload the **uncorrected** source
workbook.

Expected: a red notice reading "N problems found. Nothing has been imported.",
followed by tables grouped A / B / C. Confirm `wp post list --post_type=product
--format=count` still returns `0` — a failing validation must write nothing.

- [ ] **Step 4: Manual verification — a passing workbook**

Upload the **corrected** workbook.

Expected: a green notice, a counts panel showing 236 to create and 0 renames,
and an "Apply this plan" button.

Click Apply. Expected: the progress bar advances to 100% and reports
"Done — 236 changes applied."

Then verify:

```bash
wp post list --post_type=product --format=count     # 236
wp term list product_cat --fields=name,parent --format=table
wp term list product_tag --format=count             # 26
wp term list product_brand --format=count           # 6
```

- [ ] **Step 5: Verify a re-run writes nothing**

Upload the same corrected workbook again.

Expected: the counts panel shows **0 to create, 0 to update, 236 unchanged**.
This is the property that makes the system safe to run repeatedly.

- [ ] **Step 6: Commit**

```bash
git add inc/import/class-cadco-import-admin.php assets/css/import-admin.css
git commit -m "feat(import): add the products import admin screen"
```

---

## Task 12: The CADCO Specifications metabox

Makes the `_cadco_*` meta visible on the product edit screen. Underscore-prefixed meta is hidden from WordPress's own Custom Fields box, so without this the spec data is invisible in wp-admin.

**Files:**
- Create: `inc/import/class-cadco-product-meta-box.php`
- Test: manual, in wp-admin

**Interfaces:**
- Consumes: `cadco_import_meta_columns()` (Task 2).
- Produces: `CADCO_Product_Meta_Box::init(): void`

- [ ] **Step 1: Create `inc/import/class-cadco-product-meta-box.php`**

```php
<?php

/**
 * The CADCO Specifications panel on the product editor.
 *
 * Read-only on purpose. The workbook is the single source of truth, so
 * anything typed here would be overwritten by the next import — offering
 * editable inputs would be an invitation to lose work. The panel exists so
 * that imported values can be inspected and bad data spotted, and it links
 * back to the sheet and row each product came from.
 */

declare(strict_types=1);

final class CADCO_Product_Meta_Box
{
    private const PREFIX = '_cadco_';

    public static function init(): void
    {
        add_action('add_meta_boxes', [self::class, 'register']);
    }

    public static function register(): void
    {
        add_meta_box(
            'cadco-specifications',
            __('CADCO Specifications', 'cadco-theme'),
            [self::class, 'render'],
            'product',
            'normal',
            'default'
        );
    }

    /**
     * @return array<string, list<string>> group label => meta key suffixes
     */
    private static function groups(): array
    {
        return [
            __('Electrical', 'cadco-theme')  => ['wattage', 'amps'],
            __('Packaging', 'cadco-theme')   => ['package_height', 'package_width', 'package_length', 'package_weight'],
            __('Compliance', 'cadco-theme')  => ['upc', 'prop65_affected', 'prop65_warning', 'warranty_info', 'warranty_url'],
            __('Documents', 'cadco-theme')   => ['spec_sheet_url', 'manual_url', 'diagram_url', 'video_url', 'image_url'],
            __('Catalogue', 'cadco-theme')   => ['footnote', 'disclaimer', 'second_category', 'cubic_feet', 'approvals', 'parent_model', 'legacy_url'],
            __('Source', 'cadco-theme')      => ['source_sheet', 'source_row', 'notes', 'import_hash'],
        ];
    }

    public static function render(WP_Post $post): void
    {
        $labels = array_flip(cadco_import_meta_columns());

        echo '<p class="description">';
        esc_html_e('Imported from the product workbook. These values are read-only: the workbook is the source of truth, so anything changed here is overwritten by the next import.', 'cadco-theme');
        echo '</p>';

        foreach (self::groups() as $group => $keys) {
            $rows = '';

            foreach ($keys as $key) {
                $value = get_post_meta($post->ID, self::PREFIX . $key, true);

                if ($value === '' || $value === false) {
                    continue;
                }

                $label = $labels[$key] ?? ucwords(str_replace('_', ' ', $key));
                $rows .= sprintf(
                    '<tr><th scope="row" style="width:16em">%s</th><td>%s</td></tr>',
                    esc_html($label),
                    self::format((string) $value)
                );
            }

            if ($rows === '') {
                continue;
            }

            printf('<h4 style="margin:1.2em 0 .3em">%s</h4>', esc_html($group));
            printf('<table class="widefat striped"><tbody>%s</tbody></table>', $rows);
        }
    }

    private static function format(string $value): string
    {
        if (filter_var($value, FILTER_VALIDATE_URL) !== false) {
            return sprintf(
                '<a href="%1$s" target="_blank" rel="noopener noreferrer">%1$s</a>',
                esc_url($value)
            );
        }

        return nl2br(esc_html($value));
    }
}
```

- [ ] **Step 2: Manual verification**

Re-import the corrected workbook if the catalogue is empty, then open any
product (for example `BLC-113`) in wp-admin.

Expected: a **CADCO Specifications** panel below the editor with grouped
tables. `Wattage` shows `1440`, `UPC#` shows `654796-52113-5`, and the
document URLs render as clickable links.

Open a product whose optional fields are `n/a` and confirm groups with no
values are omitted rather than rendering empty tables.

- [ ] **Step 3: Commit**

```bash
git add inc/import/class-cadco-product-meta-box.php
git commit -m "feat(import): show imported specifications on the product editor"
```

---

## Task 13: Redirects export and documentation

Ties off the two loose ends: the legacy URLs recorded during renames, and a README section so the next person understands the system.

**Files:**
- Modify: `inc/import/class-cadco-import-admin.php` (add the export handler)
- Modify: `README.md` (add a "Product import" section)

**Interfaces:**
- Consumes: the `cadco_import_redirects` option written by `CADCO_Import_Applier::record_redirect()` (Task 10).
- Produces: `CADCO_Import_Admin::maybe_export_redirects(): void`, hooked to `admin_init`.

- [ ] **Step 1: Add the export handler to `CADCO_Import_Admin`**

Add this method, and register it in `init()` with
`add_action('admin_init', [self::class, 'maybe_export_redirects']);`

```php
    /**
     * Download the legacy-URL redirect map as CSV.
     *
     * Renamed products keep their post ID and their page, but their address
     * changes with the model number. This map pairs the old model number with
     * the new URL so the redirects can be loaded into Yoast or the server
     * config — the importer deliberately does not create them itself, because
     * redirect handling is the SEO plugin's job on this site.
     */
    public static function maybe_export_redirects(): void
    {
        if (($_GET['page'] ?? '') !== self::SLUG || ($_GET['action'] ?? '') !== 'export-redirects') {
            return;
        }

        check_admin_referer(self::NONCE);

        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You are not allowed to do that.', 'cadco-theme'));
        }

        $map    = (array) get_option('cadco_import_redirects', []);
        $handle = fopen('php://output', 'w');

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=cadco-redirects.csv');

        fputcsv($handle, ['Old model number', 'New URL'], ',', '"', '');

        foreach ($map as $old => $url) {
            fputcsv($handle, [$old, $url], ',', '"', '');
        }

        fclose($handle);
        exit;
    }
```

- [ ] **Step 2: Add the download link to the plan view**

In `render_plan()`, immediately after the closing `</ul>` of
`cadco-import-counts`, add:

```php
        <?php if (get_option('cadco_import_redirects', []) !== []) : ?>
            <p>
                <a class="button" href="<?php echo esc_url(wp_nonce_url(
                    admin_url('edit.php?post_type=product&page=cadco-import&action=export-redirects'),
                    self::NONCE
                )); ?>">
                    <?php esc_html_e('Download redirect map', 'cadco-theme'); ?>
                </a>
            </p>
        <?php endif; ?>
```

- [ ] **Step 3: Add the README section**

Append to `README.md`, before the final `---`:

````markdown
## Product import

The catalogue is driven by a single Excel workbook. `inc/import/` parses it,
reports every inconsistency, previews what it would change, and applies nothing
until the workbook is clean.

Run it from **Products → Import**.

### The pipeline

```
Reader → Normaliser → Validator → Planner → Applier
```

The dry run is not a separate mode — it is the pipeline stopping after the
Planner, so the preview and the applied result come from one code path and
cannot drift apart. The first four units are pure PHP and are unit-tested with
no WordPress loaded (`composer test`).

### Rules worth knowing

- **Columns are read by header name, never by position.** Two sheets in the
  same workbook disagree about column order, and the previous revision had
  different sheets entirely. Reordering columns is safe; renaming them is not.
- **Sheets beginning with `_` are ignored,** so the workbook can carry its own
  correction log. Any other unrecognised sheet is an error.
- **Validation is all-or-nothing.** Any problem in any tier blocks the whole
  import. This is deliberate: the catalogue is only as trustworthy as the sheet.
- **`Model #` is the key; `UPC#` detects renames.** A row whose model number is
  new but whose UPC matches an existing product is offered as a rename, which
  preserves the post ID, URL and images. Renames are never applied without
  being ticked.
- **Re-running an unchanged workbook writes nothing.** Each product stores a
  hash of its source row.
- **Categories are fully derived** from the sheet name and the `Type` column.
  A comma-separated `Type` places a product in several sub-categories.
- **Removed products are trashed, never deleted.**

### Rewrite rules

`inc/cadco-woocommerce.php` registers one literal rewrite rule per category
term and flushes on term changes. The applier therefore does all taxonomy work
first and flushes **once** at the end of a run, rather than 30+ times.

### Out of scope

Images, spec sheets and manuals are not imported — most links in the workbook
point to SharePoint locations requiring a CADCO login. The URLs are stored as
`_cadco_*` meta so a later phase can consume them.
````

- [ ] **Step 4: Verify the export**

With at least one rename applied, click **Download redirect map** and confirm
a CSV downloads with an `Old model number, New URL` header.

If no renames have occurred, seed one to test:

```bash
wp option update cadco_import_redirects '{"xaf-113":"https://cadco.local/products/convection-ovens/bakerlux-classic/blc-113/"}' --format=json
```

Then reload the plan view and confirm the button appears.

- [ ] **Step 5: Run the full test suite one last time**

Run: `composer test`
Expected: PASS — all suites green.

- [ ] **Step 6: Commit**

```bash
git add inc/import/class-cadco-import-admin.php README.md
git commit -m "feat(import): export the legacy redirect map and document the system"
```

---

## Verification checklist

Run through this before opening a pull request.

- [ ] `composer test` passes with no skipped tests
- [ ] The **corrected** workbook validates clean and imports 236 products
- [ ] The **uncorrected** workbook fails validation and writes **nothing**
      (`wp post list --post_type=product --format=count` unchanged)
- [ ] Re-uploading the corrected workbook reports 236 unchanged, 0 writes
- [ ] Product URLs are `/products/<parent>/<child>/<model>/`
- [ ] `wp term list product_cat` shows exactly the derived tree, no leftovers
      from the 26 pre-existing terms
- [ ] `wp term list product_tag --format=count` returns 26
- [ ] `wp term list product_brand --format=count` returns 6
- [ ] The CADCO Specifications metabox shows values on a product
- [ ] Renaming a model in a copy of the workbook produces a **rename** row in
      the plan, not a trash-plus-create
- [ ] Deleting a row from a copy of the workbook produces a **trash**, and the
      product is in the trash rather than gone
- [ ] Cart, checkout and my-account remain absent; nothing is purchasable
- [ ] No commit message mentions the tooling used to write it
