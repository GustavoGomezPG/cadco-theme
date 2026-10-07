<?php
/**
 * Cadco product archive.
 *
 * Figma "Products Convection Oven Page" (1440 frame). Measurements taken from
 * the design file:
 *
 *   breadcrumb   Barlow Regular 16, y 190      card        234x386, r15
 *   title        Barlow ExtraBold 48, right    card bg     #fff @ .81, 2px white border
 *   rail         264 wide, 118 -> 382          card shadow 0 0 8.9px rgba(0,0,0,.25)
 *   rail name    Barlow ExtraBold 32           card pad    23 left, 19 right, 22 top
 *   rail links   Barlow Bold 20, right, 42.9   image       186x116
 *   View All     88x36, r10, #00476e           name        Barlow SemiBold 16/20
 *   Select       151x36, r10, 1px #00476e      spec        Barlow Regular 15/27
 *   grid         742 wide, 3 up, 20/14.8 gaps  price       Barlow SemiBold 15
 *
 * Nothing here is authored. The block is placed once in the product archive
 * template and reads the query, so one instance serves the shop page and every
 * category and sub-category under it. The controls are the few decisions that
 * are the site's rather than the query's.
 *
 * Two deliberate departures from the frame, both because the frame draws one
 * fixed case and this renders every case:
 *
 *   - Product photographs are contained, not cropped. The design's slot is
 *     186x116 (1.6:1) with an image cut to fit; the real catalogue runs from
 *     1:1 to 3.2:1, and cover would take the top and bottom off every square
 *     one.
 *   - There is pagination. The frame draws fifteen cards above a count of 28,
 *     so it is already showing one page of a longer list without drawing the
 *     control that gets you to the rest.
 *
 * @var array         $attributes
 * @var WP_Block|null $block  Null in the editor preview.
 */

$titleFormat = (string) ($attributes['titleFormat'] ?? 'Explore %s');
$shopTitle   = (string) ($attributes['shopTitle'] ?? 'All Products');
$showCrumb   = (bool) ($attributes['showBreadcrumb'] ?? true);
$showRail    = (bool) ($attributes['showRail'] ?? true);
$viewAll     = (string) ($attributes['viewAllLabel'] ?? 'View All');
$filterTax   = sanitize_key((string) ($attributes['filterAttribute'] ?? 'pa_size'));
$filterLabel = (string) ($attributes['filterLabel'] ?? 'Select Size');
$perPage     = max(3, min(60, (int) ($attributes['perPage'] ?? 15)));
$orderBy     = (string) ($attributes['orderBy'] ?? 'menu_order');
$showPrice   = (bool) ($attributes['showPrice'] ?? true);

// $block is null in the editor preview.
$is_preview = ! isset($block) || $block === null;

/* Literal classes, so Tailwind's scanner sees them: it cannot generate a class
   from a string PHP assembles at run time. */
$surface  = ($attributes['surface'] ?? 'paper') === 'tint' ? 'bg-[#eef2f6]' : 'bg-paper';
$cols     = (string) ($attributes['columns'] ?? '3');
$gridCols = '2' === $cols ? 'sm:grid-cols-2'
    : ('4' === $cols ? 'sm:grid-cols-2 xl:grid-cols-4' : 'sm:grid-cols-2 xl:grid-cols-3');

if (! post_type_exists('product') || ! taxonomy_exists('product_cat')) {
    if ($is_preview) {
        echo '<p style="padding:3rem;text-align:center;font-family:system-ui">'
            . esc_html__('WooCommerce is not active, so there is no catalogue to list.', 'cadco-theme')
            . '</p>';
    }
    return;
}

/* -------------------------------------------------------------------------
   What is being looked at.

   get_queried_object() rather than is_tax(): in the site editor the template
   is rendered with no main query at all, and on the shop page the queried
   object is the shop *page*, not a term. Both answer "no category", which is
   the state the shop view and the canvas want.
   ------------------------------------------------------------------------- */
$term = null;

if (! $is_preview) {
    $queried = get_queried_object();

    if ($queried instanceof WP_Term && 'product_cat' === $queried->taxonomy) {
        $term = $queried;
    }
}

/* -------------------------------------------------------------------------
   Where "the whole catalogue" is.

   Not wc_get_page_permalink('shop') on its own: woocommerce_shop_page_id can
   name a post that no longer exists, and WooCommerce answers that with the
   *home page*, which would quietly send the first breadcrumb somewhere that
   is not the catalogue at all. The product permalink base names the page that
   is, so it is the fallback, and the page's own title is used in the
   breadcrumb so the root reads as whatever the site calls it.
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

$selfUrl = $term ? (string) get_term_link($term) : $shopUrl;

if (is_wp_error($selfUrl) || '' === $selfUrl) {
    $selfUrl = $shopUrl;
}

$viewName = $term ? $term->name : $shopTitle;
$title    = false === strpos($titleFormat, '%s')
    ? $titleFormat
    : sprintf($titleFormat, $viewName);

/* -------------------------------------------------------------------------
   The rail.

   The frame draws a category with its children beneath it. A leaf category has
   none, so it borrows its parent's family and highlights itself inside it --
   which is the same picture, one level down, rather than an empty rail.
   ------------------------------------------------------------------------- */
$railTerm = $term;

$siblingsOf = static function (int $parent): array {
    $found = get_terms([
        'taxonomy'   => 'product_cat',
        'parent'     => $parent,
        'hide_empty' => true,
        'orderby'    => 'menu_order',
        'order'      => 'ASC',
    ]);

    return is_wp_error($found) ? [] : $found;
};

$railTerms = $siblingsOf($term ? (int) $term->term_id : 0);

if (! $railTerms && $term && $term->parent) {
    $parent = get_term((int) $term->parent, 'product_cat');

    if ($parent instanceof WP_Term) {
        $railTerm  = $parent;
        $railTerms = $siblingsOf((int) $parent->term_id);
    }
}

$railName = $railTerm ? $railTerm->name : $shopTitle;
$railUrl  = $railTerm ? get_term_link($railTerm) : $shopUrl;
$railUrl  = is_wp_error($railUrl) ? $shopUrl : (string) $railUrl;

// View All is the current view whenever no child below it has been picked.
$railIsCurrent = ! $term || ! $railTerm || (int) $term->term_id === (int) $railTerm->term_id;

/* -------------------------------------------------------------------------
   The query.

   Its own query rather than the loop the archive already ran: the same block
   has to work on the shop page, on a category, and on the editor canvas, where
   there is no loop to read. Paging therefore travels in its own parameter --
   `paged` belongs to the main query, and borrowing it would mean every page
   link had to be a URL the main query could also satisfy.
   ------------------------------------------------------------------------- */
$filterOn   = taxonomy_exists($filterTax);
$filterSlug = ($is_preview || ! $filterOn) ? '' : sanitize_title((string) ($_GET['psize'] ?? ''));
$paged      = $is_preview ? 1 : max(1, (int) ($_GET['ppage'] ?? 1));

$taxQuery = [];

if ($term) {
    $taxQuery[] = [
        'taxonomy'         => 'product_cat',
        'field'            => 'term_id',
        'terms'            => (int) $term->term_id,
        'include_children' => true,
    ];
}

if ('' !== $filterSlug) {
    $taxQuery[] = ['taxonomy' => $filterTax, 'field' => 'slug', 'terms' => $filterSlug];
}

// Products the shop manager has taken out of the catalogue stay out of it.
if (taxonomy_exists('product_visibility')) {
    $taxQuery[] = [
        'taxonomy' => 'product_visibility',
        'field'    => 'name',
        'terms'    => 'exclude-from-catalog',
        'operator' => 'NOT IN',
    ];
}

if (count($taxQuery) > 1) {
    $taxQuery['relation'] = 'AND';
}

$args = [
    'post_type'           => 'product',
    'post_status'         => 'publish',
    'posts_per_page'      => $perPage,
    'paged'               => $paged,
    'ignore_sticky_posts' => true,
    'tax_query'           => $taxQuery ?: null,
];

switch ($orderBy) {
    case 'title':
        $args['orderby'] = 'title';
        $args['order']   = 'ASC';
        break;
    case 'price_asc':
    case 'price_desc':
        $args['meta_key'] = '_price';
        $args['orderby']  = 'meta_value_num';
        $args['order']    = 'price_desc' === $orderBy ? 'DESC' : 'ASC';
        break;
    case 'date':
        $args['orderby'] = 'date';
        $args['order']   = 'DESC';
        break;
    default:
        $args['orderby'] = 'menu_order title';
        $args['order']   = 'ASC';
}

$query = new WP_Query($args);
$total = (int) $query->found_posts;

/* -------------------------------------------------------------------------
   The refinement select.

   Offered only where it can do something: the attribute has to exist, and its
   terms are narrowed to the ones products in this category actually carry, so
   a size that would return nothing is never on the list. `object_ids` is the
   whole archive rather than the page on screen -- the select filters the list,
   not the page.
   ------------------------------------------------------------------------- */
$filterTerms = [];

if ($showRail && $filterOn) {
    $scope = new WP_Query([
        'post_type'              => 'product',
        'post_status'            => 'publish',
        'posts_per_page'         => 500,
        'fields'                 => 'ids',
        'no_found_rows'          => true,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
        'tax_query'              => $term ? [[
            'taxonomy'         => 'product_cat',
            'field'            => 'term_id',
            'terms'            => (int) $term->term_id,
            'include_children' => true,
        ]] : null,
    ]);

    if ($scope->posts) {
        $found = wp_get_object_terms($scope->posts, $filterTax, [
            'orderby' => 'menu_order',
            'order'   => 'ASC',
        ]);

        if (! is_wp_error($found)) {
            $filterTerms = $found;
        }
    }
}

/**
 * A URL for this view with one thing changed.
 *
 * Everything the reader already chose is carried along, so paging never
 * silently drops their refinement, and refining never strands them on a page
 * number that the shorter list does not have.
 */
$viewUrl = static function (array $changes) use ($selfUrl, $filterSlug, $paged): string {
    $args = array_merge(['psize' => $filterSlug, 'ppage' => $paged > 1 ? $paged : ''], $changes);
    $args = array_filter($args, static fn ($v): bool => '' !== $v && null !== $v && 0 !== $v);

    return esc_url(add_query_arg($args, $selfUrl));
};

$reveal = $is_preview ? '' : 'data-proto-animate="manual" data-cadco-reveal-group';

$wrapper = get_block_wrapper_attributes([
    'class' => 'cadco-product-archive w-full ' . $surface . ' pt-[100px] pb-[120px]',
]);
?>
<section <?php echo $wrapper; ?> <?php echo $reveal; ?>>
    <div class="mx-auto w-full max-w-[1140px] px-6">

        <?php // ---------- Breadcrumb and title ---------- ?>
        <div class="flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between lg:gap-10">
            <?php if ($showCrumb) : ?>
                <nav class="font-display text-[16px] leading-[19px] text-true-black"
                     aria-label="<?php esc_attr_e('Breadcrumb', 'cadco-theme'); ?>">
                    <a class="text-true-black no-underline hover:text-cadco-blue" href="<?php echo esc_url($shopUrl); ?>">
                        <?php echo esc_html($shopName); ?>
                    </a>
                    <?php
                    /* Ancestors first, so a sub-category reads as the path that
                       reaches it rather than as a name with no address. */
                    $trail = [];

                    if ($term) {
                        foreach (array_reverse(get_ancestors((int) $term->term_id, 'product_cat', 'taxonomy')) as $ancestorId) {
                            $ancestor = get_term((int) $ancestorId, 'product_cat');

                            if ($ancestor instanceof WP_Term) {
                                $trail[] = $ancestor;
                            }
                        }

                        $trail[] = $term;
                    }
                    ?>
                    <?php foreach ($trail as $i => $crumb) : ?>
                        <span class="mx-[0.9em] text-true-black/60" aria-hidden="true">|</span>
                        <?php if ($i === count($trail) - 1) : ?>
                            <span aria-current="page"><?php echo esc_html($crumb->name); ?></span>
                        <?php else : ?>
                            <?php $crumbUrl = get_term_link($crumb); ?>
                            <a class="text-true-black no-underline hover:text-cadco-blue"
                               href="<?php echo esc_url(is_wp_error($crumbUrl) ? $shopUrl : $crumbUrl); ?>">
                                <?php echo esc_html($crumb->name); ?>
                            </a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </nav>
            <?php endif; ?>

            <h1 data-cadco-reveal="lines"
                class="m-0 font-display text-[32px] font-extrabold leading-[1.2] text-true-black md:text-[40px] lg:text-right lg:text-h1">
                <?php echo esc_html($title); ?>
            </h1>
        </div>

        <?php /* 264px is the rail's width in the frame; the 88px gutter is what
                 is left of the 1092 column once the 740 grid has its three
                 234px cards and two 20px gaps. */ ?>
        <div class="mt-12 grid grid-cols-1 gap-y-12 lg:mt-[146px] lg:grid-cols-[264px_minmax(0,1fr)] lg:gap-x-[88px]">

            <?php // ---------- Category rail ---------- ?>
            <?php if ($showRail) : ?>
                <div class="lg:sticky lg:top-[120px] lg:self-start">
                    <h2 class="m-0 font-display text-[26px] font-extrabold leading-[1.18] text-true-black lg:text-[32px]">
                        <?php echo esc_html($railName); ?>
                    </h2>

                    <?php if ($railTerms) : ?>
                        <nav class="mt-7 flex flex-col items-start gap-[7.9px] lg:items-end"
                             aria-label="<?php esc_attr_e('Sub-categories', 'cadco-theme'); ?>">
                            <?php foreach ($railTerms as $railChild) : ?>
                                <?php
                                $childUrl    = get_term_link($railChild);
                                $childActive = $term && (int) $term->term_id === (int) $railChild->term_id;
                                ?>
                                <?php if (! is_wp_error($childUrl)) : ?>
                                    <a href="<?php echo esc_url($childUrl); ?>"
                                       <?php echo $childActive ? 'aria-current="page"' : ''; ?>
                                       class="flex h-[35px] items-center px-0 font-display text-[18px] font-bold leading-none no-underline transition-colors hover:text-cadco-blue lg:text-right lg:text-[20px] <?php echo $childActive ? 'text-cadco-blue' : 'text-true-black'; ?>">
                                        <?php echo esc_html($railChild->name); ?>
                                    </a>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </nav>
                    <?php endif; ?>

                    <?php /* Filled while it is the view you are on, outlined once a
                             sub-category has taken over, so the rail always shows
                             which of its entries is current. */ ?>
                    <div class="mt-6 flex lg:justify-end">
                        <a href="<?php echo esc_url($railUrl); ?>"
                           <?php echo $railIsCurrent && ! $filterSlug ? 'aria-current="page"' : ''; ?>
                           class="inline-flex h-[36px] min-w-[88px] items-center justify-center rounded-[10px] border border-cadco-blue px-4 font-display text-[16px] font-bold leading-none no-underline transition-colors <?php echo $railIsCurrent
                                ? 'bg-cadco-blue text-white hover:bg-[#00395a]'
                                : 'bg-transparent text-cadco-blue hover:bg-cadco-blue hover:text-white'; ?>">
                            <?php echo esc_html($viewAll); ?>
                        </a>
                    </div>

                    <?php if ($filterTerms) : ?>
                        <?php /* A GET form, so a refined view has its own address and
                                 still works with the script switched off. The script
                                 beside this file only saves the second click. */ ?>
                        <form method="get" action="<?php echo esc_url($selfUrl); ?>"
                              data-cadco-archive-filters
                              class="mt-[44px] border-t border-[#7b8c96] pt-[28px] lg:flex lg:justify-end">
                            <label class="block w-full sm:w-[151px]">
                                <span class="sr-only"><?php echo esc_html($filterLabel); ?></span>
                                <select name="psize"
                                        class="cadco-archive-select h-[36px] w-full cursor-pointer appearance-none rounded-[10px] border border-cadco-blue bg-transparent pl-4 pr-9 font-display text-[16px] font-bold leading-none text-cadco-blue focus:outline-none focus-visible:ring-2 focus-visible:ring-cadco-blue">
                                    <option value=""><?php echo esc_html($filterLabel); ?></option>
                                    <?php foreach ($filterTerms as $filterTerm) : ?>
                                        <option value="<?php echo esc_attr($filterTerm->slug); ?>" <?php selected($filterSlug, $filterTerm->slug); ?>>
                                            <?php echo esc_html($filterTerm->name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <noscript>
                                <button type="submit" class="mt-3 h-[36px] rounded-[10px] bg-cadco-blue px-4 font-display text-[16px] font-bold text-white">
                                    <?php esc_html_e('Apply', 'cadco-theme'); ?>
                                </button>
                            </noscript>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php // ---------- Results ---------- ?>
            <div class="<?php echo $showRail ? '' : 'lg:col-span-full'; ?>">

                <?php
                $activeFilter = '';

                if ('' !== $filterSlug) {
                    foreach ($filterTerms as $filterTerm) {
                        if ($filterTerm->slug === $filterSlug) {
                            $activeFilter = $filterTerm->name;
                        }
                    }
                }
                ?>
                <?php /* 12px lower than the rail's name beside it, which is where the frame
                         puts it: the two sit on a shared optical baseline, not a shared top. */ ?>
                <div class="mb-[28px] flex items-baseline justify-between gap-6 font-display text-[16px] leading-[20px] text-true-black lg:mt-3">
                    <p class="m-0 font-bold">
                        <?php echo esc_html($viewName); ?><?php echo '' !== $activeFilter ? ' &mdash; ' . esc_html($activeFilter) : ''; ?>
                    </p>
                    <p class="m-0 shrink-0 text-right">
                        <?php
                        printf(
                            /* translators: %s: number of products found. */
                            esc_html(_n('%s result', '%s results', $total, 'cadco-theme')),
                            esc_html(number_format_i18n($total))
                        );
                        ?>
                    </p>
                </div>

                <?php if ($query->have_posts()) : ?>
                    <ul data-cadco-reveal="items"
                        class="m-0 grid list-none grid-cols-1 gap-x-5 gap-y-[14.8px] p-0 <?php echo esc_attr($gridCols); ?>">
                        <?php while ($query->have_posts()) : $query->the_post(); ?>
                            <?php
                            $id      = get_the_ID();
                            $product = function_exists('wc_get_product') ? wc_get_product($id) : null;
                            $spec    = (string) get_the_excerpt($id);
                            $price   = $product ? (string) $product->get_price_html() : '';
                            ?>
                            <li class="m-0">
                                <?php /* The whole card is the link, as it is in the frame:
                                         one target rather than a name you have to hit. */ ?>
                                <a href="<?php the_permalink(); ?>"
                                   class="group flex h-full min-h-[386px] flex-col rounded-[15px] border-2 border-white bg-white/80 px-[22px] pt-[22px] pb-[41px] no-underline shadow-[0_0_8.9px_0_rgba(0,0,0,0.25)] transition-shadow hover:shadow-[0_2px_18px_0_rgba(0,0,0,0.3)]">

                                    <?php /* Contained, not cropped -- see the note at the top
                                             of this file. The frame's 186x116 slot is kept as a
                                             ratio rather than a fixed 116px: a two-up card is
                                             half as wide again, and a fixed height would strand
                                             the photograph in the middle of it. At the design's
                                             width the two agree to within 2px. */ ?>
                                    <div class="flex aspect-[186/116] w-full items-center justify-center overflow-hidden">
                                        <?php if (has_post_thumbnail($id)) : ?>
                                            <?php echo wp_get_attachment_image(get_post_thumbnail_id($id), 'medium_large', false, [
                                                'class'    => 'h-full w-full object-contain transition-transform duration-500 ease-out will-change-transform group-hover:scale-[1.04] motion-reduce:transition-none motion-reduce:group-hover:scale-100',
                                                'alt'      => the_title_attribute(['echo' => false]),
                                                'loading'  => 'lazy',
                                                'decoding' => 'async',
                                                /* The slot is 186px wide on the design's grid. Stated
                                                   explicitly or the browser reads wp's default `sizes`
                                                   and pulls the 768px file for every card. */
                                                'sizes'    => '(min-width: 1024px) 190px, (min-width: 640px) 44vw, 78vw',
                                            ]); ?>
                                        <?php else : ?>
                                            <img src="<?php echo esc_url(get_theme_file_uri('assets/img/cadco-mark.svg')); ?>"
                                                 class="h-1/2 w-auto opacity-25" alt="" aria-hidden="true"
                                                 loading="lazy" decoding="async" />
                                        <?php endif; ?>
                                    </div>

                                    <h3 class="mt-6 mb-0 font-display text-[16px] font-semibold leading-[20px] text-true-black transition-colors group-hover:text-cadco-blue">
                                        <?php the_title(); ?>
                                    </h3>

                                    <?php if ('' !== $spec) : ?>
                                        <p class="mt-[20px] mb-0 font-display text-[15px] font-normal leading-[27px] text-true-black">
                                            <?php echo esc_html($spec); ?>
                                        </p>
                                    <?php endif; ?>

                                    <?php /* Pushed to the foot so the price sits on one
                                             line across the row however long the names
                                             above it run. */ ?>
                                    <?php if ($showPrice && '' !== $price) : ?>
                                        <p class="mt-auto pt-[22px] mb-0 font-display text-[15px] font-semibold leading-none text-true-black">
                                            <?php echo wp_kses_post($price); ?>
                                        </p>
                                    <?php endif; ?>
                                </a>
                            </li>
                        <?php endwhile; ?>
                        <?php /* Reset before the pagination: the_post() left the last
                                 card as the global post, and the page links are the
                                 page's, not that product's. */ ?>
                        <?php wp_reset_postdata(); ?>
                    </ul>

                    <?php if ($query->max_num_pages > 1) : ?>
                        <nav class="mt-[72px] flex flex-wrap items-center justify-center gap-7 font-display text-[17px]"
                             aria-label="<?php esc_attr_e('Product pages', 'cadco-theme'); ?>">
                            <?php for ($n = 1; $n <= (int) $query->max_num_pages; $n++) : ?>
                                <?php if ($n === $paged) : ?>
                                    <span aria-current="page" class="border-b-2 border-cadco-blue pb-1 font-bold text-cadco-blue"><?php echo (int) $n; ?></span>
                                <?php else : ?>
                                    <a href="<?php echo $viewUrl(['ppage' => $n > 1 ? $n : '']); ?>"
                                       class="pb-1 font-medium text-true-black no-underline hover:text-cadco-blue"><?php echo (int) $n; ?></a>
                                <?php endif; ?>
                            <?php endfor; ?>

                            <?php if ($paged < (int) $query->max_num_pages) : ?>
                                <a href="<?php echo $viewUrl(['ppage' => $paged + 1]); ?>"
                                   class="inline-flex items-center gap-2 font-bold text-true-black no-underline hover:text-cadco-blue">
                                    <?php esc_html_e('Next', 'cadco-theme'); ?>
                                    <svg class="h-4 w-5" viewBox="0 0 22 16" fill="none" aria-hidden="true">
                                        <path d="M1 8h19m0 0-6.5-6.5M20 8l-6.5 6.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                </a>
                            <?php endif; ?>
                        </nav>
                    <?php endif; ?>

                <?php else : ?>
                    <p class="py-16 text-center font-display text-[19px] text-[#4a5a63]">
                        <?php echo '' !== $filterSlug
                            ? esc_html__('Nothing in this category matches that refinement.', 'cadco-theme')
                            : esc_html__('There are no products in this category yet.', 'cadco-theme'); ?>
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>
