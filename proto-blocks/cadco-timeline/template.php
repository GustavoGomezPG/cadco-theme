<?php
/**
 * Cadco Timeline.
 *
 * A horizontal history rail. Milestones alternate above and below a continuous
 * line, each marked by a dot, and the rail travels sideways as the section
 * passes through the viewport — the technique the Helio reference uses, with
 * GSAP scrubbing a translateX on the track. The arrows drive the same position,
 * so the two inputs cannot fight each other.
 *
 * Layout, from the frame: an above-rail milestone sets its image left and its
 * copy right; a below-rail one mirrors that. Images are 353x163, copy columns
 * about 283px, and the rail carries a 15px dot at each milestone.
 *
 * Milestones are laid out in pairs. Each slide is one rail segment with a
 * single dot and holds two milestones: the earlier one below the rail, the
 * later one above it. That is what the frame draws, and it is what makes the
 * rail read as continuous -- alternating one milestone per slide instead
 * leaves the opposite row of every slide empty, which both diverges from the
 * frame and strands a band of dead space the full width of the track.
 *
 *
 * @var array         $attributes
 * @var WP_Block|null $block  Null in the editor preview.
 */

$eyebrow    = (string) ($attributes['eyebrow'] ?? '');
$heading    = (string) ($attributes['heading'] ?? '');
$milestones = $attributes['milestones'] ?? [];

$scrub = (bool) ($attributes['scrub'] ?? true);

// $block is null in the editor preview.
$is_preview = ! isset($block) || $block === null;

/**
 * Colour controls land in a style attribute, so they are checked against the
 * shapes the control can produce rather than merely escaped.
 */
$colour = static function (string $value, string $fallback): string {
    return preg_match('/^(#[0-9a-f]{3,8}|(rgb|hsl)a?\([0-9a-z%.,\/\s]+\))$/i', trim($value))
        ? trim($value)
        : $fallback;
};

$railColor = $colour((string) ($attributes['railColor'] ?? ''), 'rgba(0,71,110,0.25)');
$dotColor  = $colour((string) ($attributes['dotColor'] ?? ''), '#00476e');

/**
 * One milestone's copy, as a closure rather than a partial in parts/.
 *
 * The plugin's Tailwind scanner (includes/Tailwind/Scanner.php) reads only
 * <block>/template.php and <block>/<block-name>.php. It does not walk
 * subdirectories, so classes written in a parts/ include are never compiled and
 * the element silently falls back to the browser's own size. A closure keeps
 * the markup in one place AND keeps every class where the scanner can see it.
 *
 * A closure, not a named function: two instances of the block on one page would
 * redeclare a named one.
 */
$copy = static function (array $item): void {
    ?>
    <p data-proto-field="year"
       class="m-0 font-display text-[26px] font-bold leading-[1.2] text-cadco-blue">
        <?php echo esc_html((string) ($item['year'] ?? '')); ?>
    </p>

    <h3 data-proto-field="title"
        class="m-0 mt-2 font-display text-[24px] font-bold leading-[1.25] text-true-black">
        <?php echo esc_html((string) ($item['title'] ?? '')); ?>
    </h3>

    <p data-proto-field="body"
       class="m-0 mt-3 font-display text-[16px] font-normal leading-6 text-true-black">
        <?php echo esc_html((string) ($item['body'] ?? '')); ?>
    </p>
    <?php
};

/**
 * Scroll reveal, front end only — the contract the other Cadco blocks use. The
 * track's own motion is handled by view.js, not by the reveal.
 */
$reveal = $is_preview ? '' : 'data-proto-animate="manual" data-cadco-reveal-group';

$wrapper = get_block_wrapper_attributes([
    'class' => 'cadco-timeline relative w-full overflow-hidden bg-gradient-to-b from-paper via-paper to-[#f2f5f9] pt-[68px] pb-[124px]',
]);
?>
<section <?php echo $wrapper; ?> <?php echo $reveal; ?>
    data-cadco-timeline
    data-scrub="<?php echo $scrub ? '1' : '0'; ?>">

    <?php // ---------- Heading ---------- ?>
    <div class="relative mx-auto w-full max-w-[1140px] px-6">
        <p class="m-0 flex items-center gap-2 font-display text-[20px] font-bold leading-[1.2] text-true-black">
            <?php /* The flag the frame draws beside the eyebrow. Inline so it
                     inherits the text colour and needs no extra asset. */ ?>
            <svg class="h-[22px] w-[19px] shrink-0" viewBox="0 0 15 18" fill="none" aria-hidden="true">
                <path d="M1 1v16" stroke="<?php echo esc_attr($dotColor); ?>" stroke-width="2" stroke-linecap="round" />
                <path d="M2 2h11l-3 4 3 4H2V2z" fill="<?php echo esc_attr($dotColor); ?>" />
            </svg>
            <span data-proto-field="eyebrow" data-cadco-reveal="rise"><?php echo esc_html($eyebrow); ?></span>
        </p>

        <h2 data-proto-field="heading"
            data-cadco-reveal="lines"
            class="m-0 mt-6 max-w-[560px] font-display text-[26px] font-bold leading-[1.25] text-true-black md:text-[38px]">
            <?php echo wp_kses_post($heading); ?>
        </h2>
    </div>

    <?php // ---------- Rail ---------- ?>
    <div class="relative mt-8 w-full" data-timeline-viewport>
        <div class="relative z-10 flex w-max items-stretch pl-[calc((100vw-1140px)/2+24px)] pr-24 will-change-transform"
             data-timeline-track>

            <?php if (empty($milestones)) : ?>
                <p class="py-16 text-body-sm text-gray-500">
                    <?php esc_html_e('Add a milestone in the block sidebar.', 'cadco-theme'); ?>
                </p>
            <?php endif; ?>

            <div data-proto-repeater="milestones" class="flex items-stretch">
                <?php
                /*
                 * Two milestones per slide: the frame fills both rows of every
                 * rail segment. array_chunk leaves a trailing odd milestone in
                 * a pair of one, which renders below the rail with the row
                 * above it empty -- the only case where a half-empty slide is
                 * correct.
                 */
                foreach (array_chunk((array) $milestones, 2) as $pair) :
                    $below = $pair[0] ?? null;   // earlier milestone, below the rail
                    $above = $pair[1] ?? null;   // later milestone, above it
                    ?>
                    <div data-proto-repeater-item
                         data-timeline-item
                         class="relative flex w-[752px] shrink-0 flex-col">

                        <?php // ----- Above the rail: image left, copy right ----- ?>
                        <div class="flex h-[232px] items-end gap-[58px]">
                            <?php if ($above) : ?>
                                <?php $img = $above['image'] ?? []; ?>
                                <div class="h-[163px] w-[353px] shrink-0 overflow-hidden rounded-[10px] bg-light-grey/40">
                                    <?php if (! empty($img['url'])) : ?>
                                        <img src="<?php echo esc_url($img['url']); ?>"
                                             alt="<?php echo esc_attr($img['alt'] ?? ''); ?>"
                                             class="h-full w-full object-cover" loading="lazy" decoding="async" />
                                    <?php endif; ?>
                                </div>
                                <div class="w-[300px] shrink-0 pb-1">
                                    <?php $copy($above); ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php // ----- The rail ----- ?>
                        <div class="relative z-10 my-[29px] h-[14px] w-full">
                            <?php /* One segment per slide. Together they read as a
                                     single line that starts at the first dot and ends
                                     with the last milestone, rather than bleeding off
                                     the left edge as a viewport-wide rule would. */ ?>
                            <span class="absolute left-0 top-1/2 h-px w-full -translate-y-1/2"
                                  style="background:<?php echo esc_attr($railColor); ?>" aria-hidden="true"></span>
                            <span class="absolute left-0 top-0 h-[14px] w-[14px] rounded-full"
                                  style="background:<?php echo esc_attr($dotColor); ?>" aria-hidden="true"></span>
                        </div>

                        <?php // ----- Below the rail: copy left, image right ----- ?>
                        <div class="flex h-[232px] items-start">
                            <?php if ($below) : ?>
                                <?php $img = $below['image'] ?? []; ?>
                                <div class="w-[353px] shrink-0 pt-1">
                                    <?php $copy($below); ?>
                                </div>
                                <div class="h-[163px] w-[353px] shrink-0 overflow-hidden rounded-[10px] bg-light-grey/40">
                                    <?php if (! empty($img['url'])) : ?>
                                        <img src="<?php echo esc_url($img['url']); ?>"
                                             alt="<?php echo esc_attr($img['alt'] ?? ''); ?>"
                                             class="h-full w-full object-cover" loading="lazy" decoding="async" />
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <?php // ---------- Controls ---------- ?>
    <div class="relative mx-auto mt-[30px] flex w-full max-w-[1140px] gap-3 px-6">
        <button type="button" data-timeline-prev
                class="flex h-[48px] w-[48px] items-center justify-center rounded-full border border-cadco-blue/40 text-cadco-blue transition-colors hover:border-cadco-blue disabled:opacity-60"
                aria-label="<?php esc_attr_e('Previous milestone', 'cadco-theme'); ?>">
            <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" aria-hidden="true">
                <path d="M19 12H5M5 12l6-6M5 12l6 6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </button>
        <button type="button" data-timeline-next
                class="flex h-[48px] w-[48px] items-center justify-center rounded-full bg-[#4a6a8a] text-white transition-colors hover:bg-[#3c5873] disabled:opacity-40"
                aria-label="<?php esc_attr_e('Next milestone', 'cadco-theme'); ?>">
            <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" aria-hidden="true">
                <path d="M5 12h14M19 12l-6-6M19 12l-6 6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </button>
    </div>
</section>
