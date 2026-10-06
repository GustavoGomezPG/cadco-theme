<?php
/**
 * Cadco Action Columns.
 *
 * Side-by-side routes to act on. Each column is a logo, a heading, a short
 * passage and a button, so the two read as equals rather than as a column of
 * prose with an aside.
 *
 * This is deliberately not cadco-prose-columns. That block is two rich-text
 * columns sharing one button, which is right when one column leads; here both
 * columns carry their own button and their own logo, and the frame gives them
 * their own measures. Modelling it there would have meant a second button, a
 * column-width variant and a container-width variant.
 *
 * The button's shape is read from its own link rather than set by a control:
 * a tel: link is drawn outlined with a handset, anything else solid. The frame
 * draws every telephone button that way, so a control would only be a chance to
 * contradict it.
 *
 * @var array         $attributes
 * @var WP_Block|null $block  Null in the editor preview.
 */

$columns = $attributes['columns'] ?? [];

// $block is null in the editor preview.
$is_preview = ! isset($block) || $block === null;

/* Literal classes so Tailwind's scanner sees them: it cannot generate a class
   from a value computed in PHP. */
$surface = ($attributes['surface'] ?? 'paper') === 'tint' ? 'bg-[#eef2f6]' : 'bg-paper';

/* Literal classes again: the frame gives the opening column 541px and the next
   400px with 82 between them, which is 1023 -- the container's content width.
   A third column, or the even measure, just shares the row.

   These are lg: rather than md:, because 1023px of columns does not fit the
   768px the md breakpoint allows: pinned there, the second column ran past the
   right edge of a tablet and gave the whole page a horizontal scrollbar. */
$wideFirst = ($attributes['measure'] ?? 'even') === 'wideFirst';

$columnClass = static function (int $i) use ($wideFirst): string {
    if (! $wideFirst) {
        return 'lg:flex-1';
    }

    if (0 === $i) {
        return 'lg:w-[541px] lg:shrink-0';
    }

    if (1 === $i) {
        return 'lg:w-[400px] lg:shrink-0';
    }

    return 'lg:flex-1';
};

$reveal = $is_preview ? '' : 'data-proto-animate="manual" data-cadco-reveal-group';

$wrapper = get_block_wrapper_attributes([
    'class' => 'cadco-action-columns w-full ' . $surface . ' pt-[89px] pb-[172px]',
]);
?>
<section <?php echo $wrapper; ?> <?php echo $reveal; ?>>
    <div class="mx-auto w-full max-w-[1071px] px-6">

        <div data-proto-repeater="columns"
             class="flex flex-col gap-16 lg:flex-row lg:items-start lg:justify-between lg:gap-[82px]"
             data-cadco-reveal="items">

            <?php foreach (array_values($columns) as $i => $column) :
                $logo    = $column['logo'] ?? [];
                $heading = (string) ($column['heading'] ?? '');
                $body    = (string) ($column['body'] ?? '');
                $cta     = $column['cta'] ?? [];

                $ctaUrl  = (string) ($cta['url'] ?? '');
                $ctaText = (string) ($cta['text'] ?? '');

                /* A telephone link is the one the frame draws outlined, with a
                   handset beside it. */
                $isPhone = str_starts_with(strtolower($ctaUrl), 'tel:');
                ?>
                <div data-proto-repeater-item class="w-full <?php echo esc_attr($columnClass($i)); ?>">

                    <?php /* A fixed band with the logo centred in it, because the
                             two logos are different heights and the frame lines
                             them up on their middles, not their tops. */ ?>
                    <div class="flex h-[106px] items-center">
                        <span data-proto-field="logo" class="block">
                            <?php if (! empty($logo['url'])) : ?>
                                <img src="<?php echo esc_url($logo['url']); ?>"
                                     alt="<?php echo esc_attr($logo['alt'] ?? ''); ?>"
                                     class="block h-auto max-h-[106px] w-auto max-w-[175px]" />
                            <?php endif; ?>
                        </span>
                    </div>

                    <h2 data-proto-field="heading"
                        data-cadco-reveal="lines"
                        class="m-0 mt-[26px] font-display text-[30px] font-bold leading-[1.2] text-true-black md:text-[36px]">
                        <?php echo esc_html($heading); ?>
                    </h2>

                    <div data-proto-field="body" class="cadco-action-prose mt-[43px]">
                        <?php echo wp_kses_post($body); ?>
                    </div>

                    <?php if ($ctaUrl !== '' || $ctaText !== '' || $is_preview) : ?>
                        <a data-proto-field="cta"
                           href="<?php echo esc_url($ctaUrl); ?>"
                           <?php if (! empty($cta['target'])) : ?>target="<?php echo esc_attr($cta['target']); ?>"<?php endif; ?>
                           <?php if (! empty($cta['rel'])) : ?>rel="<?php echo esc_attr($cta['rel']); ?>"<?php endif; ?>
                           class="mt-10 inline-flex items-center gap-2 rounded-[10px] font-display font-bold leading-none no-underline transition-colors <?php
                               echo $isPhone
                                   ? 'min-h-[44px] md:min-h-[36px] px-4 py-2 text-[15px] border border-cadco-blue text-cadco-blue hover:bg-cadco-blue/5'
                                   : 'min-h-[58px] px-6 py-4 text-[16px] bg-cadco-blue text-white hover:bg-[#00395a]'; ?>">
                            <?php if ($isPhone) : ?>
                                <?php /* Inline so it takes the link's colour and needs no asset. */ ?>
                                <svg class="h-4 w-4 shrink-0" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                                    <path d="M6.3 3.5 8 7l-1.6 1.3a11 11 0 0 0 5.3 5.3L13 12l3.5 1.7v3A1.3 1.3 0 0 1 15.2 18 13.5 13.5 0 0 1 2 4.8 1.3 1.3 0 0 1 3.3 3.5h3Z"
                                          stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" />
                                </svg>
                            <?php endif; ?>
                            <?php echo esc_html($ctaText); ?>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
