<?php
/**
 * Cadco product children.
 *
 * The products that name this one as their Parent Product — CADCO's
 * accessories: a steam pan holder sold for four sizes of warming table, a drip
 * tray for six. The same rail as Related Products, which sits below it, drawn
 * with the catalogue's own card so the two rows cannot drift apart.
 *
 * Whether this row exists at all is a site-wide decision, not a per-page one,
 * so it is read from Products → Display Settings rather than from a block
 * control. With the other option chosen the children are added to the Related
 * Products row instead and this block renders nothing.
 *
 * @var array         $attributes
 * @var WP_Block|null $block  Null in the editor preview.
 */

$limit     = max(2, min(48, (int) ($attributes['limit'] ?? 24)));
$showPrice = (bool) ($attributes['showPrice'] ?? true);

// $block is null in the editor preview.
$is_preview = ! isset($block) || $block === null;

// Literal, so the Tailwind scanner sees it.
$surface = ($attributes['surface'] ?? 'tint') === 'paper' ? 'bg-paper' : 'bg-[#eef2f6]';

if (! post_type_exists('product') || ! function_exists('cadco_product_children')) {
    return;
}

$settingsOn = class_exists('CADCO_Display_Settings')
    && CADCO_Display_Settings::children_placement() === CADCO_Display_Settings::PLACEMENT_SECTION;

/* A block that draws nothing is indistinguishable from a broken one, so in the
   editor it says why instead of disappearing. On the front end it simply does
   not render. */
if (! $settingsOn && ! $is_preview) {
    return;
}

$productId = 0;

if (! $is_preview) {
    $queried = get_queried_object();

    if ($queried instanceof WP_Post && 'product' === $queried->post_type) {
        $productId = (int) $queried->ID;
    }
}

/* The canvas has no product to be on, so it borrows one that has children —
   an empty row would otherwise be the editor's only preview of this block. */
if (! $productId && $is_preview) {
    $withChildren = get_posts([
        'post_type'      => 'product',
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'meta_key'       => '_cadco_related_children',
        'meta_compare'   => 'EXISTS',
    ]);

    $productId = $withChildren ? (int) $withChildren[0] : 0;
}

if (! $productId) {
    return;
}

$children = array_slice(cadco_product_children($productId), 0, $limit);

if (! $children) {
    if ($is_preview) {
        $heading = class_exists('CADCO_Display_Settings')
            ? CADCO_Display_Settings::children_heading()
            : __('Accessories', 'cadco-theme');
        ?>
        <div class="cadco-product-children mx-auto w-full max-w-[1440px] px-6 py-10 lg:px-10">
            <p class="m-0 rounded border border-dashed border-[#9fb3c4] bg-[#f5f8fa] p-5 text-center text-[14px] leading-relaxed text-[#44525f]">
                <strong><?php echo esc_html($heading); ?></strong><br>
                <?php esc_html_e('No product names this one as its Parent Product, so this row is empty. It fills in once products carrying a Parent Product are imported.', 'cadco-theme'); ?>
            </p>
        </div>
        <?php
    }

    return;
}

$heading = class_exists('CADCO_Display_Settings')
    ? CADCO_Display_Settings::children_heading()
    : __('Accessories', 'cadco-theme');

$reveal = $is_preview ? '' : 'data-proto-animate="manual" data-cadco-reveal-group';

/* The same band as the related-products rail below it, so the two rows are
   the same control drawn twice rather than two near-misses. */
$wrapper = get_block_wrapper_attributes([
    'class' => 'cadco-product-children w-full ' . $surface . ' pt-[95px] pb-[109px]',
]);
?>
<section <?php echo $wrapper; ?> <?php echo $reveal; ?>>
    <div class="mx-auto w-full max-w-[1440px] px-6 lg:px-10">

        <?php if ($is_preview && ! $settingsOn) : ?>
            <p class="m-0 mb-6 rounded border border-dashed border-[#9fb3c4] bg-[#f5f8fa] p-4 text-[13px] leading-relaxed text-[#44525f]">
                <?php esc_html_e('Products → Display Settings is currently adding these to the Related Products row, so this block does not render on the site. Shown here so the layout can still be seen.', 'cadco-theme'); ?>
            </p>
        <?php endif; ?>

        <h2 data-cadco-reveal="lines"
            class="m-0 font-display text-[28px] font-extrabold leading-[1.18] text-true-black md:text-h2">
            <?php echo esc_html($heading); ?>
        </h2>

        <div data-cadco-rail data-cadco-reveal="rise" class="cadco-rail mt-10 lg:mt-[62px]">
            <?php /* The cards carry their own shadow, so the track is padded rather
                     than letting it be clipped at the scroll edges. */ ?>
            <ul class="cadco-rail__track m-0 flex list-none snap-x snap-mandatory gap-5 overflow-x-auto p-0 px-[3px] py-[6px]"
                data-cadco-rail-track tabindex="0"
                aria-label="<?php echo esc_attr($heading); ?>">
                <?php foreach ($children as $id) : ?>
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
            <div class="mt-[56px] flex gap-5">
                <?php $arrow('prev', __('Previous products', 'cadco-theme')); ?>
                <?php $arrow('next', __('More products', 'cadco-theme')); ?>
            </div>
        </div>
    </div>
</section>
