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
    /*
 * Height, for a section that gets pinned.
 *
 * A fixed height taller than the viewport cannot work here: the pin centres
 * the section, so the overflow is split top and bottom and eats the padding,
 * which is what pushed the heading against the top of the screen on a laptop
 * and on mobile. Instead the section fills the viewport but never grows past
 * the height the frame draws, and its content is centred inside whatever that
 * comes to. Padding scales with the viewport so short screens give their room
 * to the content rather than to margins.
 */
'class' => 'cadco-timeline relative flex w-full flex-col justify-center '
    . ($is_preview ? 'overflow-x-auto' : 'min-h-[min(100svh,982px)] overflow-hidden')
    . ' bg-gradient-to-b from-paper via-paper to-[#f2f5f9]'
    . ' pt-[clamp(28px,5vh,120px)] pb-[clamp(28px,5vh,122px)]',
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
            class="m-0 mt-6 max-w-[600px] font-display text-[26px] font-bold leading-[1.2] text-true-black md:text-[36px]">
            <?php echo wp_kses_post($heading); ?>
        </h2>
    </div>

    <?php if ($is_preview) : ?>
        <?php
        /*
         * The editor gets a plain card grid, not the rail.
         *
         * The rail is a horizontally scrolling strip that the front end drives
         * with a pinned ScrollTrigger. There is no pin in the editor, so the
         * same markup there is a strip the author has to scroll sideways
         * through to reach later milestones, with the repeater's own controls
         * layered on top of absolutely placed cards. Content entry does not
         * need the rail; it needs every milestone visible and in order.
         */
        ?>
        <div data-proto-repeater="milestones"
             class="mx-auto mt-10 grid w-full max-w-[1140px] grid-cols-1 gap-6 px-6 md:grid-cols-2">

            <?php if (empty($milestones)) : ?>
                <p class="text-body-sm text-gray-500">
                    <?php esc_html_e('Add a milestone in the block sidebar.', 'cadco-theme'); ?>
                </p>
            <?php endif; ?>

            <?php foreach ((array) $milestones as $item) : ?>
                <?php $img = $item['image'] ?? []; ?>
                <div data-proto-repeater-item
                     class="flex gap-5 rounded-[10px] border border-light-grey/60 bg-white p-4">
                    <div data-proto-field="image" class="h-[96px] w-[150px] shrink-0 overflow-hidden rounded-[6px] bg-light-grey/40">
                        <?php if (! empty($img['url'])) : ?>
                            <img src="<?php echo esc_url($img['url']); ?>"
                                 alt="<?php echo esc_attr($img['alt'] ?? ''); ?>"
                                 class="h-full w-full object-cover" />
                        <?php endif; ?>
                    </div>
                    <div class="min-w-0"><?php $copy($item); ?></div>
                </div>
            <?php endforeach; ?>
        </div>

    <?php else : ?>
    <?php // ---------- Rail ---------- ?>
    <div class="relative mt-[11px] w-full" data-timeline-viewport>
        <?php /* In the editor the rail is scrolled by hand rather than by the
                 pin, so it starts at the block's own left edge instead of being
                 inset to the front end's container. */ ?>
        <div <?php /* No trailing padding: the track's travel has to equal the rail's
                 growth, or the line's right end drifts inward as it moves. The
                 last column carries its own slack, so nothing is cramped. */ ?>
             class="relative z-10 flex w-max items-stretch will-change-transform <?php echo $is_preview ? 'pl-0' : 'pl-[max(24px,calc((100vw-1140px)/2+24px))]'; ?>"
             data-timeline-track>

            <?php if (empty($milestones)) : ?>
                <p class="py-16 text-body-sm text-gray-500">
                    <?php esc_html_e('Add a milestone in the block sidebar.', 'cadco-theme'); ?>
                </p>
            <?php endif; ?>

            <?php
            /*
             * One DOM element per milestone, not per pair. The pairing is done
             * by grid placement instead: a milestone is assigned a column
             * (its pair) and a row (below the rail if it is the earlier of the
             * two, above if the later). Wrapping two milestones in one element
             * would have been simpler to write, but then a repeater item would
             * hold two records and the editor would bind eight milestones to
             * four items.
             */
            $cols = (int) ceil(count((array) $milestones) / 2);
            ?>
            <div data-proto-repeater="milestones"
                 class="grid grid-flow-col grid-rows-[232px_72px_232px]"
                 style="grid-auto-columns:752px">

                <?php // The rail: one line across every column, in the middle row. ?>
                <?php /* "1 / -1" spans only the EXPLICIT grid, and these columns
                         are implicit (grid-auto-flow), so it covered a single
                         column and the line stopped at the second dot. The span
                         is stated explicitly instead. It also grows on scroll,
                         so scaleX starts partial and transform-origin is left. */ ?>
                <span data-timeline-rail
                      class="relative z-0 h-px origin-left self-center"
                      style="grid-row:2;grid-column:1/span <?php echo (int) max(1, $cols); ?>;background:<?php echo esc_attr($railColor); ?>"
                      aria-hidden="true"></span>

                <?php for ($c = 1; $c <= $cols; $c++) : ?>
                    <span class="relative z-10 h-[14px] w-[14px] self-center justify-self-start rounded-full"
                          style="grid-row:2;grid-column:<?php echo (int) $c; ?>;background:<?php echo esc_attr($dotColor); ?>"
                          aria-hidden="true"></span>
                <?php endfor; ?>

                <?php foreach ((array) $milestones as $i => $item) : ?>
                    <?php
                    $above = ($i % 2) === 1;              // the later of the pair sits above
                    $col   = (int) floor($i / 2) + 1;
                    $img   = $item['image'] ?? [];
                    ?>
                    <div data-proto-repeater-item
                         data-timeline-item
                         class="flex <?php echo $above ? 'items-end' : 'items-start'; ?> <?php echo $above ? 'gap-[58px]' : ''; ?>"
                         style="grid-row:<?php echo $above ? 1 : 3; ?>;grid-column:<?php echo $col; ?>">

                        <?php if ($above) : ?>
                            <div data-proto-field="image" class="h-[163px] w-[353px] shrink-0 overflow-hidden rounded-[10px] bg-light-grey/40">
                                <?php if (! empty($img['url'])) : ?>
                                    <img src="<?php echo esc_url($img['url']); ?>"
                                         alt="<?php echo esc_attr($img['alt'] ?? ''); ?>"
                                         class="h-full w-full object-cover" loading="lazy" decoding="async" />
                                <?php endif; ?>
                            </div>
                            <div class="w-[300px] shrink-0 pb-1"><?php $copy($item); ?></div>
                        <?php else : ?>
                            <div class="w-[353px] shrink-0 pt-1"><?php $copy($item); ?></div>
                            <div data-proto-field="image" class="h-[163px] w-[353px] shrink-0 overflow-hidden rounded-[10px] bg-light-grey/40">
                                <?php if (! empty($img['url'])) : ?>
                                    <img src="<?php echo esc_url($img['url']); ?>"
                                         alt="<?php echo esc_attr($img['alt'] ?? ''); ?>"
                                         class="h-full w-full object-cover" loading="lazy" decoding="async" />
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <?php endif; ?>

    <?php // ---------- Controls: they drive the rail, so front end only ---------- ?>
    <?php if (! $is_preview) : ?>
    <div class="relative mx-auto mt-[10px] flex w-full max-w-[1140px] gap-3 px-6">
        <button type="button" data-timeline-prev
                class="flex h-[48px] w-[48px] items-center justify-center rounded-full border border-cadco-blue/40 text-cadco-blue transition-colors hover:border-cadco-blue disabled:opacity-60"
                aria-label="<?php esc_attr_e('Previous milestone', 'cadco-theme'); ?>">
            <svg viewBox="0 0 28 24" class="h-6 w-6" fill="none" aria-hidden="true">
                <path d="M25 12H3M3 12l7-7M3 12l7 7" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </button>
        <button type="button" data-timeline-next
                class="flex h-[48px] w-[48px] items-center justify-center rounded-full bg-cadco-blue text-white transition-colors hover:bg-[#00395a] disabled:opacity-40"
                aria-label="<?php esc_attr_e('Next milestone', 'cadco-theme'); ?>">
            <svg viewBox="0 0 28 24" class="h-6 w-6" fill="none" aria-hidden="true">
                <path d="M3 12h22M25 12l-7-7M25 12l-7 7" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </button>
    </div>
    <?php endif; ?>
</section>
