<?php
/**
 * Cadco related products.
 *
 * Figma "Product Individual Page" (1440 frame): a heading over a row of the
 * catalogue's own product cards -- 234 x 386 with a 20px gap -- and the same
 * two 48px arrows as the video strip.
 *
 * The card is inc/cadco-products.php's, shared with the catalogue grid. Two
 * rows drawing the same card from two copies of the markup is how they drift
 * apart.
 *
 * The row is a scroll container, not a slider: a trackpad, a swipe and the
 * keyboard drive it before any script runs, and assets/js/cadco-rail.js only
 * adds the arrows.
 *
 * @var array         $attributes
 * @var WP_Block|null $block  Null in the editor preview.
 */

$heading   = (string) ($attributes['heading'] ?? 'Related Products');
$limit     = max(2, min(24, (int) ($attributes['limit'] ?? 12)));
$scope     = (string) ($attributes['scope'] ?? 'deepest');
$showPrice = (bool) ($attributes['showPrice'] ?? true);

// $block is null in the editor preview.
$is_preview = ! isset($block) || $block === null;

// Literal, so the Tailwind scanner sees it.
$surface = ($attributes['surface'] ?? 'paper') === 'tint' ? 'bg-[#eef2f6]' : 'bg-paper';

if (! post_type_exists('product') || ! taxonomy_exists('product_cat')) {
    return;
}

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

if (! $productId) {
    return;
}

/* -------------------------------------------------------------------------
   Which products count as related.

   The categories are ordered deepest first, and the query widens through them
   until the row is full. A sub-category with three products in it is a better
   first answer than the whole catalogue, but a row of three in a space for six
   reads as a mistake -- so it is the starting point, not the only one.
   ------------------------------------------------------------------------- */
$terms = get_the_terms($productId, 'product_cat');
$terms = (! $terms || is_wp_error($terms)) ? [] : $terms;

usort($terms, static function (WP_Term $a, WP_Term $b): int {
    return count(get_ancestors($b->term_id, 'product_cat', 'taxonomy'))
         - count(get_ancestors($a->term_id, 'product_cat', 'taxonomy'));
});

if ('top' === $scope) {
    $terms = array_values(array_filter($terms, static fn (WP_Term $t): bool => 0 === (int) $t->parent))
        ?: $terms;
}

/* Products the shop manager has taken out of the catalogue stay out of it.
   Built once, and only when the taxonomy exists -- an empty clause in a
   tax_query is not ignored, it matches nothing. */
$hidden = taxonomy_exists('product_visibility') ? [
    'taxonomy' => 'product_visibility',
    'field'    => 'name',
    'terms'    => 'exclude-from-catalog',
    'operator' => 'NOT IN',
] : null;

$found = [];
$seen  = [$productId => true];

foreach ($terms as $term) {
    if (count($found) >= $limit) {
        break;
    }

    $ids = get_posts([
        'post_type'        => 'product',
        'post_status'      => 'publish',
        'posts_per_page'   => $limit + 1,
        'fields'           => 'ids',
        'orderby'          => 'menu_order title',
        'order'            => 'ASC',
        'post__not_in'     => array_keys($seen),
        'ignore_sticky_posts' => true,
        'tax_query'        => array_values(array_filter([
            [
                'taxonomy'         => 'product_cat',
                'field'            => 'term_id',
                'terms'            => (int) $term->term_id,
                'include_children' => true,
            ],
            $hidden,
        ])),
    ]);

    foreach ($ids as $id) {
        if (count($found) >= $limit) {
            break;
        }

        $seen[(int) $id] = true;
        $found[]         = (int) $id;
    }

    /* Only the first (most specific, or the top-level one) category is tried
       when the row is meant to stay inside it. The other modes keep widening
       until the row is full. */
    if ('top' === $scope) {
        break;
    }
}

if (! $found) {
    return;
}

$reveal = $is_preview ? '' : 'data-proto-animate="manual" data-cadco-reveal-group';

$wrapper = get_block_wrapper_attributes([
    'class' => 'cadco-related-products w-full ' . $surface . ' pt-[92px] pb-[110px]',
]);
?>
<section <?php echo $wrapper; ?> <?php echo $reveal; ?>>
    <div class="mx-auto w-full max-w-[1440px] px-6 lg:px-10">

        <h2 data-cadco-reveal="lines"
            class="m-0 font-display text-[28px] font-extrabold leading-[1.18] text-true-black md:text-h2">
            <?php echo esc_html($heading); ?>
        </h2>

        <div data-cadco-rail data-cadco-reveal="rise" class="cadco-rail mt-10 lg:mt-[62px]">
            <?php /* The cards carry their own shadow, so the track is padded rather
                     than letting it be clipped at the scroll edges. */ ?>
            <ul class="cadco-rail__track m-0 flex list-none snap-x snap-mandatory gap-5 overflow-x-auto p-0 px-[3px] py-[6px]"
                data-cadco-rail-track tabindex="0"
                aria-label="<?php esc_attr_e('Related products', 'cadco-theme'); ?>">
                <?php foreach ($found as $id) : ?>
                    <li class="m-0 w-[234px] shrink-0 snap-start">
                        <?php echo cadco_product_card_html($id, ['showPrice' => $showPrice]); ?>
                    </li>
                <?php endforeach; ?>
            </ul>

            <?php
            $arrow = static function (string $dir, string $label): void {
                $filled = 'next' === $dir;
                ?>
                <button type="button" data-cadco-rail-<?php echo esc_attr($dir); ?> disabled
                        aria-label="<?php echo esc_attr($label); ?>"
                        class="flex h-12 w-12 items-center justify-center rounded-full border border-[#4a6a84] transition-colors disabled:cursor-default disabled:opacity-35 <?php echo $filled
                            ? 'bg-[#4a6a84] text-white hover:enabled:bg-[#3b5568]'
                            : 'bg-transparent text-[#4a6a84] hover:enabled:bg-[#4a6a84] hover:enabled:text-white'; ?>">
                    <svg class="h-4 w-6" viewBox="0 0 24 16" fill="none" stroke="currentColor"
                         stroke-width="1" stroke-linecap="round" stroke-linejoin="round"
                         aria-hidden="true" focusable="false">
                        <?php if ($filled) : ?>
                            <path d="M1 8h22m0 0-4-4m4 4-4 4" />
                        <?php else : ?>
                            <path d="M23 8H1m0 0 4-4M1 8l4 4" />
                        <?php endif; ?>
                    </svg>
                </button>
                <?php
            };
            ?>
            <div class="mt-[34px] flex gap-5">
                <?php $arrow('prev', __('Previous products', 'cadco-theme')); ?>
                <?php $arrow('next', __('More products', 'cadco-theme')); ?>
            </div>
        </div>
    </div>
</section>
