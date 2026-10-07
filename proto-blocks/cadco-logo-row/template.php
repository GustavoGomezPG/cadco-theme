<?php
/**
 * Cadco Logo Row.
 *
 * A heading over a row of association logos. The logos are a gallery control
 * rather than a repeater: the images ARE the layout here, and a repeater would
 * inject its editing chrome into the markup, so the editor canvas would stop
 * matching the front end.
 *
 * Supplied logos never share a bounding box -- a wordmark is wide and short, a
 * badge is square -- so each is fitted inside one box rather than set to a
 * common height. Matching heights makes a square badge tower over the
 * wordmarks beside it; fitting them to a box balances them by area, which is
 * what the design draws.
 *
 * @var array         $attributes
 * @var WP_Block|null $block  Null in the editor preview.
 */

$heading = (string) ($attributes['heading'] ?? '');
$logos   = $attributes['logos'] ?? [];

$boxW = max(80, min(420, (int) ($attributes['boxWidth'] ?? 230)));
$boxH = max(32, min(200, (int) ($attributes['boxHeight'] ?? 105)));

// $block is null in the editor preview.
$is_preview = ! isset($block) || $block === null;

/* Literal classes so Tailwind's scanner sees them. */
$justify = [
    'center'  => 'justify-center',
    'between' => 'justify-between',
    'start'   => 'justify-start',
][ $attributes['align'] ?? 'center' ] ?? 'justify-center';

$surface = ($attributes['tone'] ?? 'paper') === 'tint' ? 'bg-[#f2f5f9]' : 'bg-paper';

$reveal = $is_preview ? '' : 'data-proto-animate="manual" data-cadco-reveal-group';

$wrapper = get_block_wrapper_attributes([
    'class' => 'cadco-logo-row w-full ' . $surface . ' pt-[165px] pb-[185px]',
]);
?>
<section <?php echo $wrapper; ?> <?php echo $reveal; ?>>
    <div class="mx-auto w-full max-w-[1140px] px-6">
        <h2 data-proto-field="heading"
            data-cadco-reveal="rise"
            class="m-0 text-center font-display text-[28px] font-bold leading-[1.25] text-true-black md:text-[36px]">
            <?php echo esc_html($heading); ?>
        </h2>

        <?php if (empty($logos) && $is_preview) : ?>
            <p class="mt-8 text-center text-body-sm text-gray-500">
                <?php esc_html_e('Add logos in the block sidebar.', 'cadco-theme'); ?>
            </p>
        <?php endif; ?>

        <ul class="m-0 mt-[102px] flex list-none flex-wrap items-center <?php echo esc_attr($justify); ?> gap-x-[130px] gap-y-10 p-0"
            data-cadco-reveal="items">
            <?php foreach ((array) $logos as $logo) : ?>
                <?php if (empty($logo['url'])) { continue; } ?>
                <li class="flex shrink-0 items-center justify-center"
                    style="width:<?php echo (int) $boxW; ?>px;height:<?php echo (int) $boxH; ?>px">
                    <img src="<?php echo esc_url($logo['url']); ?>"
                         alt="<?php echo esc_attr($logo['alt'] ?? ''); ?>"
                         class="max-h-full max-w-full object-contain"
                         loading="lazy" decoding="async" />
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</section>
