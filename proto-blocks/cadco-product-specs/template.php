<?php
/**
 * Cadco product specifications.
 *
 * Figma "Product Individual Page" (1440 frame). Measurements taken from the
 * design file:
 *
 *   band       #e8edf4, full bleed        tag       h 34.3, r10, #4a6a84
 *   heading    Barlow ExtraBold 36        tag text  Barlow Bold 16, white
 *   spec label Barlow Bold 16             warranty  360 x 142, r15, #dce2ec
 *   spec value Barlow Regular 16/37       shield    40 x 50
 *   columns    left from 162, right 721   buttons   151 x 36, r10, 1px #00476e
 *
 * The groups -- Size, Power, Freight Class -- are the design's, not the
 * catalogue's: the source data is one flat list of label/value pairs. Which
 * label belongs under which tag is stated once in inc/cadco-products.php, and
 * anything no group claims falls into a trailing Details group rather than
 * being quietly dropped.
 *
 * @var array         $attributes
 * @var WP_Block|null $block  Null in the editor preview.
 */

$heading       = (string) ($attributes['heading'] ?? 'Specifications');
$featuresLabel = (string) ($attributes['featuresLabel'] ?? 'Features');
$showWarranty  = (bool) ($attributes['showWarranty'] ?? true);
$warrantyText  = (string) ($attributes['warrantyText'] ?? '');
$warrantyLink  = (string) ($attributes['warrantyLink'] ?? '');
$showDownloads = (bool) ($attributes['showDownloads'] ?? true);
$sheetLabel    = (string) ($attributes['specSheetLabel'] ?? 'Spec Sheet');
$manualLabel   = (string) ($attributes['manualLabel'] ?? 'Manual');

// $block is null in the editor preview.
$is_preview = ! isset($block) || $block === null;

if (! post_type_exists('product')) {
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
    // Same stand-in as the hero: an empty band teaches an editor nothing.
    $recent    = get_posts(['post_type' => 'product', 'posts_per_page' => 1, 'fields' => 'ids']);
    $productId = $recent ? (int) $recent[0] : 0;
}

if (! $productId) {
    return;
}

$groups   = cadco_product_grouped_specs($productId);
$features = cadco_product_features($productId);

$sheet  = $showDownloads ? cadco_product_resources($productId, ['spec-sheet'], 1) : [];
$manual = $showDownloads ? cadco_product_resources($productId, ['manual'], 1) : [];

/* Nothing to say: no specifications, no features, no downloads. Rendering the
   band anyway would put a heading over an empty tinted rectangle. */
if (! $groups && ! $features && ! $sheet && ! $manual && ! $showWarranty) {
    return;
}

$destination = static function (WP_Post $resource): string {
    return function_exists('cadco_resource_destination')
        ? (string) cadco_resource_destination($resource->ID)
        : (string) get_post_meta($resource->ID, 'cadco_resource_url', true);
};

$reveal = $is_preview ? '' : 'data-proto-animate="manual" data-cadco-reveal-group';

$wrapper = get_block_wrapper_attributes([
    'class' => 'cadco-product-specs w-full bg-[#e8edf4] pt-[86px] pb-[100px]',
]);
?>
<section <?php echo $wrapper; ?> <?php echo $reveal; ?>>
    <div class="mx-auto w-full max-w-[1440px] px-6 lg:px-10">

        <h2 data-cadco-reveal="lines"
            class="m-0 font-display text-[28px] font-extrabold leading-[1.18] text-true-black md:text-h2">
            <?php echo esc_html($heading); ?>
        </h2>

        <div class="mt-10 grid grid-cols-1 gap-x-[62px] gap-y-12 lg:mt-[71px] lg:grid-cols-2">

            <?php // ---------- Grouped specifications ---------- ?>
            <div data-cadco-reveal="rise" class="flex flex-col gap-[42px]">
                <?php foreach ($groups as $group) : ?>
                    <div>
                        <?php /* A tag, not a heading of its own: the design labels the
                                 group rather than titling a section, and the band
                                 already has one heading above all of it. */ ?>
                        <p class="m-0 inline-flex h-[34px] items-center rounded-[10px] bg-[#4a6a84] px-[19px] font-display text-[16px] font-bold leading-none text-white">
                            <?php echo esc_html($group['label']); ?>
                        </p>

                        <?php if ($group['rows']) : ?>
                            <dl class="m-0 mt-4 font-display text-[16px] leading-[28px] text-true-black lg:leading-[37px]">
                                <?php foreach ($group['rows'] as $label => $value) : ?>
                                    <div class="m-0">
                                        <dt class="inline font-bold"><?php echo esc_html($label); ?></dt>
                                        <dd class="m-0 inline"><?php echo esc_html($value); ?></dd>
                                    </div>
                                <?php endforeach; ?>
                            </dl>
                        <?php endif; ?>

                        <?php if ('' !== $group['text']) : ?>
                            <p class="m-0 mt-4 max-w-[394px] font-display text-[16px] leading-[28px] text-true-black lg:leading-[37px]">
                                <?php echo esc_html($group['text']); ?>
                            </p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php // ---------- Features, warranty, downloads ---------- ?>
            <div data-cadco-reveal="rise" class="flex flex-col gap-[42px]">
                <?php if ($features) : ?>
                    <div>
                        <p class="m-0 inline-flex h-[34px] items-center rounded-[10px] bg-[#4a6a84] px-[19px] font-display text-[16px] font-bold leading-none text-white">
                            <?php echo esc_html($featuresLabel); ?>
                        </p>

                        <ul class="m-0 mt-4 list-none p-0 font-display text-[16px] leading-[28px] text-true-black lg:leading-[37px]">
                            <?php foreach ($features as $feature) : ?>
                                <li class="m-0"><?php echo esc_html($feature); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php if ($showWarranty && '' !== trim($warrantyText)) : ?>
                    <?php
                    $warrantyInner = '<span class="shrink-0">'
                        . '<img src="' . esc_url(get_theme_file_uri('assets/img/cadco-warranty-shield.svg')) . '"'
                        . ' width="40" height="50" class="h-[50px] w-10" alt="" aria-hidden="true" loading="lazy" decoding="async" />'
                        . '</span>'
                        . '<span class="font-display text-[16px] font-bold leading-[24px] text-true-black">'
                        . esc_html($warrantyText) . '</span>';
                    ?>
                    <?php if ('' !== trim($warrantyLink)) : ?>
                        <a href="<?php echo esc_url($warrantyLink); ?>"
                           class="flex max-w-[361px] items-center gap-5 rounded-[15px] bg-[#dce2ec] px-[28px] py-[22px] no-underline transition-colors hover:bg-[#cfd8e6]">
                            <?php echo $warrantyInner; ?>
                        </a>
                    <?php else : ?>
                        <div class="flex max-w-[361px] items-center gap-5 rounded-[15px] bg-[#dce2ec] px-[28px] py-[22px]">
                            <?php echo $warrantyInner; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($sheet || $manual) : ?>
                    <div class="flex flex-wrap gap-[11px]">
                        <?php foreach ([[$sheet, $sheetLabel], [$manual, $manualLabel]] as [$found, $label]) : ?>
                            <?php if (! $found) : ?>
                                <?php continue; ?>
                            <?php endif; ?>
                            <?php $url = $destination($found[0]); ?>
                            <?php if ('' === $url) : ?>
                                <?php continue; ?>
                            <?php endif; ?>
                            <a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener"
                               class="inline-flex h-[36px] min-w-[151px] items-center justify-center gap-3 rounded-[10px] border border-cadco-blue px-4 font-display text-[16px] font-bold leading-none text-cadco-blue no-underline transition-colors hover:bg-cadco-blue hover:text-white">
                                <?php echo esc_html($label); ?>
                                <?php echo cadco_download_icon('h-[15px] w-[15px] shrink-0'); ?>
                                <span class="sr-only"><?php esc_html_e('(opens the document in a new tab)', 'cadco-theme'); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>
