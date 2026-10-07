<?php
/**
 * Cadco product hero.
 *
 * Figma "Product Individual Page" (1440 frame). Measurements taken from the
 * design file:
 *
 *   breadcrumb   Barlow Regular 16, y 192      name    Barlow Bold 64
 *   photograph   704 x 438, left column        intro   Barlow Regular 16/24
 *   gutter       41 between the columns        claims  Barlow Regular 16/24, discs
 *   text column  from x 820                    price   Barlow Bold 16, #00476e
 *                                              button  166 x 58, r10
 *
 * Nothing here is authored except the call to action: the rest is the product
 * being viewed. The block is placed once in the single product template.
 *
 * The "Placeholder - ..." paragraph the frame shows is the product's own
 * introduction, which this reads as the description's opening prose -- whatever
 * an editor writes above the specification tables. A product that goes straight
 * into its specifications has none and the paragraph is simply left out.
 *
 * @var array         $attributes
 * @var WP_Block|null $block  Null in the editor preview.
 */

$cta        = $attributes['cta'] ?? [];
$showCrumb  = (bool) ($attributes['showBreadcrumb'] ?? true);
$shopTitle  = (string) ($attributes['shopTitle'] ?? 'All Products');
$showGal    = (bool) ($attributes['showGallery'] ?? true);
$showPrice  = (bool) ($attributes['showPrice'] ?? true);
$priceNote  = (string) ($attributes['priceNote'] ?? '');

// $block is null in the editor preview.
$is_preview = ! isset($block) || $block === null;

if (! function_exists('wc_get_product')) {
    if ($is_preview) {
        echo '<p style="padding:3rem;text-align:center;font-family:system-ui">'
            . esc_html__('WooCommerce is not active, so there is no product to show.', 'cadco-theme')
            . '</p>';
    }
    return;
}

/* -------------------------------------------------------------------------
   Which product.

   On the canvas there is no product being viewed, so the most recent one
   stands in -- an empty hero would tell an editor nothing about what the block
   does or whether their call to action reads properly against a real name.
   ------------------------------------------------------------------------- */
$productId = 0;

if (! $is_preview) {
    $queried = get_queried_object();

    if ($queried instanceof WP_Post && 'product' === $queried->post_type) {
        $productId = (int) $queried->ID;
    }
}

if (! $productId) {
    $recent    = get_posts(['post_type' => 'product', 'posts_per_page' => 1, 'fields' => 'ids']);
    $productId = $recent ? (int) $recent[0] : 0;
}

$product = $productId ? wc_get_product($productId) : null;

if (! $product) {
    if ($is_preview) {
        echo '<p style="padding:3rem;text-align:center;font-family:system-ui">'
            . esc_html__('There are no products yet, so there is nothing for this block to show.', 'cadco-theme')
            . '</p>';
    }
    return;
}

$intro  = cadco_product_intro($productId);
$claims = cadco_product_summary_lines($productId);
$price  = $showPrice ? (string) $product->get_price_html() : '';

/* The gallery, main image first. get_gallery_image_ids() excludes the featured
   image, which is the one the big frame shows, so the thumbnails are the rest. */
$galleryIds = $showGal ? array_map('intval', (array) $product->get_gallery_image_ids()) : [];

/* -------------------------------------------------------------------------
   The breadcrumb.

   Same resolution as the catalogue listing, and for the same reason:
   woocommerce_shop_page_id can name a post that no longer exists, and
   WooCommerce answers that with the home page.
   ------------------------------------------------------------------------- */
$shopUrl  = '';
$shopName = $shopTitle;
$shopId   = function_exists('wc_get_page_id') ? (int) wc_get_page_id('shop') : 0;

if ($shopId > 0 && 'publish' !== get_post_status($shopId)) {
    $shopId = 0;
}

if (! $shopId && function_exists('wc_get_permalink_structure')) {
    $base = trim((string) (wc_get_permalink_structure()['category_rewrite_slug'] ?? ''), '/');
    $page = '' !== $base ? get_page_by_path($base) : null;

    if ($page instanceof WP_Post && 'publish' === $page->post_status) {
        $shopId = (int) $page->ID;
    }
}

if ($shopId) {
    $shopUrl  = (string) get_permalink($shopId);
    $shopName = (string) get_the_title($shopId);
}

if ('' === $shopUrl) {
    $shopUrl = home_url('/');
}

/*
 * The deepest category the product is in, with its ancestors, so the trail is
 * the path that actually reaches this page rather than whichever term happens
 * to come back first.
 */
$trail = [];
$terms = get_the_terms($productId, 'product_cat');

if ($terms && ! is_wp_error($terms)) {
    $deepest = null;
    $depth   = -1;

    foreach ($terms as $term) {
        $d = count(get_ancestors($term->term_id, 'product_cat', 'taxonomy'));

        if ($d > $depth) {
            $depth   = $d;
            $deepest = $term;
        }
    }

    if ($deepest) {
        foreach (array_reverse(get_ancestors($deepest->term_id, 'product_cat', 'taxonomy')) as $ancestorId) {
            $ancestor = get_term((int) $ancestorId, 'product_cat');

            if ($ancestor instanceof WP_Term) {
                $trail[] = $ancestor;
            }
        }

        $trail[] = $deepest;
    }
}

$ctaUrl  = isset($cta['url']) ? (string) $cta['url'] : '';
$ctaText = isset($cta['text']) && '' !== trim((string) $cta['text'])
    ? (string) $cta['text']
    : __('Contact Cadco', 'cadco-theme');

$reveal = $is_preview ? '' : 'data-proto-animate="manual" data-cadco-reveal-group';

$wrapper = get_block_wrapper_attributes([
    'class' => 'cadco-product-hero w-full bg-paper pt-[118px] pb-[129px]',
]);
?>
<section <?php echo $wrapper; ?> <?php echo $reveal; ?>>
    <div class="mx-auto w-full max-w-[1440px] px-6 lg:px-10">

        <?php if ($showCrumb) : ?>
            <nav class="font-display text-[16px] leading-[19px] text-true-black"
                 aria-label="<?php esc_attr_e('Breadcrumb', 'cadco-theme'); ?>">
                <a class="text-true-black no-underline hover:text-cadco-blue" href="<?php echo esc_url($shopUrl); ?>">
                    <?php echo esc_html($shopName); ?>
                </a>
                <?php foreach ($trail as $crumb) : ?>
                    <?php $crumbUrl = get_term_link($crumb); ?>
                    <span class="mx-[0.9em] text-true-black/60" aria-hidden="true">|</span>
                    <a class="text-true-black no-underline hover:text-cadco-blue"
                       href="<?php echo esc_url(is_wp_error($crumbUrl) ? $shopUrl : $crumbUrl); ?>">
                        <?php echo esc_html($crumb->name); ?>
                    </a>
                <?php endforeach; ?>
                <span class="mx-[0.9em] text-true-black/60" aria-hidden="true">|</span>
                <span aria-current="page"><?php echo esc_html(get_the_title($productId)); ?></span>
            </nav>
        <?php endif; ?>

        <?php /* 704 and 620 of the frame's two columns, with its 41px gutter. */ ?>
        <div class="mt-10 grid grid-cols-1 gap-10 lg:mt-[78px] lg:grid-cols-[minmax(0,704fr)_minmax(0,620fr)] lg:gap-x-[41px]">

            <?php // ---------- Photograph ---------- ?>
            <div data-cadco-reveal="fade">
                <?php /* Contained: product shots arrive at every ratio from square to
                         panoramic, and a fixed 704x438 crop would cut the tall ones
                         in half. The box keeps the frame's proportion so the text
                         column starts level with the photograph whatever shape it is. */ ?>
                <div class="flex aspect-[704/438] w-full items-center justify-center overflow-hidden rounded-[15px] bg-paper">
                    <?php if (has_post_thumbnail($productId)) : ?>
                        <?php echo wp_get_attachment_image(get_post_thumbnail_id($productId), 'large', false, [
                            'class'    => 'h-full w-full object-contain',
                            'alt'      => the_title_attribute(['echo' => false, 'post' => $productId]),
                            'decoding' => 'async',
                            'sizes'    => '(min-width: 1024px) 704px, 92vw',
                        ]); ?>
                    <?php else : ?>
                        <img src="<?php echo esc_url(get_theme_file_uri('assets/img/cadco-mark.svg')); ?>"
                             class="h-1/3 w-auto opacity-20" alt="" aria-hidden="true" decoding="async" />
                    <?php endif; ?>
                </div>

                <?php if (count($galleryIds) > 0) : ?>
                    <ul class="m-0 mt-5 flex list-none flex-wrap gap-4 p-0">
                        <?php foreach ($galleryIds as $galleryId) : ?>
                            <li class="m-0">
                                <?php /* A link, not a script: it opens the image at full
                                         size, which works with no JavaScript and gives a
                                         keyboard and a screen reader something real. */ ?>
                                <a href="<?php echo esc_url((string) wp_get_attachment_image_url($galleryId, 'full')); ?>"
                                   class="block h-[88px] w-[112px] overflow-hidden rounded-[10px] border border-[#e8edf4] bg-paper p-1 transition-colors hover:border-cadco-blue">
                                    <?php echo wp_get_attachment_image($galleryId, 'medium', false, [
                                        'class'    => 'h-full w-full object-contain',
                                        'loading'  => 'lazy',
                                        'decoding' => 'async',
                                    ]); ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <?php // ---------- Name, introduction, claims, price ---------- ?>
            <div class="flex flex-col lg:pt-[17px]">
                <h1 data-cadco-reveal="lines"
                    class="m-0 font-display text-[34px] font-bold leading-[1.12] text-true-black md:text-[48px] lg:text-[64px]">
                    <?php echo esc_html(get_the_title($productId)); ?>
                </h1>

                <?php if ('' !== $intro) : ?>
                    <div data-cadco-reveal="rise"
                         class="cadco-product-intro mt-8 max-w-[511px] font-display text-[16px] leading-[24px] text-true-black lg:mt-[57px]">
                        <?php echo wp_kses_post($intro); ?>
                    </div>
                <?php endif; ?>

                <?php if ($claims) : ?>
                    <ul data-cadco-reveal="rise"
                        class="mt-8 mb-0 list-disc pl-6 font-display text-[16px] leading-[24px] text-true-black <?php echo '' !== $intro ? 'lg:mt-[23px]' : 'lg:mt-[57px]'; ?>">
                        <?php foreach ($claims as $claim) : ?>
                            <li class="m-0"><?php echo esc_html($claim); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <?php if ('' !== $price) : ?>
                    <p data-cadco-reveal="rise"
                       class="mt-8 mb-0 font-display text-[16px] font-bold leading-none text-cadco-blue lg:mt-[34px]">
                        <?php if ('' !== $priceNote) : ?>
                            <span class="font-normal text-true-black"><?php echo esc_html($priceNote); ?> </span>
                        <?php endif; ?>
                        <?php echo wp_kses_post($price); ?>
                    </p>
                <?php endif; ?>

                <?php /* Rendered whenever it has an address, and always on the canvas
                         so it stays editable even before one is set. */ ?>
                <?php if ('' !== $ctaUrl || $is_preview) : ?>
                    <div data-cadco-reveal="rise" class="mt-9 lg:mt-[48px]">
                        <a data-proto-field="cta"
                           class="inline-flex h-[58px] min-w-[166px] items-center justify-center rounded-[10px] bg-cadco-blue px-6 font-display text-[16px] font-bold leading-none text-white no-underline transition-colors hover:bg-[#00395a]"
                           href="<?php echo esc_url($ctaUrl); ?>"
                           <?php echo ! empty($cta['target']) ? 'target="' . esc_attr($cta['target']) . '"' : ''; ?>
                           <?php echo ! empty($cta['rel']) ? 'rel="' . esc_attr($cta['rel']) . '"' : ''; ?>>
                            <?php echo esc_html($ctaText); ?>
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>
