<?php

declare(strict_types=1);

namespace CADCO\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The specification band's rows, built from an imported product's values.
 */
final class ProductSpecsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../inc/cadco-product-specs.php';
    }

    public function test_a_real_product_reads_like_a_spec_sheet(): void
    {
        // VK-120+ as the 13 Nov workbook has it.
        $rows = \cadco_product_spec_rows_from_values([
            'width' => '24.82', 'depth' => '25.94', 'height' => '13.5', 'weight' => '67',
            'package_width' => '30.5', 'package_depth' => '32', 'package_height' => '18', 'package_weight' => '78',
            'voltage' => '120', 'wattage' => '1920', 'amps' => '16', 'plug' => 'NEMA 5-20P',
            'material' => 'Stainless Steel', 'color' => 'Stainless',
            'certifications' => 'NSF Listed; UL Listed; cULus Listed',
            'country' => 'US', 'lead_time' => '3 weeks',
        ]);

        self::assertSame([
            'Unit Dimensions'     => '24.82″ W × 25.94″ D × 13.5″ H',
            'Weight'              => '67 lbs',
            'Shipping Dimensions' => '30.5″ W × 32″ D × 18″ H',
            'Shipping Weight'     => '78 lbs',
            'Volts'               => '120 V',
            'Watts'               => '1920 W',
            'Amps'                => '16 A',
            'Plug'                => 'NEMA 5-20P',
            'Material'            => 'Stainless Steel',
            'Color'               => 'Stainless',
            'Certifications'      => 'NSF Listed, UL Listed, cULus Listed',
            'Country of Origin'   => 'US',
            'Lead Time'           => '3 weeks',
        ], $rows);
    }

    public function test_n_a_and_blank_values_are_left_out(): void
    {
        $rows = \cadco_product_spec_rows_from_values([
            'width' => '10', 'depth' => 'n/a', 'height' => '',
            'voltage' => 'N/A', 'wattage' => 'n/a', 'amps' => '—', 'plug' => 'n/a', 'material' => 'Aluminum',
        ]);

        self::assertSame(['Unit Dimensions' => '10″ W', 'Material' => 'Aluminum'], $rows);
    }

    public function test_a_value_that_names_its_own_unit_is_left_as_written(): void
    {
        self::assertSame('208-240 V', \cadco_spec_with_unit('208-240', 'V'));
        self::assertSame('120V', \cadco_spec_with_unit('120V', 'V'));
        self::assertSame('120/208V 3PH', \cadco_spec_with_unit('120/208V 3PH', 'V'));
    }

    public function test_a_product_with_nothing_has_no_rows(): void
    {
        self::assertSame([], \cadco_product_spec_rows_from_values([]));
    }
}
