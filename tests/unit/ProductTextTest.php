<?php

declare(strict_types=1);

namespace CADCO\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ProductTextTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../inc/cadco-product-text.php';
    }

    public function test_short_text_is_left_alone(): void
    {
        self::assertSame('Small Warming Shelf', \cadco_text_truncate('Small Warming Shelf', 70));
    }

    public function test_long_text_is_cut_on_a_word_with_an_ellipsis(): void
    {
        $title = 'Steam Pan Holder – Stainless with charcoal finish (Note: For -HD series buffet servers & WT-10-HD & WT-40-HD warming shelves; NOT for carts)';
        $cut   = \cadco_text_truncate($title, 70);

        self::assertLessThanOrEqual(70, mb_strlen($cut));
        self::assertStringEndsWith('…', $cut);
        self::assertStringStartsWith('Steam Pan Holder – Stainless with charcoal finish', $cut);
        self::assertDoesNotMatchRegularExpression('~\s…$~u', $cut, 'no space before the ellipsis');
    }

    public function test_one_very_long_word_is_cut_mid_word_rather_than_lost(): void
    {
        $cut = \cadco_text_truncate('Supercalifragilisticexpialidocious oven', 12);

        self::assertSame(12, mb_strlen($cut));
    }

    public function test_a_description_that_restates_the_title_is_recognised(): void
    {
        // XAFT-04HS-TR: the short description is the name plus 'Bakerlux' and 'Stainless Steel'.
        self::assertTrue(\cadco_text_repeats(
            '4-Shelf Half Size Bakerlux Heavy-Duty Digital Stainless Steel Countertop Convection Oven with Right Side Door and Touch Control Panel - 208-240V',
            '4-Shelf Half Size Heavy-Duty Digital Countertop Convection Oven with Right Side Door and Touch Control Panel – 208-240V'
        ));
    }

    public function test_a_description_with_real_detail_is_kept(): void
    {
        self::assertFalse(\cadco_text_repeats(
            'Third Size 2 1/2" deep stainless steam pan with clear dome lid',
            'Third Size Stainless Pan with Clear Dome Lid'
        ));
        self::assertFalse(\cadco_text_repeats(
            '13 7/8"x14" Stainless Warming Surface with variable heat control 155-205° F',
            'Small Warming Shelf'
        ));
    }

    public function test_lines_are_fitted_to_a_budget_and_a_line_count(): void
    {
        $lines = [
            'Steam Pan Holder',
            'holds (1) full size or (2) 1/2 size or (3) 1/3 size 2-1/2" steam table pans (not included)',
            'For -HD series countertop buffet servers',
            'UL Sanitation',
        ];

        $fit = \cadco_text_fit_lines($lines, 100, 3);

        self::assertLessThanOrEqual(3, count($fit));
        self::assertLessThanOrEqual(100, mb_strlen(implode('', $fit)));
        self::assertStringEndsWith('…', end($fit), 'the cut is visible');
        self::assertSame('Steam Pan Holder', $fit[0]);
    }

    public function test_lines_that_fit_are_shown_whole(): void
    {
        self::assertSame(['3 shelf', '120v (No Humidity)', 'GO Panel'], \cadco_text_fit_lines(['3 shelf', '120v (No Humidity)', 'GO Panel'], 100, 3));
    }

    public function test_dropping_lines_over_the_count_marks_the_last_one(): void
    {
        self::assertSame(['a line', 'b line', 'c line…'], \cadco_text_fit_lines(['a line', 'b line', 'c line', 'd line'], 100, 3));
    }
}
