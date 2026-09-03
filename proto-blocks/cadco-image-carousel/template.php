<?php
/**
 * Cadco image carousel.
 *
 * Figma: a diagonal band of photographs rising left to right, the middle ones
 * sharp and full size, the outer ones smaller, blurred and nearly transparent.
 * Measurements taken from the design file (1440px frame):
 *
 *   card      ~443 x ~413, radius 15      step   ~456 (443 card + 13 gap)
 *   rise      ~54px higher per step       recede scale .655, blur 6.05px,
 *                                                opacity .15 by two steps out
 *
 * The design's own y values are hand-placed and slightly irregular (the outer
 * pair drop about 140px rather than 54). Rather than reproduce that drift, the
 * markup here lays every item on one continuous curve that passes through the
 * design's stated values at the centre and at one and two steps out -- so it
 * matches at the positions the design actually specifies, and stays coherent at
 * the fractional positions the design never had to draw because it does not
 * move.
 *
 * THE EDITOR AND THE PAGE RENDER THE SAME MARKUP. There is no preview branch
 * in this file, and that is deliberate: the images come from a `gallery`
 * CONTROL in the inspector rather than a repeater, so nothing has to inject
 * editing chrome between the items. The band's first frame is computed below
 * in PHP, so the canvas shows the real geometry -- the real diagonal, at the
 * real section height -- without depending on the canvas ever running the
 * view script. That is what makes the spacing controls worth having: padding
 * and margin are dialled against the thing that will actually ship.
 *
 * BUILT TO WORK WITHOUT JAVASCRIPT. Without it the band is simply still, and
 * `is-enhanced` is withheld so style.css falls back to a plain scrollable row
 * of real <img> elements with alt text, srcset and lazy loading.
 *
 * @var array         $attributes
 * @var WP_Block|null $block
 */

$travel    = max(1, min(8, (int) ($attributes['travel'] ?? 3)));
$tilt      = max(0, min(30, (int) ($attributes['tilt'] ?? 14)));
$smoothing = max(0, min(25, (int) ($attributes['smoothing'] ?? 10)));

/**
 * Vertical spacing, author-controlled.
 *
 * Padding and margin are separate on purpose: padding sets how much room the
 * band has inside its own section, margin sets how it sits against the
 * sections around it -- and margin is allowed to go negative, which is how one
 * section is pulled up to overlap the one above it.
 *
 * Written as inline styles rather than Tailwind classes because the values are
 * continuous: a class per pixel is not something the compiler can scan for.
 */
$padTop    = max(0, min(240, (int) ($attributes['paddingTop'] ?? 64)));
$padBottom = max(0, min(240, (int) ($attributes['paddingBottom'] ?? 96)));
$marTop    = max(-240, min(240, (int) ($attributes['marginTop'] ?? 0)));
$marBottom = max(-240, min(240, (int) ($attributes['marginBottom'] ?? 0)));
$bandH     = max(400, min(1000, (int) ($attributes['bandHeight'] ?? 840)));
$zIndex    = max(-2, min(10, (int) ($attributes['zIndex'] ?? 0)));

$spacing = sprintf(
    'padding-top:%dpx;padding-bottom:%dpx;margin-top:%dpx;margin-bottom:%dpx;z-index:%d',
    $padTop,
    $padBottom,
    $marTop,
    $marBottom,
    $zIndex
);

/**
 * The band's height, as a literal declaration rather than a custom property.
 *
 * It used to ride on the section as `--band-h`, which style.css read through
 * var(). That worked on the page and silently did nothing in the editor: the
 * canvas runs rendered block HTML through an inline-style sanitiser that keeps
 * ordinary declarations and DROPS custom properties -- so padding and margin
 * arrived, the band height did not, and the canvas quietly fell back to the
 * CSS default. A Band height control that moved nothing in the editor is
 * exactly the kind of thing this block is meant not to have.
 *
 * The clamp is reproduced here rather than left in the stylesheet because only
 * its upper bound is author-controlled and CSS gives no way to substitute one
 * term of a clamp without a custom property -- which is the thing that does
 * not survive. The floor keeps the band from collapsing on narrow screens,
 * where the rise scales down with the cards but never to nothing.
 */
$bandStyle = sprintf('height:clamp(560px, 58vw, %dpx)', $bandH);

// $block is null in the editor preview.
$is_preview = ! isset($block) || $block === null;

// Only entries with an actual image. The gallery control already drops
// anything without one, so this guards a hand-edited attribute rather than
// ordinary authoring -- an empty entry would render a hole in the band and,
// once enhanced, occupy a slot in the loop.
$images = [];

foreach ((array) ($attributes['images'] ?? []) as $img) {
    if (is_array($img) && (! empty($img['id']) || ! empty($img['url']))) {
        $images[] = $img;
    }
}

$count = count($images);

/**
 * The band's first frame, computed here rather than left to JavaScript.
 *
 * On a live page view.js overwrites these same properties every frame, so
 * nothing here constrains the animation -- it only decides what is on screen
 * before, and instead of, the first frame. In the editor it is the whole
 * picture, which is the point.
 *
 * The constants mirror view.js exactly; changing one means changing both.
 *
 * @return array{transform:string,opacity:string,filter:string,visibility:string}
 */
if (! function_exists('cadco_carousel_static_frame')) {
    function cadco_carousel_static_frame(int $index, int $count, int $tilt): array
    {
        $half = $count / 2;

        // Signed distance from centre, wrapped -- view.js's wrapSigned().
        $span = $half * 2;
        $d    = fmod($index + $half, $span);

        if ($d < 0) {
            $d += $span;
        }

        $d -= $half;

        $ad = abs($d);

        if ($ad > 2.6) {                       // VISIBLE_SPAN
            return [
                'transform'  => 'none',
                'opacity'    => '0',
                'filter'     => 'none',
                'visibility' => 'hidden',
            ];
        }

        $t    = max(0.0, min(1.0, $ad - 1));   // recession ramp
        $lerp = static fn(float $a, float $b): float => $a + ($b - $a) * $t;

        $turn = max(-2, min(2, $d));           // TILT_SPAN

        return [
            'transform' => sprintf(
                'translate3d(%.2fpx, %.2fpx, 0) rotateY(%.2fdeg) scale(%.4f)',
                $d * 456 * $lerp(1, 0.82),     // step 443 + 13 gap; FAR_PULL_IN
                -$d * 54 * $lerp(1, 1.6),      // RISE; FAR_RISE_GAIN
                -$turn * $tilt,
                $lerp(1, 0.655)                // FAR_SCALE
            ),
            'opacity'    => sprintf('%.3f', $lerp(1, 0.15)),
            'filter'     => $t > 0 ? sprintf('blur(%.1fpx)', $lerp(0, 6.05)) : 'none',
            'visibility' => 'visible',
        ];
    }
}

/**
 * The editor is put into the band state HERE rather than by view.js.
 *
 * On a live page `is-enhanced` is a promise the script keeps: it appears only
 * once JS has actually placed the cards, so a visitor without JS is never left
 * looking at an absolute layout nothing is driving. The editor needs the
 * opposite guarantee -- the band has to be there whether or not the canvas
 * ever runs the script, because that is the geometry the author is dialling
 * spacing against. PHP is the only thing that can promise that, since it
 * renders both sides and knows which one it is on.
 *
 * `is-editor` additionally exempts the canvas from the reduced-motion
 * fallback: a still diagonal is not motion, and an author who prefers reduced
 * motion still has to be able to see what they are laying out.
 */
$editorState = $is_preview ? ' is-enhanced is-editor' : '';

$wrapper = get_block_wrapper_attributes([
    'class' => 'cadco-image-carousel relative w-full overflow-hidden' . $editorState,
]);

/**
 * Scroll reveal, front end only. See assets/js/cadco-reveal.js.
 *
 * The band gets `fade` rather than `rise`: view.js rewrites every item's
 * transform on each frame of the scrub, and the track itself carries the
 * perspective the 3D depends on -- a second writer putting a translate on
 * either would be fighting for the same property. Opacity is the one channel
 * nothing else here touches.
 */
$reveal = $is_preview ? '' : 'data-proto-animate="manual" data-cadco-reveal-group';
?>
<section <?php echo $wrapper; ?> <?php echo $reveal; ?> style="<?php echo esc_attr($spacing); ?>">

    <?php if ($count > 0) : ?>
        <?php /* NO data-lenis-prevent, for the same reason as the featured
                 products carousel: Lenis's prevent applies to both axes, so it
                 turns every vertical wheel over the element into a native
                 scroll that leaves Lenis's own position stale. Horizontal
                 gestures reach the fallback row regardless, because Lenis only
                 acts on vertical ones. */ ?>
        <ul class="cadco-image-carousel__track flex list-none items-center gap-[13px] overflow-x-auto p-0"
            data-cadco-reveal="fade"
            style="<?php echo esc_attr($bandStyle); ?>"
            data-carousel-track
            <?php /* Only a live page scrubs. The canvas gets the same band,
                     frozen at the first frame and still clickable -- a
                     carousel that animates while you are editing it is no
                     easier to work with than one that does not move. */ ?>
            <?php echo $is_preview ? '' : 'data-carousel-live'; ?>
            data-carousel-travel="<?php echo (int) $travel; ?>"
            data-carousel-tilt="<?php echo (int) $tilt; ?>"
            data-carousel-smoothing="<?php echo (int) $smoothing; ?>"
            tabindex="0"
            role="group"
            aria-label="<?php esc_attr_e('Photographs of Cadco equipment in use', 'cadco-theme'); ?>">

            <?php foreach ($images as $i => $img) : ?>
                <?php $frame = cadco_carousel_static_frame((int) $i, $count, $tilt); ?>
                <li class="cadco-image-carousel__item m-0 shrink-0"
                    data-carousel-item
                    data-index="<?php echo (int) $i; ?>"
                    style="transform:<?php echo esc_attr($frame['transform']); ?>;opacity:<?php echo esc_attr($frame['opacity']); ?>;filter:<?php echo esc_attr($frame['filter']); ?>;visibility:<?php echo esc_attr($frame['visibility']); ?>">

                    <figure class="m-0 h-full w-full">
                        <?php
                        $id = (int) ($img['id'] ?? 0);

                        if ($id > 0) {
                            // From the attachment id so WordPress emits srcset --
                            // these are ~443px on screen but full-bleed source
                            // photographs, and serving a phone the desktop file
                            // is the most expensive mistake available here.
                            echo wp_get_attachment_image($id, 'large', false, [
                                'class'    => 'block h-full w-full rounded-[15px] object-cover',
                                'alt'      => esc_attr($img['alt'] ?? ''),
                                'loading'  => 'lazy',
                                'decoding' => 'async',
                            ]);
                        } else {
                            ?>
                            <img class="block h-full w-full rounded-[15px] object-cover"
                                 src="<?php echo esc_url($img['url']); ?>"
                                 alt="<?php echo esc_attr($img['alt'] ?? ''); ?>"
                                 loading="lazy" decoding="async" />
                            <?php
                        }
                        ?>
                    </figure>
                </li>
            <?php endforeach; ?>
        </ul>

    <?php elseif ($is_preview) : ?>
        <div class="mx-auto max-w-[640px] rounded-[15px] border border-dashed border-black/25 px-6 py-14 text-center">
            <span class="font-display text-[15px] font-bold text-black/55">
                <?php esc_html_e('Choose images in the block sidebar to build the band.', 'cadco-theme'); ?>
            </span>
        </div>
    <?php endif; ?>
</section>
