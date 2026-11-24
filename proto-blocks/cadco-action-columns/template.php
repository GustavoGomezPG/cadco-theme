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

/* The frame gives the opening column 541px and the next 400px. Those are held
   as a ratio rather than as two fixed widths, because the block now shares the
   site's container instead of one cut to this frame: fixed widths in a wider
   box leave their slack to the gap, which would have opened the frame's 82px to
   roughly 151. Growing in proportion keeps the frame's balance at any measure.

   These are lg: rather than md:, because two columns of this width do not fit
   the 768px the md breakpoint allows: pinned there, the second column ran past
   the right edge of a tablet and gave the whole page a horizontal scrollbar. */
$wideFirst = ($attributes['measure'] ?? 'even') === 'wideFirst';

$columnClass = static function (int $i) use ($wideFirst): string {
    if (! $wideFirst) {
        return 'lg:flex-1';
    }

    if (0 === $i) {
        return 'lg:flex-[541_1_0%]';
    }

    if (1 === $i) {
        return 'lg:flex-[400_1_0%]';
    }

    return 'lg:flex-1';
};

/* The logo band is optional. When no column has a logo it is left out
   entirely (the editor keeps it, to be filled), and the section's top padding
   grows to the frame's 109px so the headings still sit where it puts them. */
$anyLogo  = (bool) array_filter((array) $columns, static fn ($c): bool => ! empty(($c['logo'] ?? [])['url']));
$showLogo = $anyLogo || $is_preview;

$reveal = $is_preview ? '' : 'data-proto-animate="manual" data-cadco-reveal-group';

$wrapper = get_block_wrapper_attributes([
    'class' => 'cadco-action-columns w-full ' . $surface . ' pt-[89px] pb-[172px]',
]);
?>
<section <?php echo $wrapper; ?> <?php echo $reveal; ?><?php echo $showLogo ? '' : ' style="padding-top:109px"'; ?>>
    <div class="mx-auto w-full max-w-[1140px] px-6">

        <div data-proto-repeater="columns"
             class="flex flex-col gap-16 lg:flex-row lg:items-start lg:justify-between lg:gap-[82px]"
             data-cadco-reveal="items">

            <?php foreach (array_values($columns) as $i => $column) :
                $logo    = $column['logo'] ?? [];
                $heading = (string) ($column['heading'] ?? '');
                $body    = (string) ($column['body'] ?? '');
                $cta     = $column['cta'] ?? [];
                $badge   = $column['badge'] ?? [];
                $download     = $column['download'] ?? [];
                $downloadUrl  = (string) ($download['url'] ?? '');
                $downloadText = (string) ($download['text'] ?? '');

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
                    <?php if ($showLogo) : ?>
                    <div class="flex h-[106px] items-center">
                        <span data-proto-field="logo" class="block">
                            <?php if (! empty($logo['url'])) : ?>
                                <img src="<?php echo esc_url($logo['url']); ?>"
                                     alt="<?php echo esc_attr($logo['alt'] ?? ''); ?>"
                                     class="block h-auto max-h-[106px] w-auto max-w-[166px]" />
                            <?php endif; ?>
                        </span>
                    </div>
                    <?php endif; ?>

                    <h2 data-proto-field="heading"
                        data-cadco-reveal="lines"
                        class="m-0 <?php echo $showLogo ? 'mt-[26px]' : 'mt-0'; ?> font-display text-[30px] font-bold leading-[1.2] text-true-black md:text-[36px]">
                        <?php echo esc_html($heading); ?>
                    </h2>

                    <div data-proto-field="body" class="cadco-action-prose mt-[43px]">
                        <?php echo wp_kses_post($body); ?>
                    </div>

                    <?php
                    /* The column's buttons share one row and wrap when it is
                       narrow: the main button, then the optional download. */
                    $hasCta      = $ctaUrl !== '' || $ctaText !== '' || $is_preview;
                    $hasDownload = $downloadText !== '' || $is_preview;

                    /* A link to another site, or one set to open in a new tab, gets the
                       frame's outward arrow. */
                    $ctaHost    = (string) wp_parse_url($ctaUrl, PHP_URL_HOST);
                    $isExternal = ! $isPhone && (
                        ($cta['target'] ?? '') === '_blank'
                        || ($ctaHost !== '' && $ctaHost !== (string) wp_parse_url(home_url(), PHP_URL_HOST))
                    );
                    ?>
                    <?php if ($hasCta || $hasDownload) : ?>
                    <div class="mt-10 flex flex-wrap items-center gap-4">
                        <?php if ($hasCta) : ?>
                            <a data-proto-field="cta"
                               href="<?php echo esc_url($ctaUrl); ?>"
                               <?php if (! empty($cta['target'])) : ?>target="<?php echo esc_attr($cta['target']); ?>"<?php endif; ?>
                               <?php if (! empty($cta['rel'])) : ?>rel="<?php echo esc_attr($cta['rel']); ?>"<?php endif; ?>
                               style="white-space:nowrap"
                               class="inline-flex items-center gap-2 rounded-[10px] font-display font-bold leading-none no-underline transition-colors <?php
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
                                <?php if ($isExternal) : ?>
                                    <svg class="shrink-0" width="12" height="12" style="margin-left:6px" viewBox="0 0 12 12" fill="none" aria-hidden="true">
                                        <path d="M2.5 9.5l7-7M4 2.5h5.5V8" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                <?php endif; ?>
                            </a>
                        <?php endif; ?>

                        <?php /* Optional: a file to take away. Outlined at the main
                                 button's size, with the icon after the label, as
                                 the frame draws it. Always present in the editor
                                 so it can be filled in. */ ?>
                        <?php if ($hasDownload) : ?>
                            <a data-proto-field="download"
                               href="<?php echo esc_url($downloadUrl !== '' ? $downloadUrl : '#'); ?>"
                               <?php if (! empty($download['target'])) : ?>target="<?php echo esc_attr($download['target']); ?>"<?php endif; ?>
                               <?php if (! empty($download['rel'])) : ?>rel="<?php echo esc_attr($download['rel']); ?>"<?php endif; ?>
                               <?php if ($downloadUrl !== '' && $downloadUrl !== '#') : ?>download<?php endif; ?>
                               class="inline-flex min-h-[58px] items-center gap-2 rounded-[10px] border border-cadco-blue bg-white px-6 py-4 font-display text-[16px] font-bold leading-none text-cadco-blue no-underline transition-colors hover:bg-cadco-blue/5">
                                <?php echo esc_html($downloadText); ?>
                                <svg class="shrink-0" width="16" height="16" style="margin-left:6px" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                                    <path d="M10 3v9.5M6 9l4 4 4-4M4 16.5h12" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <?php /* Optional certification mark under the buttons, 60px
                             below them and 67px tall, as the service frame
                             sets the CFESA badge. */ ?>
                    <?php if (! empty($badge['url']) || $is_preview) : ?>
                        <span data-proto-field="badge" class="block" style="margin-top:60px">
                            <?php if (! empty($badge['url'])) : ?>
                                <?php echo ! empty($badge['id'])
                                    ? wp_get_attachment_image((int) $badge['id'], 'medium', false, [
                                        'alt'     => (string) ($badge['alt'] ?? ''),
                                        'class'   => 'block w-auto',
                                        'style'   => 'height:67px;max-width:100%',
                                        'loading' => 'lazy',
                                    ])
                                    : '<img src="' . esc_url($badge['url']) . '" alt="' . esc_attr($badge['alt'] ?? '') . '" class="block w-auto" style="height:67px;max-width:100%" loading="lazy" />'; ?>
                            <?php endif; ?>
                        </span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
