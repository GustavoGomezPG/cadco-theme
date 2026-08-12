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
        // WooCommerce's own GTIN/UPC/EAN/ISBN field, added in 9.1. Using it
        // rather than a custom meta key buys uniqueness enforcement, the
        // native Inventory-tab UI, and gtin in the structured data.
        'UPC#'                                    => 'global_unique_id',
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
