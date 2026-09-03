<?php
/**
 * Cadco product categories.
 *
 * Figma: a tinted band holding a centred heading and standfirst, then a grid of
 * category cards, each a photograph over a white bar carrying the category name
 * and an arrow. Measurements taken from the design file (1440px frame):
 *
 *   band            rgba(232,237,244,.5)   card       536x386, r15
 *   heading         Barlow ExtraBold 36    image      536x312 (aspect 536/312)
 *   standfirst      Barlow Bold 20         bar        74px tall, 24px inset
 *   grid            1092 wide, 20px gap    name       Barlow Bold 24/32
 *   pad top         92px                   arrow      28px, 24px from the right
 *
 * The cards are not authored: they are the product_cat terms. Only the two
 * lines of copy above them are fields; everything else is a query.
 *
 * @var array         $attributes
 * @var WP_Block|null $block
 */

$heading    = $attributes['heading'] ?? '';
$subheading = $attributes['subheading'] ?? '';

$scope     = $attributes['scope'] ?? 'top';
$parentId  = (int) ($attributes['parentCategory'] ?? 0);
$limit     = max(1, min(12, (int) ($attributes['limit'] ?? 4)));
$columns   = (string) ($attributes['columns'] ?? '2');
$orderBy   = $attributes['orderBy'] ?? 'menu_order';
$order     = strtoupper((string) ($attributes['order'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';
$hideEmpty = (bool) ($attributes['hideEmpty'] ?? false);

$showOverlap = (bool) ($attributes['showOverlap'] ?? true);
$overlapH    = max(0, min(300, (int) ($attributes['overlapHeight'] ?? 111)));
$overhang    = max(0, min(200, (int) ($attributes['overlapOverhang'] ?? 80)));

// $block is null in the editor preview.
$is_preview = ! isset($block) || $block === null;

/**
 * The band colour goes into a style attribute, so it is checked against the
 * shapes the colour control can actually produce rather than merely escaped —
 * esc_attr would happily pass a value that closes the declaration.
 */
$overlapColor = (string) ($attributes['overlapColor'] ?? '#000000');
if (! preg_match('/^(#[0-9a-f]{3,8}|(rgb|hsl)a?\([0-9a-z%.,\/\s]+\))$/i', trim($overlapColor))) {
    $overlapColor = '#000000';
}

/**
 * Cards hang over the band, so the section's own bottom padding is only what is
 * left of the band below them. With the overhang at or past the band's height
 * the cards reach the section's edge and there is no padding left to give.
 */
$padBottom = $showOverlap ? max(0, $overlapH - $overhang) : 92;

/* -------------------------------------------------------------------------
   The categories.

   parent => 0 is a real constraint, not a default: without it get_terms()
   returns every term at every depth, so a catalogue with sub-categories would
   mix levels into one grid.

   menu_order is WooCommerce's own ordering (the drag handles on Products →
   Categories); it works through wc_terms_clauses(), so it is only offered while
   WooCommerce is loaded and falls back to name otherwise.
   ------------------------------------------------------------------------- */
$terms = [];

if (taxonomy_exists('product_cat')) {
    if ($orderBy === 'menu_order' && ! function_exists('wc_terms_clauses')) {
        $orderBy = 'name';
    }

    $found = get_terms([
        'taxonomy'   => 'product_cat',
        'hide_empty' => $hideEmpty,
        'number'     => $limit,
        'orderby'    => $orderBy,
        'order'      => $order,
        'parent'     => ($scope === 'children' && $parentId > 0) ? $parentId : 0,
    ]);

    if (! is_wp_error($found)) {
        $terms = $found;
    }
}

// Both literal, so the Tailwind compiler sees them when it scans this file.
$colClass = $columns === '3' ? 'md:grid-cols-2 lg:grid-cols-3' : 'md:grid-cols-2';

// Black marks on a white oval, transparent everywhere else — derived from the
// site logo, so a category with no thumbnail still reads as Cadco's.
$markUrl = get_theme_file_uri('assets/img/cadco-mark.svg');

$wrapper = get_block_wrapper_attributes([
    'class' => 'cadco-product-categories relative isolate w-full bg-[rgba(232,237,244,0.5)] pt-16 md:pt-[92px]',
]);

/**
 * Scroll reveal, front end only. See assets/js/cadco-reveal.js; the runtime
 * force-reveals the section if the script never runs, so the hidden start
 * state cannot strand the grid. Omitted in the editor so the canvas never
 * hides what an author is editing.
 */
$reveal = $is_preview ? '' : 'data-proto-animate="manual" data-cadco-reveal-group';
?>
<section <?php echo $wrapper; ?> <?php echo $reveal; ?> style="padding-bottom:<?php echo (int) $padBottom; ?>px">

    <?php // ---------- Overlap band ---------- ?>
    <?php if ($showOverlap && $overlapH > 0) : ?>
        <div class="pointer-events-none absolute inset-x-0 bottom-0 -z-10"
             style="height:<?php echo (int) $overlapH; ?>px;background-color:<?php echo esc_attr($overlapColor); ?>"
             aria-hidden="true"></div>
    <?php endif; ?>

    <?php /* The grid measures 1092px in the design (180.29 → 1272.02 of a 1440
             frame), narrower than the 1440 column the header, hero and footer
             share. px-6 either side of that is where the 1140 cap comes from. */ ?>
    <div class="relative mx-auto w-full max-w-[1140px] px-6">

        <?php // Always rendered, empty or not, so both stay editable. ?>
        <h2 data-proto-field="heading"
            data-cadco-reveal="lines"
            class="m-0 text-center font-display text-[28px] font-extrabold leading-[1.15] text-true-black md:text-h2">
            <?php echo esc_html($heading); ?>
        </h2>

        <p data-proto-field="subheading"
           data-cadco-reveal="rise"
           class="mx-auto mt-3.5 mb-0 max-w-[1090px] text-center font-display text-[17px] font-bold leading-[1.35] text-true-black md:text-body-lg">
            <?php echo esc_html($subheading); ?>
        </p>

        <?php if (! empty($terms)) : ?>
            <ul data-cadco-reveal="items"
                class="mt-14 grid list-none grid-cols-1 gap-5 p-0 md:mt-24 <?php echo esc_attr($colClass); ?>">
                <?php foreach ($terms as $term) : ?>
                    <?php
                    $link = get_term_link($term);
                    if (is_wp_error($link)) {
                        continue;
                    }

                    $thumbId = (int) get_term_meta($term->term_id, 'thumbnail_id', true);
                    ?>
                    <li class="m-0">
                        <a class="group block overflow-hidden rounded-[15px] bg-paper no-underline shadow-[0_4px_10.6px_0_rgba(0,0,0,0.15)]"
                           href="<?php echo esc_url($link); ?>">

                            <?php /* The zoom is on the image inside this box, not on
                                     the card, so the photograph grows behind a fixed
                                     frame instead of the whole card swelling. */ ?>
                            <div class="relative aspect-[536/312] overflow-hidden bg-light-grey/40">
                                <?php if ($thumbId > 0) : ?>
                                    <?php echo wp_get_attachment_image($thumbId, 'large', false, [
                                        'class'    => 'absolute inset-0 h-full w-full object-cover transition-transform duration-500 ease-out will-change-transform group-hover:scale-105 motion-reduce:transition-none motion-reduce:group-hover:scale-100',
                                        'alt'      => esc_attr($term->name),
                                        'loading'  => 'lazy',
                                        'decoding' => 'async',
                                    ]); ?>
                                <?php else : ?>
                                    <span class="absolute inset-0 flex items-center justify-center">
                                        <img src="<?php echo esc_url($markUrl); ?>"
                                             class="w-1/2 max-w-[240px] opacity-30"
                                             alt="" aria-hidden="true" loading="lazy" decoding="async" />
                                    </span>
                                <?php endif; ?>
                            </div>

                            <div class="flex h-[74px] items-center justify-between gap-4 px-6">
                                <h3 class="m-0 font-display text-[20px] font-bold leading-8 text-true-black transition-colors duration-300 group-hover:text-cadco-blue md:text-[24px]">
                                    <?php echo esc_html($term->name); ?>
                                </h3>

                                <?php /* Lucide arrow-right, the design's icon exactly:
                                         a 28px box, insets 20.83%/79.17%, stroke 2.5
                                         of a 24 viewBox (2.91667 at 28px in Figma). */ ?>
                                <svg class="h-7 w-7 shrink-0 text-true-black transition-colors duration-300 group-hover:text-cadco-blue"
                                     width="28" height="28" viewBox="0 0 24 24" fill="none"
                                     stroke="currentColor" stroke-width="2.5"
                                     stroke-linecap="round" stroke-linejoin="round"
                                     aria-hidden="true" focusable="false">
                                    <path d="M5 12h14" />
                                    <path d="m12 5 7 7-7 7" />
                                </svg>
                            </div>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>

        <?php elseif ($is_preview) : ?>
            <?php // Without this the block is an empty band on the canvas with no
                  // hint that the cards come from Products → Categories. ?>
            <div class="mt-14 rounded-[15px] border border-dashed border-black/25 px-6 py-14 text-center md:mt-24">
                <span class="font-display text-[15px] font-bold text-black/55">
                    <?php echo taxonomy_exists('product_cat')
                        ? esc_html__('No product categories to show yet — add them under Products → Categories.', 'cadco-theme')
                        : esc_html__('WooCommerce is not active, so there are no product categories to show.', 'cadco-theme'); ?>
                </span>
            </div>
        <?php endif; ?>
    </div>
</section>
