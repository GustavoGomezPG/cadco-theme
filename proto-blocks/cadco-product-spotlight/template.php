<?php
/**
 * Cadco Product Spotlight.
 *
 * One product, full width, in one of two dresses:
 *
 *   images  copy on white at the left, a tall product photograph at the right
 *           with a smaller one inset over its lower-left corner (the Bakerlux
 *           section of the Products page).
 *   photo   a photograph anchored to the left edge with a scrim pulled across
 *           it, and the copy set over the dark right-hand side (VariKwik).
 *
 * The two share one content model: eyebrow, heading, body, button. Only the
 * dressing differs, so a page can switch treatment without re-authoring copy.
 *
 * Measurements come from the Figma frame (1440 wide): the copy column is 511px,
 * the main photograph 494x488, the inset 228.5 square inside a 14px white
 * border, hanging 119px below the main one.
 *
 * @var array         $attributes
 * @var WP_Block|null $block  Null in the editor preview.
 */

$eyebrow = (string) ($attributes['eyebrow'] ?? '');
$heading = (string) ($attributes['heading'] ?? '');
$body    = (string) ($attributes['body'] ?? '');
$cta     = $attributes['cta'] ?? [];

$layout  = ($attributes['layout'] ?? 'images') === 'photo' ? 'photo' : 'images';
$isPhoto = 'photo' === $layout;

$imgPrimary   = $attributes['imagePrimary'] ?? [];
$imgSecondary = $attributes['imageSecondary'] ?? [];
$background   = $attributes['backgroundImage'] ?? [];

$scrim     = max(0, min(100, (int) ($attributes['scrimOpacity'] ?? 55)));
$minHeight = max(320, min(1000, (int) ($attributes['minHeight'] ?? 620)));

// $block is null in the editor preview.
$is_preview = ! isset($block) || $block === null;

$ctaUrl  = isset($cta['url']) ? (string) $cta['url'] : '';
$ctaText = isset($cta['text']) ? (string) $cta['text'] : '';

/**
 * Scroll reveal, front end only — the same contract the other Cadco blocks use
 * (assets/js/cadco-reveal.js). Omitted in the canvas so the editor never hides
 * what an author is editing.
 */
$reveal = $is_preview ? '' : 'data-proto-animate="manual" data-cadco-reveal-group';

/*
 * The photo layout does not run the photograph full bleed: in the design it is
 * 1159 of the 1440 frame, anchored left, and the rest of the band is the scrim
 * colour. The gradient then takes it the rest of the way to solid so the copy
 * on the right keeps its contrast.
 */
$photoWidth  = '80.5%';
$photoOffset = '-7.3%';
$scrimStyle  = sprintf(
    'background:linear-gradient(90deg, rgba(0,0,0,%1$s) 0%%, rgba(0,0,0,%2$s) 34%%, rgba(0,0,0,%3$s) 54%%, rgba(0,0,0,0.98) 62%%, rgba(0,0,0,1) 68%%, rgba(0,0,0,1) 100%%)',
    number_format($scrim / 260, 3, '.', ''),
    number_format($scrim / 170, 3, '.', ''),
    number_format(min(0.92, $scrim / 100 + 0.34), 3, '.', '')
);

// Type scales differ between the dresses: the white layout carries a display
// heading, the photo layout a smaller one beside the photograph's subject.
/*
 * Two scales share one layout. "display" is the product spotlight the block was
 * built for; "section" is supporting content such as the About page's
 * representation panel, where the same arrangement carries a modest heading
 * rather than a display one. Every class is literal so Tailwind's scanner
 * sees it.
 */
$isSection = ($attributes['scale'] ?? 'display') === 'section';

$frameAspect = ($attributes['imageAspect'] ?? 'tall') === 'wide'
    ? 'aspect-[465/305]'
    : 'aspect-[494/488]';

$sectionPad   = $isSection ? 'pt-[140px] pb-[70px]' : 'pt-12 pb-[130px]';
$mediaWidth   = $isSection ? 'lg:w-[466px]' : 'lg:w-[494px]';
$mediaMax     = $isSection ? 'max-w-[466px]' : 'max-w-[494px]';
$rowAlign     = 'lg:items-start';
$copyWidth    = $isSection ? 'lg:w-[540px]' : 'lg:w-[511px]';
$bodyTop      = $isSection ? 'mt-7 max-w-[490px]' : 'mt-8';

$headingType = $isPhoto
    ? 'text-[30px] leading-[1.2] md:text-[40px]'
    : ($isSection ? 'text-[26px] leading-[1.25] md:text-[34px]'   /* 38px matches the frame's size but wraps to three lines in this column, which costs more height than the size gains */ : 'text-[40px] leading-[1.21] md:text-[64px]');
$eyebrowGap  = $isPhoto ? 'mt-[54px]' : 'mt-[26px]';

$wrapper = get_block_wrapper_attributes([
    'class' => 'cadco-product-spotlight relative isolate w-full '
        . ($isPhoto ? 'overflow-hidden bg-true-black' : 'bg-paper ' . $sectionPad),
]);
?>
<section <?php echo $wrapper; ?> <?php echo $reveal; ?>
    <?php if ($isPhoto) : ?>style="min-height:<?php echo (int) $minHeight; ?>px"<?php endif; ?>>

    <?php if ($isPhoto) : ?>
        <?php // ---------- Photograph, anchored left, then the scrim ---------- ?>
        <?php if (! empty($background['url'])) : ?>
            <img src="<?php echo esc_url($background['url']); ?>"
                 alt="<?php echo esc_attr($background['alt'] ?? ''); ?>"
                 class="absolute inset-y-0 -z-10 h-full object-cover object-[left_bottom]"
                 style="width:<?php echo esc_attr($photoWidth); ?>;left:<?php echo esc_attr($photoOffset); ?>"
                 loading="lazy" decoding="async" />
        <?php endif; ?>

        <div class="pointer-events-none absolute inset-0 -z-10"
             style="<?php echo esc_attr($scrimStyle); ?>" aria-hidden="true"></div>
    <?php endif; ?>

    <div class="relative mx-auto flex w-full max-w-[1140px] px-6
        <?php echo $isPhoto ? 'items-center justify-end lg:pr-[83px]' : 'flex-col gap-12 lg:flex-row lg:justify-between lg:gap-10 ' . $rowAlign; ?>"
        <?php if ($isPhoto) : ?>style="min-height:<?php echo (int) $minHeight; ?>px"<?php endif; ?>>

        <?php // ---------- Copy ---------- ?>
        <div class="<?php echo $isPhoto ? 'w-full max-w-[613px] pb-[26px]' : 'w-full lg:shrink-0 ' . $copyWidth; ?>">

            <?php // Always rendered, empty or not, so every region stays editable. ?>
            <p data-proto-field="eyebrow"
               data-cadco-reveal="rise"
               class="m-0 font-display text-[20px] font-bold leading-[1.2] <?php echo $isPhoto ? 'text-paper' : 'text-true-black'; ?>">
                <?php echo esc_html($eyebrow); ?>
            </p>

            <h2 data-proto-field="heading"
                data-cadco-reveal="lines"
                class="m-0 <?php echo esc_attr($eyebrowGap); ?> font-display <?php echo esc_attr($headingType); ?> font-bold <?php echo $isPhoto ? 'text-paper' : 'text-true-black'; ?>">
                <?php echo esc_html($heading); ?>
            </h2>

            <?php /* Paragraph and specification list are one authored region.
                     Tailwind's preflight strips list markers, so the bullets
                     come back in the block's own stylesheet, scoped to here. */ ?>
            <div data-proto-field="body"
                 data-cadco-reveal="rise"
                 class="cadco-spotlight-body <?php echo esc_attr($bodyTop); ?> font-display text-[16px] font-normal leading-[24px] <?php echo $isPhoto ? 'text-paper/90' : 'text-true-black'; ?>">
                <?php echo wp_kses_post($body); ?>
            </div>

            <?php /* Rendered when it has a destination or some label, and always
                     in the editor so it can be filled in. Unconditional, a
                     spotlight with no button -- the About page's representation
                     section is one -- would draw an empty blue rectangle. */ ?>
            <?php if ($ctaUrl !== '' || $ctaText !== '' || $is_preview) : ?>
            <div class="<?php echo $isPhoto ? 'mt-[60px]' : 'mt-[50px]'; ?>">
                <a data-proto-field="cta"
                   data-cadco-reveal="rise"
                   class="inline-flex h-[58px] min-w-[220px] items-center justify-center rounded-[10px] bg-cadco-blue px-6 font-display text-[16px] font-bold leading-none text-white no-underline transition-colors hover:bg-[#00395a]"
                   href="<?php echo esc_url($ctaUrl); ?>">
                    <?php echo esc_html($ctaText); ?>
                </a>
            </div>
            <?php endif; ?>
        </div>

        <?php // ---------- Photographs ---------- ?>
        <?php if (! $isPhoto) : ?>
            <div class="relative w-full lg:shrink-0 <?php echo esc_attr($mediaWidth); ?> <?php echo $isSection ? '' : 'lg:translate-x-[14px] lg:pb-[119px]'; ?>" data-cadco-reveal="items">

                <?php /* The inset hangs off the main photograph's lower-left
                         corner rather than sitting in flow, so the main frame
                         keeps its 494x488 proportions whatever the inset does. */ ?>
                <div class="relative ml-auto <?php echo esc_attr($frameAspect . ' ' . $mediaMax); ?> w-full overflow-hidden rounded-[15px] bg-light-grey/40">
                    <?php if (! empty($imgPrimary['url'])) : ?>
                        <img src="<?php echo esc_url($imgPrimary['url']); ?>"
                             alt="<?php echo esc_attr($imgPrimary['alt'] ?? ''); ?>"
                             class="absolute inset-0 h-full w-full object-cover object-[60%_center]"
                             loading="lazy" decoding="async" />
                    <?php endif; ?>
                </div>

                <?php if (! empty($imgSecondary['url'])) : ?>
                    <div class="absolute bottom-[-12px] left-[-80px] hidden aspect-square w-[257px] overflow-hidden rounded-[15px] border-[14px] border-paper bg-light-grey/40 shadow-[0_8px_18px_rgba(0,0,0,0.16)] lg:block">
                        <img src="<?php echo esc_url($imgSecondary['url']); ?>"
                             alt="<?php echo esc_attr($imgSecondary['alt'] ?? ''); ?>"
                             class="absolute inset-0 h-full w-full object-cover"
                             loading="lazy" decoding="async" />
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
