<?php
/**
 * Cadco hero.
 *
 * Figma: background photograph, a directional scrim, then eyebrow / h1 / button
 * stacked left. Measurements taken from the design file (1440px frame):
 *
 *   content inset   px-10 (see below)  scrim  linear-gradient(273deg,
 *   heading         Barlow 96/115         rgba(0,0,0,0) 1.51%,
 *   eyebrow         Barlow 20/24          rgba(0,0,0,.74) 43.25%)
 *   button          166x58, r10, #00476E
 *
 * @var array         $attributes
 * @var WP_Block|null $block
 */

$eyebrow = $attributes['eyebrow'] ?? '';
$heading = $attributes['heading'] ?? '';
$cta     = $attributes['cta'] ?? [];
$bg      = $attributes['backgroundImage'] ?? [];
$scrim   = max(0, min(100, (int) ($attributes['scrimOpacity'] ?? 74)));
$minH    = max(400, min(1100, (int) ($attributes['minHeight'] ?? 841)));

// $block is null in the editor preview.
$is_preview = ! isset($block) || $block === null;

$bgUrl = $bg['url'] ?? '';

$body       = (string) ($attributes['body'] ?? '');
$background = ($attributes['background'] ?? 'photo') === 'gradient' ? 'gradient' : 'photo';

/**
 * Colour controls land in a style attribute, so they are checked against the
 * shapes the control can actually produce rather than merely escaped --
 * esc_attr would happily pass a value that closes the declaration.
 */
$colour = static function (string $value, string $fallback): string {
    return preg_match('/^(#[0-9a-f]{3,8}|(rgb|hsl)a?\([0-9a-z%.,\/\s]+\))$/i', trim($value))
        ? trim($value)
        : $fallback;
};

$gradTop    = $colour((string) ($attributes['gradientTop'] ?? ''), '#000000');
$gradBottom = $colour((string) ($attributes['gradientBottom'] ?? ''), '#011a27');

/* Measured off the About frame: solid black at the top easing into the deep
   navy the footer sits on, so the page reads as one continuous field. */
$gradientStyle = sprintf(
    'background-image:linear-gradient(180deg, %1$s 0%%, %2$s 100%%)',
    $gradTop,
    $gradBottom
);

/**
 * The scrim is one gradient rather than a flat tint so the photograph stays
 * readable on the right while the text side goes dark enough for white type.
 * 273deg points left, so the transparent stop lands on the image side.
 */
$scrimStyle = sprintf(
    'background-image:linear-gradient(273deg, rgba(0,0,0,0) 1.51%%, rgba(0,0,0,%s) 43.25%%)',
    rtrim(rtrim(number_format($scrim / 100, 2, '.', ''), '0'), '.') ?: '0'
);

/**
 * Height is driven by the design's 841px at 1440px wide (58.4vw) and floored so
 * the heading still has room to wrap on small screens.
 */
$heightStyle = sprintf('min-height:clamp(520px, 58.4vw, %dpx)', $minH);

$glowColor = preg_match('/^#[0-9a-f]{3,8}$/i', trim((string) ($attributes['highlightColor'] ?? '')))
    ? trim((string) $attributes['highlightColor'])
    : '#1f7fd4';

$wrapper = get_block_wrapper_attributes([
    'class' => 'cadco-hero relative isolate w-full overflow-hidden bg-true-black',
]);
?>
<section <?php echo $wrapper; ?> style="<?php echo esc_attr($heightStyle . ';--cadco-glow:' . $glowColor); ?>"<?php echo $is_preview ? '' : ' data-cadco-hero'; ?>>

    <?php if (! $is_preview) : ?>
        <?php /* Marks the pre-animation state while the section is still being
                 parsed, so the un-animated hero never gets a frame on screen —
                 view.js runs from the footer, far too late for that. A script
                 adds it, so JS-off visitors simply see the finished hero.
                 The timeout is the last line of defence: if view.js never loads
                 at all, nothing else would ever reveal the copy. Editor preview
                 is excluded — a hidden hero cannot be edited. */ ?>
        <script>(function(){var s=document.currentScript.parentNode;s.classList.add('is-anim-pending');
        setTimeout(function(){s.classList.remove('is-anim-pending');},10000);})();</script>
    <?php endif; ?>

    <?php // ---------- Background ---------- ?>
    <?php if ($background === 'gradient') : ?>
        <div class="absolute inset-0" style="<?php echo esc_attr($gradientStyle); ?>" aria-hidden="true"></div>
    <?php elseif ($bgUrl !== '') : ?>
        <img
            data-hero-image
            class="absolute inset-0 h-full w-full object-cover will-change-transform"
            src="<?php echo esc_url($bgUrl); ?>"
            alt="<?php echo esc_attr($bg['alt'] ?? ''); ?>"
            loading="eager"
            decoding="async"
            fetchpriority="high"
        />
    <?php elseif ($is_preview) : ?>
        <?php // Without this the editor shows a black rectangle and no hint that
              // the image lives in the sidebar rather than on the canvas. ?>
        <div class="absolute inset-0 flex items-center justify-center bg-gray-900">
            <span class="rounded-md border border-dashed border-white/30 px-4 py-2 text-[13px] text-white/60">
                <?php esc_html_e('Choose a background image in the block sidebar', 'cadco-theme'); ?>
            </span>
        </div>
    <?php endif; ?>

    <?php /* ---------- Scrim ----------
             Only over a photograph. The gradient is already a controlled field,
             so a second darkening pass over it just flattens the colour. */ ?>
    <?php if ($background !== 'gradient') : ?>
        <div class="pointer-events-none absolute inset-0" style="<?php echo esc_attr($scrimStyle); ?>" aria-hidden="true"></div>
    <?php endif; ?>

    <?php /* ---------- Content ----------
             Same container as the header and footer — max-w-[1440px] with
             px-6 lg:px-10 — so the copy starts on the same vertical line as the
             logo above it and the footer columns below. The Figma frame insets
             the hero to 160px, but its header is inset ~140px where the built
             header sits at 40px; sharing the shipped container is what actually
             lines the page up. Keep this in step with cadco-header and
             cadco-footer if that column ever changes. */ ?>
    <div class="relative mx-auto flex h-full w-full max-w-[1440px] flex-col justify-center px-6 lg:px-10"
         style="<?php echo esc_attr($heightStyle); ?>">

        <?php // Always rendered, empty or not, so both stay editable. ?>
        <p data-proto-field="eyebrow" data-hero-eyebrow
           class="m-0 mb-6 font-display text-[16px] font-extrabold leading-[1.2] text-white md:text-[20px]">
            <?php echo esc_html($eyebrow); ?>
        </p>

        <?php /* Capped at the design's 1239.89px so a single-field headline
                 still wraps as drawn. px-10 leaves 1360px inside the 1440
                 column, so this cap — not the padding — sets the measure.

                 [&>*]:m-0 is for the editor: it swaps each bound field for its
                 own rich-text element, which brings default block margins with
                 it, and those opened a gap between the two headline lines and
                 under the eyebrow that the front end never had. Zeroing the
                 margin of whatever element it injects keeps the editor looking
                 like the page. */ ?>
        <h1 data-hero-heading
            class="m-0 max-w-[1240px] font-display text-[clamp(38px,6.67vw,96px)] font-extrabold leading-[1.198] text-white [&>*]:m-0">
            <?php
            /*
             * Two fields rather than one, so a two-sentence headline breaks
             * where the author decides. Driving the break off a max-width
             * failed: the size is viewport-relative, so the same width held a
             * different number of words in the editor's narrower iframe than
             * on the front end, and the sentences split mid-sentence there.
             * Each line is its own block, so there is no wrap to get wrong.
             */
            $line2 = (string) ($attributes['headingLine2'] ?? '');

            /*
             * The design backlights one word of the headline. Rather than
             * hard-coding it, the author names the word and it is wrapped
             * where it falls -- across either line, matched once, ignoring
             * case. The match runs over the ALREADY ESCAPED string and the
             * wrapper is the only markup added, so nothing from the field can
             * become markup.
             */
            $highlight = trim((string) ($attributes['highlight'] ?? ''));
            $glowDone  = false;

            $glow = static function (string $text) use ($highlight, &$glowDone): string {
                $safe = esc_html($text);

                if ($glowDone || $highlight === '') {
                    return $safe;
                }

                $needle = esc_html($highlight);
                $at     = stripos($safe, $needle);

                if ($at === false) {
                    return $safe;
                }

                $glowDone = true;

                return substr($safe, 0, $at)
                    . '<span class="cadco-hero-glow">' . substr($safe, $at, strlen($needle)) . '</span>'
                    . substr($safe, $at + strlen($needle));
            };
            ?>
            <span data-proto-field="heading" class="m-0 block leading-[inherit]"><?php echo $glow($heading); ?></span>
            <?php if ($line2 !== '' || $is_preview) : ?>
                <span data-proto-field="headingLine2" class="m-0 block leading-[inherit]"><?php echo $glow($line2); ?></span>
            <?php endif; ?>
        </h1>

        <?php /* Rendered when it has copy, and always in the editor so it can
                 be filled in -- the same contract the button below uses. An
                 always-rendered wrapper would be empty but still carry mt-6,
                 pushing the button 24px down on every hero that has no body,
                 which is both existing instances. wp_kses_post rather than
                 esc_html: it is a wysiwyg region and carries paragraph markup. */ ?>
        <?php if ($body !== '' || $is_preview) : ?>
            <div data-proto-field="body" data-hero-body
                 class="cadco-hero-body mt-6 max-w-[620px] font-display text-[17px] font-light leading-[30px] text-white/90 md:text-[19px]">
                <?php echo wp_kses_post($body); ?>
            </div>
        <?php endif; ?>

        <?php if (! empty($cta['url']) || $is_preview) : ?>
            <div class="mt-10 lg:mt-[60px]">
                <a data-proto-field="cta" data-hero-cta
                   class="inline-flex h-[58px] min-w-[166px] items-center justify-center rounded-[10px] bg-cadco-blue px-6 font-display text-[16px] font-bold leading-none text-white no-underline transition-colors hover:bg-[#00395a]"
                   href="<?php echo esc_url($cta['url'] ?? '#'); ?>"
                   <?php echo ! empty($cta['target']) ? 'target="' . esc_attr($cta['target']) . '" rel="noopener"' : ''; ?>
                ><?php echo esc_html($cta['text'] ?? 'Contact Cadco'); ?></a>
            </div>
        <?php endif; ?>
    </div>
</section>
