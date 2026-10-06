<?php
/**
 * Cadco Numbered Steps.
 *
 * A heading over a row of numbered steps, with an optional footnote beneath.
 *
 * The number is drawn from the step's position rather than authored. The frame
 * shows 1, 2, 3 in circles, and an authored number is a number someone has to
 * remember to change: inserting a step between two others would otherwise mean
 * renumbering every step after it by hand, and a repeater exists precisely so
 * that order is the thing being edited.
 *
 * @var array         $attributes
 * @var WP_Block|null $block  Null in the editor preview.
 */

$heading  = (string) ($attributes['heading'] ?? '');
$steps    = $attributes['steps'] ?? [];
$footnote = (string) ($attributes['footnote'] ?? '');

// $block is null in the editor preview.
$is_preview = ! isset($block) || $block === null;

/* Literal classes so Tailwind's scanner sees them. */
$surface = ($attributes['surface'] ?? 'tint') === 'paper' ? 'bg-paper' : 'bg-[#e8edf4]';

/* A colour control lands in a style attribute, so it is checked against the
   shapes the control can produce rather than merely escaped. */
$marker = preg_match('/^(#[0-9a-f]{3,8}|(rgb|hsl)a?\([0-9a-z%.,\/\s]+\))$/i', trim((string) ($attributes['markerColor'] ?? '')))
    ? trim((string) $attributes['markerColor'])
    : '#00476e';

$reveal = $is_preview ? '' : 'data-proto-animate="manual" data-cadco-reveal-group';

$wrapper = get_block_wrapper_attributes([
    'class' => 'cadco-numbered-steps w-full ' . $surface . ' py-[88px] md:pt-[112px] md:pb-[108px]',
]);
?>
<section <?php echo $wrapper; ?> <?php echo $reveal; ?>>
    <div class="mx-auto w-full max-w-[1140px] px-6">

        <h2 data-proto-field="heading"
            data-cadco-reveal="lines"
            class="m-0 font-display text-[28px] font-bold leading-[1.2] text-true-black md:text-[36px]">
            <?php echo esc_html($heading); ?>
        </h2>

        <?php if (empty($steps) && $is_preview) : ?>
            <p class="mt-10 text-body-sm text-gray-500">
                <?php esc_html_e('Add a step in the block sidebar.', 'cadco-theme'); ?>
            </p>
        <?php endif; ?>

        <ol data-proto-repeater="steps"
            data-cadco-reveal="items"
            class="m-0 mt-[62px] grid list-none grid-cols-1 gap-x-[90px] gap-y-10 p-0 md:grid-cols-2 lg:grid-cols-[repeat(3,271px)]">

            <?php foreach ((array) $steps as $i => $item) : ?>
                <li data-proto-repeater-item class="m-0 flex flex-col">
                    <span class="flex h-[30px] w-[30px] items-center justify-center rounded-full border text-[13px] font-bold leading-none"
                          style="border-color:<?php echo esc_attr($marker); ?>;color:<?php echo esc_attr($marker); ?>"
                          aria-hidden="true">
                        <?php echo (int) ($i + 1); ?>
                    </span>

                    <p data-proto-field="body"
                       class="m-0 mt-[11px] font-display text-[15px] font-bold leading-[24px]"
                       style="color:<?php echo esc_attr($marker); ?>">
                        <?php echo esc_html((string) ($item['body'] ?? '')); ?>
                    </p>
                </li>
            <?php endforeach; ?>
        </ol>

        <?php if ($footnote !== '' || $is_preview) : ?>
            <div data-proto-field="footnote"
                 class="cadco-steps-footnote mt-[75px] max-w-[540px] font-display text-[15px] font-normal leading-[24px] text-true-black">
                <?php echo wp_kses_post($footnote); ?>
            </div>
        <?php endif; ?>
    </div>
</section>
