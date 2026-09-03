<?php
/**
 * Cadco intro.
 *
 * Figma: an eyebrow, a display heading, a paragraph and a button, all flush to
 * one left edge on white. Measurements taken from the design file (1440px
 * frame), where every element starts at x=191. The container is 1106px wide
 * rather than the 1058px the content itself measures, because the 24px gutter
 * that keeps text off the screen edge on a phone sits INSIDE it: centring 1106
 * in 1440 leaves 167px, and the gutter carries the text the remaining way to
 * 191. Sizing the container to the content instead would land every line 24px
 * too far right at desktop, which is very visible when four elements share one
 * flush-left edge:
 *
 *   eyebrow    Barlow ExtraBold 20, true black
 *   heading    Barlow Bold 64, capped at 913px so it wraps as drawn
 *   body       Barlow Regular 16/24, capped at 745px
 *   button     157x58, r10, #00476E
 *
 * The heading is a `full`-format text field, so an author can select a phrase
 * and colour it from the theme palette -- that is how the design's blue
 * "30 years" is reproduced, rather than by hard-coding the phrase here where
 * only a developer could change it.
 *
 * @var array         $attributes
 * @var WP_Block|null $block
 */

$eyebrow = $attributes['eyebrow'] ?? '';
$heading = $attributes['heading'] ?? '';
$body    = $attributes['body'] ?? '';
$cta     = $attributes['cta'] ?? [];
$align   = ($attributes['alignment'] ?? 'left') === 'center' ? 'center' : 'left';

$centred = $align === 'center';

/**
 * Every class pair written out in full rather than composed from fragments.
 *
 * The Tailwind compiler scans this file as text: a class it never sees spelled
 * out is a class it never generates, so `'text-' . $align` would compile to
 * nothing at all and the variant would silently do nothing.
 */
$alignText   = $centred ? 'text-center' : 'text-left';
$alignSelf   = $centred ? 'mx-auto' : '';
$ctaRow      = $centred ? 'flex justify-center' : '';

// The design gives the centred statement a wider measure than the left one:
// 998px against 913px, so its three lines break where they are drawn.
$headingWide = $centred ? 'max-w-[998px]' : 'max-w-[913px]';

// $block is null in the editor preview.
$is_preview = ! isset($block) || $block === null;

$wrapper = get_block_wrapper_attributes([
    'class' => 'cadco-intro w-full bg-paper py-20 md:py-[120px]',
]);

/**
 * Scroll reveal, front end only.
 *
 * `manual` hands the section to Proto-Blocks' reveal runtime as one this block
 * animates itself; assets/js/cadco-reveal.js reads the `data-cadco-reveal`
 * markers below and builds the timeline. The runtime force-reveals the section
 * if that never happens, so the hidden start state cannot strand the copy.
 *
 * Omitted in the editor: the canvas must never hide what an author is editing,
 * and a section that animates while being written is worse than one that does
 * not move.
 */
$reveal = $is_preview ? '' : 'data-proto-animate="manual" data-cadco-reveal-group';
?>
<section <?php echo $wrapper; ?> <?php echo $reveal; ?>>
    <div class="mx-auto w-full max-w-[1106px] px-6">

        <?php // Always rendered, empty or not, so all four stay editable. ?>
        <p data-proto-field="eyebrow"
           data-cadco-reveal="rise"
           class="m-0 font-display text-[16px] font-extrabold leading-tight text-true-black md:text-[20px] <?php echo esc_attr($alignText); ?>">
            <?php echo esc_html($eyebrow); ?>
        </p>

        <?php /* wp_kses_post rather than esc_html: this field carries inline
                 markup on purpose -- the coloured span the design puts around
                 "30 years" is a <mark> or a styled <span> written by the
                 editor's own colour tool. esc_html would print those tags as
                 visible text. */ ?>
        <h2 data-proto-field="heading"
            data-cadco-reveal="lines"
            class="m-0 mt-6 font-display text-[36px] font-bold leading-[1.18] text-true-black md:mt-9 md:text-[64px] <?php echo esc_attr($headingWide . ' ' . $alignText . ' ' . $alignSelf); ?>">
            <?php echo wp_kses_post($heading); ?>
        </h2>

        <?php /* Rendered whenever there is prose, and always in the editor so
                 an author can add some to a block that has none yet. Skipped on
                 the front end when empty: the wrapper would otherwise
                 contribute its top margin and open a gap under the heading. */ ?>
        <?php if (trim(wp_strip_all_tags((string) $body)) !== '' || $is_preview) : ?>
            <div data-proto-field="body"
                 data-cadco-reveal="rise"
                 class="cadco-intro__body mt-8 max-w-[745px] font-display text-[16px] font-normal leading-6 text-true-black md:mt-12 <?php echo esc_attr($alignText . ' ' . $alignSelf); ?>">
                <?php echo wp_kses_post($body); ?>
            </div>
        <?php endif; ?>

        <?php if (! empty($cta['url']) || $is_preview) : ?>
            <div data-cadco-reveal="rise" class="mt-10 md:mt-12 <?php echo esc_attr($ctaRow); ?>">
                <a data-proto-field="cta"
                   class="inline-flex h-[58px] min-w-[157px] items-center justify-center rounded-[10px] bg-cadco-blue px-6 font-display text-[16px] font-bold leading-none text-white no-underline transition-colors hover:bg-[#00395a]"
                   href="<?php echo esc_url($cta['url'] ?? '#'); ?>"
                   <?php echo ! empty($cta['target']) ? 'target="' . esc_attr($cta['target']) . '" rel="noopener"' : ''; ?>
                ><?php echo esc_html($cta['text'] ?? 'Contact Cadco'); ?></a>
            </div>
        <?php endif; ?>
    </div>
</section>
