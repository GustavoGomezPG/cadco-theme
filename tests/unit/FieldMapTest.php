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

    public function test_upc_uses_the_native_woocommerce_gtin_field(): void
    {
        // WooCommerce 9.1+ has a built-in GTIN/UPC/EAN/ISBN field that already
        // enforces uniqueness and feeds structured data. Storing UPC in a
        // custom meta key instead would give up all three.
        self::assertSame('global_unique_id', cadco_import_native_columns()['UPC#'] ?? null);
        self::assertArrayNotHasKey('UPC#', cadco_import_meta_columns());
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
