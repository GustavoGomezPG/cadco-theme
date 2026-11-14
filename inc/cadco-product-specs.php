<?php
/**
 * Specification rows from a product's structured data.
 *
 * The importer stores a product's specifications where WooCommerce and the
 * importer keep structured data — native dimensions and weight, attributes
 * (Voltage, Plug Type, Material, ...), and `_cadco_*` meta (wattage, amps,
 * package size) — not as a label/value list in the description. Read from the
 * description alone, the specification band came out empty for every imported
 * product.
 *
 * cadco_product_spec_rows_from_values() is pure: it takes the raw values and
 * returns the rows the band shows, so the formatting is unit-tested without
 * WordPress. cadco_product_structured_spec_rows() gathers those values for a
 * product.
 */

declare(strict_types=1);

/**
 * Whether a cell value means "nothing to show".
 *
 * The workbook writes 'n/a' rather than leaving a cell blank, so that "not
 * applicable" is distinguishable from "not filled in" — both are blanks to a
 * customer.
 */
function cadco_spec_is_empty(string $value): bool
{
    $value = strtolower(trim($value));

    return $value === '' || in_array($value, ['n/a', 'na', 'n.a.', 'none', '-', '--', '—', 'not applicable'], true);
}

/**
 * A number with a unit, unless the value already says what it is.
 *
 * '120' becomes '120 V'; '208-240' becomes '208-240 V'; '120V' and
 * '120/208V' are left as written.
 */
function cadco_spec_with_unit(string $value, string $unit): string
{
    $value = trim($value);

    if ($value === '' || preg_match('~[a-z]~i', $value) === 1) {
        return $value;
    }

    return $value . ' ' . $unit;
}

/**
 * '24.82″ W × 25.94″ D × 13.5″ H', from whichever of the three are known.
 */
function cadco_spec_dimensions(string $width, string $depth, string $height): string
{
    $parts = [];

    foreach (['W' => $width, 'D' => $depth, 'H' => $height] as $axis => $value) {
        if (!cadco_spec_is_empty($value)) {
            $parts[] = trim($value) . '″ ' . $axis;
        }
    }

    return implode(' × ', $parts);
}

/**
 * The band's rows, in the order a buyer reads them: size, then power, then
 * the rest. Labels are chosen to fall into the band's groups
 * (cadco_product_spec_groups()); anything else lands in "Details".
 *
 * @param array<string, string> $v keys: width, depth, height, weight,
 *        package_width, package_depth, package_height, package_weight,
 *        capacity, size, cubic_feet, voltage, wattage, amps, plug,
 *        material, color, certifications, approvals, country, lead_time
 * @return array<string, string>
 */
function cadco_product_spec_rows_from_values(array $v): array
{
    $get  = static fn (string $key): string => trim((string) ($v[$key] ?? ''));
    $rows = [];

    $add = static function (string $label, string $value) use (&$rows): void {
        if (!cadco_spec_is_empty($value)) {
            $rows[$label] = $value;
        }
    };

    $add('Unit Dimensions', cadco_spec_dimensions($get('width'), $get('depth'), $get('height')));
    $add('Weight', cadco_spec_is_empty($get('weight')) ? '' : cadco_spec_with_unit($get('weight'), 'lbs'));
    $add('Capacity', $get('capacity'));
    $add('Size', $get('size'));
    $add('Cubic Feet', $get('cubic_feet'));
    $add('Shipping Dimensions', cadco_spec_dimensions($get('package_width'), $get('package_depth'), $get('package_height')));
    $add('Shipping Weight', cadco_spec_is_empty($get('package_weight')) ? '' : cadco_spec_with_unit($get('package_weight'), 'lbs'));

    $add('Volts', cadco_spec_is_empty($get('voltage')) ? '' : cadco_spec_with_unit($get('voltage'), 'V'));
    $add('Watts', cadco_spec_is_empty($get('wattage')) ? '' : cadco_spec_with_unit($get('wattage'), 'W'));
    $add('Amps', cadco_spec_is_empty($get('amps')) ? '' : cadco_spec_with_unit($get('amps'), 'A'));
    $add('Plug', $get('plug'));

    $add('Material', $get('material'));
    $add('Color', $get('color'));
    $add('Certifications', str_replace(';', ',', $get('certifications')));
    $add('Approvals', $get('approvals'));
    $add('Country of Origin', $get('country'));
    $add('Lead Time', $get('lead_time'));

    return $rows;
}
