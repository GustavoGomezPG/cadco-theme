<?php
/**
 * Cadco featured products.
 *
 * Figma: a black-to-transparent gradient band, an eyebrow and a display
 * heading, then a centre-snapped carousel of product cards, then a button.
 * Measurements taken from the design file (1440px frame, 1139px band):
 *
 *   gradient  #000 21.538% -> rgba(0,0,0,.79) 41.799% -> transparent 91.415%
 *   eyebrow   Barlow ExtraBold 24, white, y 115
 *   heading   Barlow Bold 64, white, max 913, y 179
 *   cards     645x328.69, y 430, 42px gap
 *   card      radius 20 on three corners -- TOP-LEFT IS SQUARE
 *             active #f4f4f4, neighbours rgba(244,244,244,.73)
 *             image 213sq at 40,61   text column starts at 310
 *             title Barlow Bold 24    bullets Barlow Regular 16/24
 *   button    179x58, r10, #00476E, y 856
 *
 * The card body is the product's own data: its featured image, its name, and
 * the first bullet list out of its description -- which is exactly where the
 * workbook importer writes 'Supplier Specifications - Bullet Points'. Nothing
 * about a card is authored here, so a product edit updates every carousel it
 * appears in.
 *
 * @var array         $attributes
 * @var WP_Block|null $block
 */

$eyebrow   = $attributes['eyebrow'] ?? '';
$heading   = $attributes['heading'] ?? '';
$cta       = $attributes['cta'] ?? [];
$picked    = $attributes['products'] ?? [];
$maxBullet = max(1, min(6, (int) ($attributes['bulletLimit'] ?? 4)));
$linkLabel = $attributes['linkLabel'] ?? 'See all Features';
$autoplay  = (bool) ($attributes['autoplay'] ?? true);
$delay     = max(2, min(15, (int) ($attributes['autoplayDelay'] ?? 5)));
$bg        = $attributes['backgroundImage'] ?? [];
$bgPos     = $attributes['backgroundPosition'] ?? 'center';

$bgUrl = $bg['url'] ?? '';
$bgId  = (int) ($bg['id'] ?? 0);

// Both literal, so the Tailwind compiler sees them when it scans this file.
$bgPosClass = $bgPos === 'top' ? 'object-top' : ($bgPos === 'bottom' ? 'object-bottom' : 'object-center');

// $block is null in the editor preview.
$is_preview = ! isset($block) || $block === null;

/**
 * The chosen products, in the author's order.
 *
 * 'post__in' ordering is the load-bearing half: without it WordPress returns
 * date order and throws away the ordering the multiselect control exists to
 * capture.
 *
 * With nothing picked the block falls back to WooCommerce's own Featured flag
 * rather than rendering an empty band, so it is useful the moment it is
 * inserted and an author can curate later.
 */
$ids = array_values(array_filter(array_map('intval', (array) $picked)));
$usingFallback = false;

if ($ids !== []) {
    $products = get_posts([
        'post_type'        => 'product',
        'post__in'         => $ids,
        'orderby'          => 'post__in',
        'posts_per_page'   => count($ids),
        'suppress_filters' => false,
    ]);
} else {
    // Remembered so the editor can say so -- otherwise an author sees six cards
    // above an empty picker and has no way to tell where they came from.
    $usingFallback = true;

    $products = get_posts([
        'post_type'      => 'product',
        'posts_per_page' => 8,
        'orderby'        => 'menu_order title',
        'order'          => 'ASC',
        'tax_query'      => [[
            'taxonomy' => 'product_visibility',
            'field'    => 'name',
            'terms'    => 'featured',
        ]],
    ]);
}

/**
 * The first bullet list out of a product description.
 *
 * The importer writes the supplier's spec bullets as the description's opening
 * <ul>, optionally followed by an "Additional information" heading and a second
 * list. Only the first list belongs on a card, so this stops at it rather than
 * collecting every <li> in the document.
 *
 * Guarded because a Proto-Blocks template is included once per block INSTANCE,
 * not once per request: a page carrying two of these blocks -- or one block
 * rendered a second time in the same request, as the editor preview does --
 * hits "Cannot redeclare" and fatals the page. The guard is what makes this
 * template safe to include repeatedly.
 *
 * @return list<string>
 */
if (! function_exists('cadco_featured_bullets')) {
    function cadco_featured_bullets(string $content, int $limit): array
    {
        if ($content === '' || ! preg_match('#<ul\b[^>]*>(.*?)</ul>#is', $content, $list)) {
            return [];
        }

        if (! preg_match_all('#<li\b[^>]*>(.*?)</li>#is', $list[1], $items)) {
            return [];
        }

        $out = [];

        foreach ($items[1] as $item) {
            $text = trim(html_entity_decode(wp_strip_all_tags($item), ENT_QUOTES, 'UTF-8'));

            if ($text !== '') {
                $out[] = $text;
            }

            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }
}

$wrapper = get_block_wrapper_attributes([
    'class' => 'cadco-featured-products relative isolate w-full overflow-hidden bg-paper',
]);
?>
<section <?php echo $wrapper; ?>>

    <?php /* The photograph sits behind the gradient rather than as a CSS
             background so it gets srcset and lazy decoding, the same way the
             hero handles its backdrop. The gradient's bottom stop is
             transparent, so the lower half of the picture reads through
             behind the cards -- which is the whole reason the design fades to
             transparent instead of to white. */ ?>
    <?php if ($bgId > 0) : ?>
        <?php /* Rendered from the attachment id rather than the control's bare
                 url so WordPress emits srcset -- this is a full-bleed 1440px
                 photograph, so serving a phone the desktop file is the single
                 most expensive mistake available here. */ ?>
        <?php echo wp_get_attachment_image($bgId, 'full', false, [
            'class'         => 'pointer-events-none absolute inset-0 -z-20 h-full w-full object-cover ' . $bgPosClass,
            'alt'           => '',
            'aria-hidden'   => 'true',
            'loading'       => 'lazy',
            'decoding'      => 'async',
        ]); ?>
    <?php elseif ($bgUrl !== '') : ?>
        <?php // No id (a url pasted straight into the attribute): no srcset available. ?>
        <img
            class="pointer-events-none absolute inset-0 -z-20 h-full w-full object-cover <?php echo esc_attr($bgPosClass); ?>"
            src="<?php echo esc_url($bgUrl); ?>"
            alt=""
            aria-hidden="true"
            loading="lazy"
            decoding="async"
        />
    <?php endif; ?>

    <?php /* One gradient rather than a flat fill: the band has to start solid
             black under the section above it and fade out over whatever sits
             below -- the photograph when there is one, the white page when
             there is not. */ ?>
    <div class="pointer-events-none absolute inset-0 -z-10"
         style="background-image:linear-gradient(to bottom,#000 21.538%,rgba(0,0,0,0.79) 41.799%,rgba(0,0,0,0) 91.415%)"
         aria-hidden="true"></div>

    <div class="pt-16 md:pt-[115px]">

        <?php // Always rendered, empty or not, so both stay editable. ?>
        <p data-proto-field="eyebrow"
           class="m-0 px-6 text-center font-display text-[18px] font-extrabold leading-tight text-white md:text-[24px]">
            <?php echo esc_html($eyebrow); ?>
        </p>

        <h2 data-proto-field="heading"
            class="mx-auto mt-6 mb-0 max-w-[913px] px-6 text-center font-display text-[32px] font-bold leading-[1.12] text-white md:mt-[36px] md:text-[64px]">
            <?php echo esc_html($heading); ?>
        </h2>

        <?php if (! empty($products)) : ?>
            <?php /* NO data-lenis-prevent here, deliberately.
                     It reads like the right tool -- "don't let the smooth-scroll
                     runtime eat wheel events inside this track" -- but Lenis's
                     prevent is all-or-nothing across BOTH axes. With it, every
                     vertical wheel with the pointer over this carousel was
                     ignored by Lenis and scrolled the page natively instead,
                     leaving Lenis's internal position stale; the moment the
                     pointer left the carousel Lenis resumed from that stale
                     target and yanked the page up or down.
                     It is not needed: Lenis's gestureOrientation defaults to
                     'vertical', so horizontal trackpad and wheel gestures pass
                     through to this track untouched anyway. */ ?>
            <div class="cadco-featured-products__track mt-12 flex snap-x snap-mandatory gap-[42px] overflow-x-auto overscroll-x-contain pb-4 md:mt-[136px]"
                 data-featured-track
                 <?php if ($autoplay && ! $is_preview) : ?>
                     <?php /* Never in the editor: a canvas that scrolls itself
                              while you are trying to edit it is maddening. */ ?>
                     data-featured-autoplay="<?php echo (int) ($delay * 1000); ?>"
                 <?php endif; ?>
                 tabindex="0"
                 role="group" aria-label="<?php esc_attr_e('Featured products', 'cadco-theme'); ?>">

                <?php foreach ($products as $i => $product) : ?>
                    <?php
                    $bullets = cadco_featured_bullets((string) $product->post_content, $maxBullet);
                    $thumb   = get_post_thumbnail_id($product->ID);
                    ?>
                    <article
                        class="cadco-featured-products__card group/card relative flex shrink-0 snap-center rounded-b-[20px] rounded-tr-[20px] pl-6 pr-6 pt-10 pb-10 md:h-[328.69px] md:w-[645px] md:pl-10 md:pr-6 md:pt-[61px] md:pb-[55px]"
                        <?php echo $i === 0 ? 'data-featured-active' : ''; ?>>

                        <?php /* object-contain, not cover: these are product
                                 renders on transparent grounds, and cropping one
                                 to fill a square cuts the machine in half. */ ?>
                        <div class="hidden w-[213px] shrink-0 md:block">
                            <?php if ($thumb) : ?>
                                <?php echo wp_get_attachment_image($thumb, 'medium_large', false, [
                                    'class'    => 'h-[213px] w-[213px] object-contain',
                                    'alt'      => esc_attr(get_the_title($product)),
                                    'loading'  => 'lazy',
                                    'decoding' => 'async',
                                ]); ?>
                            <?php endif; ?>
                        </div>

                        <div class="flex min-w-0 flex-1 flex-col md:ml-[57px] md:pt-[7px]">
                            <h3 class="m-0 font-display text-[20px] font-bold leading-tight text-true-black md:text-[24px]">
                                <?php echo esc_html(get_the_title($product)); ?>
                            </h3>

                            <?php if ($bullets !== []) : ?>
                                <ul class="mt-4 mb-0 list-disc pl-5 font-display text-[15px] font-normal leading-6 text-true-black md:mt-5 md:text-[16px]">
                                    <?php foreach ($bullets as $bullet) : ?>
                                        <li class="mb-0"><?php echo esc_html($bullet); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>

                            <a class="mt-6 inline-flex items-center gap-3 self-start font-display text-[16px] font-bold text-true-black no-underline transition-colors duration-300 group-hover/card:text-cadco-blue hover:text-cadco-blue focus-visible:text-cadco-blue md:mt-auto md:text-[18px]"
                               href="<?php echo esc_url(get_permalink($product)); ?>">
                                <span><?php echo esc_html($linkLabel); ?></span>
                                <?php /* The design's own long-tailed arrow, not the
                                         Lucide one the category cards use. */ ?>
                                <svg class="h-[15px] w-[24px] shrink-0"
                                     viewBox="0 0 23.6914 14.7279" fill="currentColor"
                                     aria-hidden="true" focusable="false">
                                    <path d="M23.3985 8.07107C23.789 7.68054 23.789 7.04738 23.3985 6.65685L17.0346 0.292893C16.644 -0.097631 16.0109 -0.097631 15.6203 0.292893C15.2298 0.683418 15.2298 1.31658 15.6203 1.70711L21.2772 7.36396L15.6203 13.0208C15.2298 13.4113 15.2298 14.0445 15.6203 14.435C16.0109 14.8256 16.644 14.8256 17.0346 14.435L23.3985 8.07107ZM0 7.36396V8.36396H22.6914V7.36396V6.36396H0V7.36396Z" />
                                </svg>
                            </a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <?php if ($usingFallback && $is_preview) : ?>
                <?php /* The sidebar cannot say this: Proto-Blocks does not render
                         a control's `help` text, so the picker gives no hint that
                         an empty selection means "every Featured product". */ ?>
                <p class="mx-auto mt-6 max-w-[645px] px-6 text-center font-display text-[13px] font-bold text-white/70">
                    <?php esc_html_e('Showing every product flagged Featured, because no products are picked. Choose products in the block sidebar to curate and order them yourself.', 'cadco-theme'); ?>
                </p>
            <?php endif; ?>

        <?php elseif ($is_preview) : ?>
            <?php // Without this the block is an empty gradient on the canvas with
                  // no hint that the cards come from the sidebar or the Featured flag. ?>
            <div class="mx-auto mt-12 max-w-[645px] rounded-[20px] border border-dashed border-white/35 px-6 py-14 text-center md:mt-[136px]">
                <span class="font-display text-[15px] font-bold text-white/70">
                    <?php echo taxonomy_exists('product_visibility')
                        ? esc_html__('Pick products in the block sidebar, or flag some products as Featured.', 'cadco-theme')
                        : esc_html__('WooCommerce is not active, so there are no products to show.', 'cadco-theme'); ?>
                </span>
            </div>
        <?php endif; ?>

        <?php if (! empty($cta['url']) || $is_preview) : ?>
            <div class="mt-14 flex justify-center pb-24 md:mt-[82px] md:pb-[225px]">
                <a data-proto-field="cta"
                   class="inline-flex h-[58px] min-w-[179px] items-center justify-center rounded-[10px] bg-cadco-blue px-6 font-display text-[16px] font-bold leading-none text-white no-underline transition-colors hover:bg-[#00395a]"
                   href="<?php echo esc_url($cta['url'] ?? '#'); ?>"
                   <?php echo ! empty($cta['target']) ? 'target="' . esc_attr($cta['target']) . '" rel="noopener"' : ''; ?>
                ><?php echo esc_html($cta['text'] ?? 'Contact Cadco'); ?></a>
            </div>
        <?php endif; ?>
    </div>
</section>
