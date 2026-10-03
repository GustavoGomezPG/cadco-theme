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
 * One deliberate difference from the frame: it draws an above item and a below
 * item overlapping in the same horizontal span, sharing a single dot. Its
 * content is the same two milestones duplicated, so that reads as placeholder
 * density rather than intent, and a dot that marks two different years would be
 * hard to explain. Milestones here alternate without overlapping, one dot each.
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
 * Scroll reveal, front end only — the contract the other Cadco blocks use. The
 * track's own motion is handled by view.js, not by the reveal.
 */
$reveal = $is_preview ? '' : 'data-proto-animate="manual" data-cadco-reveal-group';

$wrapper = get_block_wrapper_attributes([
    'class' => 'cadco-timeline relative w-full overflow-hidden bg-gradient-to-b from-paper via-paper to-[#f2f5f9] pt-[92px] pb-[70px]',
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
            <svg class="h-[18px] w-[15px] shrink-0" viewBox="0 0 15 18" fill="none" aria-hidden="true">
                <path d="M1 1v16" stroke="<?php echo esc_attr($dotColor); ?>" stroke-width="2" stroke-linecap="round" />
                <path d="M2 2h11l-3 4 3 4H2V2z" fill="<?php echo esc_attr($dotColor); ?>" />
            </svg>
            <span data-proto-field="eyebrow" data-cadco-reveal="rise"><?php echo esc_html($eyebrow); ?></span>
        </p>

        <h2 data-proto-field="heading"
            data-cadco-reveal="lines"
            class="m-0 mt-4 max-w-[560px] font-display text-[26px] font-bold leading-[1.25] text-true-black md:text-[34px]">
            <?php echo wp_kses_post($heading); ?>
        </h2>
    </div>

    <?php // ---------- Rail ---------- ?>
    <div class="relative mt-14 w-full" data-timeline-viewport>
        <?php /* Padded to the heading's column so the first milestone starts on
                 the same line as the copy above it, then free to run past the
                 right edge — that overflow is the point. */ ?>
        <div class="flex w-max items-stretch pl-[calc((100vw-1140px)/2+24px)] pr-24 will-change-transform"
             data-timeline-track>

            <?php if (empty($milestones)) : ?>
                <p class="py-16 text-body-sm text-gray-500">
                    <?php esc_html_e('Add a milestone in the block sidebar.', 'cadco-theme'); ?>
                </p>
            <?php endif; ?>

            <div data-proto-repeater="milestones" class="flex items-stretch">
                <?php foreach ((array) $milestones as $i => $item) : ?>
                    <?php
                    $above = ($i % 2) === 0;
                    $img   = $item['image'] ?? [];
                    ?>
                    <div data-proto-repeater-item
                         data-timeline-item
                         class="relative flex w-[706px] shrink-0 flex-col">

                        <?php // ----- Above the rail ----- ?>
                        <div class="flex h-[232px] items-end gap-[58px] <?php echo $above ? '' : 'invisible'; ?>">
                            <?php if ($above) : ?>
                                <div class="h-[163px] w-[353px] shrink-0 overflow-hidden rounded-[10px] bg-light-grey/40">
                                    <?php if (! empty($img['url'])) : ?>
                                        <img src="<?php echo esc_url($img['url']); ?>"
                                             alt="<?php echo esc_attr($img['alt'] ?? ''); ?>"
                                             class="h-full w-full object-cover" loading="lazy" decoding="async" />
                                    <?php endif; ?>
                                </div>
                                <div class="w-[283px] shrink-0 pb-1">
                                    <?php include __DIR__ . '/parts/copy.php'; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php // ----- The rail itself ----- ?>
                        <div class="relative my-[29px] h-[15px] w-full">
                            <span class="absolute left-0 top-1/2 h-px w-full -translate-y-1/2"
                                  style="background:<?php echo esc_attr($railColor); ?>" aria-hidden="true"></span>
                            <span class="absolute left-0 top-0 h-[15px] w-[15px] rounded-full"
                                  style="background:<?php echo esc_attr($dotColor); ?>" aria-hidden="true"></span>
                        </div>

                        <?php // ----- Below the rail ----- ?>
                        <div class="flex h-[232px] items-start gap-[0px] <?php echo $above ? 'invisible' : ''; ?>">
                            <?php if (! $above) : ?>
                                <div class="w-[353px] shrink-0 pt-1">
                                    <?php include __DIR__ . '/parts/copy.php'; ?>
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
    <div class="relative mx-auto mt-[75px] flex w-full max-w-[1140px] gap-3 px-6">
        <button type="button" data-timeline-prev
                class="flex h-[48px] w-[48px] items-center justify-center rounded-full border border-cadco-blue/40 text-cadco-blue transition-colors hover:border-cadco-blue disabled:opacity-40"
                aria-label="<?php esc_attr_e('Previous milestone', 'cadco-theme'); ?>">
            <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" aria-hidden="true">
                <path d="M19 12H5M5 12l6-6M5 12l6 6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </button>
        <button type="button" data-timeline-next
                class="flex h-[48px] w-[48px] items-center justify-center rounded-full bg-cadco-blue text-white transition-colors hover:bg-[#00395a] disabled:opacity-40"
                aria-label="<?php esc_attr_e('Next milestone', 'cadco-theme'); ?>">
            <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" aria-hidden="true">
                <path d="M5 12h14M19 12l-6-6M19 12l-6 6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </button>
    </div>
</section>
