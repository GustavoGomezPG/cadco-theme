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
 * The sheets the importer recognises, mapped to the top-level product
 * category each one creates on the storefront.
 *
 * The tab name used to *be* the category name — CADCO_Import_Plan::categories_for()
 * title-cased it and used it as the parent term. That made a spreadsheet tab
 * label a public-facing category name and a URL segment, so renaming a tab
 * silently restructured the shop: categories match by name and are never
 * renamed in place, so a retitled tab creates a second category and strands
 * the first, empty, on a live URL.
 *
 * Declaring the category here breaks that coupling. CADCO may retitle a tab
 * however they like; what customers see changes only when this map does.
 *
 * The workbook's tab is currently 'CONVECTION | COOK & HOLD OVENS' — the pipe
 * is a tab-label convention, not something that belongs in a category name.
 *
 * Matched case-insensitively. A sheet whose name begins with '_' is skipped
 * so CADCO can keep working notes in the workbook; any other unrecognised
 * sheet is a Tier A error rather than a silent skip, because a genuinely new
 * product line should be a deliberate decision rather than a new category
 * appearing on the shop because someone added a tab.
 *
 * @return array<string, string> sheet name => category name
 */
function cadco_import_sheet_categories(): array
{
    return [
        'CONVECTION | COOK & HOLD OVENS' => 'Convection & Cook-Hold Ovens',
        'FAST COOKING OVENS'             => 'Fast Cooking Ovens',
        'COUNTERTOP EQUIPMENT'           => 'Countertop Equipment',
        'FOODSERVICE CARTS'              => 'Foodservice Carts',
    ];
}

/**
 * Tab names CADCO has shipped before, mapped to the canonical name above.
 *
 * A workbook is not always the newest one: the History tab restores an
 * archived run from an earlier revision, and CADCO occasionally reverts a
 * change. Without this an older workbook stops importing the moment a tab is
 * retitled, which is the failure this whole indirection exists to avoid.
 *
 * Declared rather than guessed, for the same reason as
 * cadco_import_value_aliases(): no string-distance rule relates
 * 'CONVECTION OVENS' to 'CONVECTION | COOK & HOLD OVENS' without also
 * matching sheets that are genuinely different.
 *
 * @return array<string, string> former sheet name => canonical sheet name
 */
function cadco_import_sheet_aliases(): array
{
    return [
        // Retitled in the 12 August 2026 revision.
        'CONVECTION OVENS' => 'CONVECTION | COOK & HOLD OVENS',
    ];
}

/**
 * The sheets the importer recognises, in canonical order.
 */
function cadco_import_sheets(): array
{
    return array_keys(cadco_import_sheet_categories());
}

/**
 * The storefront category a sheet places its products under, or '' if the
 * sheet is not one the importer recognises.
 *
 * Resolves a former tab name too. The Reader canonicalises '__sheet' as it
 * reads, so in a live run the alias branch is never needed — it is here so
 * that a row carrying a name from an older revision, wherever it reaches this
 * function from, still lands in the same category rather than in none.
 */
function cadco_import_sheet_category(string $sheet): string
{
    $sheet = trim($sheet);

    foreach (cadco_import_sheet_aliases() as $alias => $canonical) {
        if (strcasecmp($sheet, $alias) === 0) {
            $sheet = $canonical;
            break;
        }
    }

    return cadco_import_sheet_categories()[$sheet] ?? '';
}

/**
 * Column headings that mean the same field, mapped to the canonical heading.
 *
 * Applied by the Reader as it builds each sheet's header row, so every unit
 * downstream sees one name. The convection sheet calls its manual column
 * 'Install / Manual URL' while the other three call it 'Manual URL'; because
 * the manual column is not required, the mismatch dropped 73 links per run
 * without raising anything.
 *
 * @return array<string, string> heading as written => canonical heading
 */
function cadco_import_column_aliases(): array
{
    return [
        'Install / Manual URL' => 'Manual URL',
        'Installation Manual URL' => 'Manual URL',
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
 * Values that mean the same thing but share no spelling.
 *
 * Everything else in this system infers variants from the text: the
 * normaliser groups by a case- and punctuation-insensitive key, and the
 * validator by edit distance. Neither can relate 'IT' to 'Italy' — they are
 * different strings of different lengths, and no threshold that caught them
 * would avoid flagging genuinely different values.
 *
 * So these are declared rather than guessed. The workbook holds 43 'IT'
 * against 37 'Italy'; without this table both survive and the catalogue ends
 * up with two country terms for one country, with nobody told.
 *
 * Keyed by column, then by the alias in lower case. Only ISO country codes so
 * far — add entries when a new abbreviation actually appears in the data.
 *
 * @return array<string, array<string, string>>
 */
function cadco_import_value_aliases(): array
{
    return [
        'Country Of Origin' => [
            'italy'          => 'IT',
            'united states'  => 'US',
            'usa'            => 'US',
            'united kingdom' => 'UK',
            'germany'        => 'DE',
        ],
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
 * Media columns whose cell may hold several links.
 *
 * A product often has more than one of a thing — two spec sheets, a manual
 * plus an installation guide, several product photographs — and the workbook
 * has one column for each kind. CADCO separate them with a comma or a line
 * break inside the cell, so both are accepted; see CADCO_Import_Media::urls().
 *
 * 'Warranty URL' and 'Website URL' are deliberately absent. They are single
 * addresses rather than collections, and 'Website URL' in particular is the
 * left-hand side of the legacy redirect map — a cell holding two of them
 * would make the old address ambiguous.
 */
function cadco_import_multi_url_columns(): array
{
    return [
        'Images URL',
        'Video URL',
        'Spec Sheet URL',
        'Diagram URL',
        'Manual URL',
    ];
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
 * The meta key suffix the importer stores each product's comparable payload
 * under — the snapshot Task 15's per-field diff compares the next run
 * against. Declared here, next to the rest of the column/key rules, so the
 * applier that writes it and the repository that reads it back can never
 * disagree on the key.
 *
 * Not a workbook column, so it carries no entry in cadco_import_meta_columns().
 * It is machine bookkeeping rather than product data — CADCO_Product_Meta_Box's
 * groups() is an explicit allow-list of what the CADCO Specifications panel
 * shows, so a suffix simply absent from every group there, this one included,
 * cannot appear on the panel and swamp it.
 */
function cadco_import_snapshot_meta_key(): string
{
    return 'import_snapshot';
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
