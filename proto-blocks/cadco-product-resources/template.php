<?php
/**
 * Cadco product resources.
 *
 * Figma "Product Individual Page" (1440 frame). Measurements taken from the
 * design file:
 *
 *   heading   Barlow ExtraBold 36          card      476 x 92, r10, 1px #e8edf4
 *   cards     two up, 476 wide, 16 gaps    card name Barlow SemiBold 18
 *   video     455 x 321, r15               card kind Barlow Regular 16
 *   play      130, centred                 shadow    0 0 18.7px rgba(0,0,0,.1)
 *   arrows    48 circles, 20 apart         caption   Barlow Regular 16
 *
 * The resources are the ones tagged to this product through `resource_product`
 * -- the same taxonomy the resource centre filters on, so a document added
 * there appears here with no second place to maintain.
 *
 * The video strip is a scroll container, not a slider: it already works with a
 * trackpad, a swipe and the keyboard before any script runs, and
 * assets/js/cadco-rail.js only adds the two arrows the design draws. The
 * dialog the stills open is the resource centre's, shared rather than copied
 * (assets/js/cadco-video-modal.js).
 *
 * @var array         $attributes
 * @var WP_Block|null $block  Null in the editor preview.
 */

$heading   = (string) ($attributes['heading'] ?? 'Resources & Downloads');
$showDocs  = (bool) ($attributes['showDocuments'] ?? true);
$showVids  = (bool) ($attributes['showVideos'] ?? true);
$docLimit  = max(2, min(40, (int) ($attributes['documentLimit'] ?? 12)));
$showRule  = (bool) ($attributes['showDivider'] ?? true);

// $block is null in the editor preview.
$is_preview = ! isset($block) || $block === null;

if (! post_type_exists('product') || ! post_type_exists('resource')) {
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
    /* On the canvas, the most recent product that actually has resources --
       the most recent product outright would often show an empty section and
       teach an editor nothing about what this block does. */
    foreach (get_posts(['post_type' => 'product', 'posts_per_page' => 20, 'fields' => 'ids']) as $candidate) {
        if (cadco_product_resources((int) $candidate, [], 1)) {
            $productId = (int) $candidate;
            break;
        }
    }
}

if (! $productId) {
    return;
}

$all = cadco_product_resources($productId);

/* Split by what each one DOES, not by its media type: the two answer different
   questions and come apart -- a guide is a PDF on one row and a video on the
   next. A resource with no kind recorded falls in with the documents, which is
   the safe side: it opens in a tab rather than into an empty player. */
$documents = [];
$videos    = [];

foreach ($all as $resource) {
    $kind = (string) get_post_meta($resource->ID, 'cadco_resource_link_type', true);

    if ('video' === $kind) {
        $videos[] = $resource;
        continue;
    }

    $documents[] = $resource;
}

if (! $showDocs) {
    $documents = [];
}

if (! $showVids) {
    $videos = [];
}

$documents = array_slice($documents, 0, $docLimit);

if (! $documents && ! $videos) {
    return;
}

$cols = (string) ($attributes['columns'] ?? '2');
// Literal, so the Tailwind scanner sees them.
$gridCols = '1' === $cols ? '' : ('3' === $cols ? 'md:grid-cols-2 xl:grid-cols-3' : 'md:grid-cols-2');

$destination = static function (WP_Post $resource): string {
    return function_exists('cadco_resource_destination')
        ? (string) cadco_resource_destination($resource->ID)
        : (string) get_post_meta($resource->ID, 'cadco_resource_url', true);
};

/**
 * The embed form of a video URL.
 *
 * Whoever adds the resource pastes what the address bar gave them -- a watch
 * link, a share link, sometimes already an embed link -- and only the embed
 * form loads in a frame.
 */
$embedUrl = static function (string $url): string {
    if (preg_match('~youtube\.com/embed/([A-Za-z0-9_-]+)~', $url, $m)
        || preg_match('~youtube\.com/watch\?v=([A-Za-z0-9_-]+)~', $url, $m)
        || preg_match('~youtu\.be/([A-Za-z0-9_-]+)~', $url, $m)) {
        return 'https://www.youtube.com/embed/' . $m[1] . '?rel=0';
    }

    if (preg_match('~vimeo\.com/(?:video/)?(\d+)~', $url, $m)) {
        return 'https://player.vimeo.com/video/' . $m[1];
    }

    return '';
};

$mediaName = static function (WP_Post $resource): string {
    $terms = get_the_terms($resource->ID, 'resource_media');

    return (! is_wp_error($terms) && $terms) ? (string) $terms[0]->name : '';
};

$reveal = $is_preview ? '' : 'data-proto-animate="manual" data-cadco-reveal-group';

$wrapper = get_block_wrapper_attributes([
    'class' => 'cadco-product-resources w-full bg-paper pt-[100px] pb-[92px]',
]);
?>
<section <?php echo $wrapper; ?> <?php echo $reveal; ?>>
    <div class="mx-auto w-full max-w-[1440px] px-6 lg:px-10">

        <h2 data-cadco-reveal="lines"
            class="m-0 font-display text-[28px] font-extrabold leading-[1.18] text-true-black md:text-h2">
            <?php echo esc_html($heading); ?>
        </h2>

        <?php // ---------- Documents ---------- ?>
        <?php if ($documents) : ?>
            <ul data-cadco-reveal="items"
                class="m-0 mt-10 grid list-none grid-cols-1 gap-x-[18px] gap-y-4 p-0 lg:mt-[56px] <?php echo esc_attr($gridCols); ?>">
                <?php foreach ($documents as $resource) : ?>
                    <?php
                    $url  = $destination($resource);
                    $kind = (string) get_post_meta($resource->ID, 'cadco_resource_link_type', true);
                    $away = '' !== $url && '#' !== $url;
                    $href = $away ? $url : (string) get_permalink($resource->ID);
                    ?>
                    <li class="m-0">
                        <a href="<?php echo esc_url($href); ?>"
                           <?php echo $away ? 'target="_blank" rel="noopener noreferrer"' : ''; ?>
                           class="group flex h-[92px] items-center gap-4 rounded-[10px] border border-[#e8edf4] bg-paper px-[22px] no-underline shadow-[0_0_18.7px_0_rgba(0,0,0,0.1)] transition-shadow hover:shadow-[0_2px_22px_0_rgba(0,0,0,0.16)]">
                            <span class="min-w-0 flex-1">
                                <span class="block truncate font-display text-[18px] font-semibold leading-[22px] text-true-black transition-colors group-hover:text-cadco-blue">
                                    <?php echo esc_html(get_the_title($resource->ID)); ?>
                                </span>
                                <?php $media = $mediaName($resource); ?>
                                <?php if ('' !== $media) : ?>
                                    <span class="mt-1 block font-display text-[16px] leading-[20px] text-true-black">
                                        <?php echo esc_html($media); ?>
                                    </span>
                                <?php endif; ?>
                            </span>

                            <span class="shrink-0 text-true-black transition-colors group-hover:text-cadco-blue">
                                <?php echo cadco_download_icon('h-[15px] w-[15px]'); ?>
                            </span>

                            <?php if ($away) : ?>
                                <span class="sr-only"><?php
                                    echo 'file' === $kind
                                        ? esc_html__('(opens the document in a new tab)', 'cadco-theme')
                                        : esc_html__('(opens another site in a new tab)', 'cadco-theme');
                                ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php // ---------- Videos ---------- ?>
        <?php if ($videos) : ?>
            <div data-cadco-rail data-cadco-reveal="rise" class="cadco-rail mt-[60px]">
                <h3 class="sr-only"><?php esc_html_e('Videos', 'cadco-theme'); ?></h3>

                <?php /* A scroll container with snapping: it works before the script
                         does, and the arrows below only drive it. The negative
                         margins let the strip run to the viewport edge on a phone
                         while its first card still lines up with the column. */ ?>
                <ul class="cadco-rail__track m-0 flex list-none snap-x snap-mandatory gap-[29px] overflow-x-auto p-0 pb-2"
                    data-cadco-rail-track tabindex="0"
                    aria-label="<?php esc_attr_e('Product videos', 'cadco-theme'); ?>">
                    <?php foreach ($videos as $resource) : ?>
                        <?php
                        $url   = $destination($resource);
                        $embed = $embedUrl($url);
                        $title = (string) get_the_title($resource->ID);
                        ?>
                        <li class="m-0 w-[300px] shrink-0 snap-start sm:w-[380px] lg:w-[455px]">
                            <a href="<?php echo esc_url('' !== $url ? $url : (string) get_permalink($resource->ID)); ?>"
                               <?php if ('' !== $embed) : ?>
                                   data-cadco-resource-video="<?php echo esc_url($embed); ?>"
                                   data-cadco-resource-title="<?php echo esc_attr($title); ?>"
                               <?php else : ?>
                                   target="_blank" rel="noopener noreferrer"
                               <?php endif; ?>
                               class="group block no-underline">

                                <span class="relative block aspect-[455/321] w-full overflow-hidden rounded-[15px] bg-[#e8edf4]">
                                    <?php if (has_post_thumbnail($resource->ID)) : ?>
                                        <?php echo get_the_post_thumbnail($resource->ID, 'large', [
                                            'class'    => 'h-full w-full object-cover transition-transform duration-500 ease-out group-hover:scale-[1.03] motion-reduce:transition-none motion-reduce:group-hover:scale-100',
                                            'alt'      => '',
                                            'loading'  => 'lazy',
                                            'decoding' => 'async',
                                        ]); ?>
                                    <?php endif; ?>

                                    <?php /* Lucide circle-play at the frame's 130px, white
                                             over the still. */ ?>
                                    <span class="absolute inset-0 flex items-center justify-center">
                                        <svg class="h-[72px] w-[72px] text-white drop-shadow-[0_2px_10px_rgba(0,0,0,0.45)] transition-transform duration-300 group-hover:scale-110 motion-reduce:transition-none motion-reduce:group-hover:scale-100 lg:h-[130px] lg:w-[130px]"
                                             viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                             stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"
                                             aria-hidden="true" focusable="false">
                                            <circle cx="12" cy="12" r="10" />
                                            <path d="m10 8 6 4-6 4V8z" fill="currentColor" stroke="none" />
                                        </svg>
                                    </span>
                                </span>

                                <span class="mt-[30px] block font-display text-[16px] leading-[22px] text-true-black transition-colors group-hover:text-cadco-blue">
                                    <?php echo esc_html($title); ?>
                                </span>

                                <?php if ('' !== $embed) : ?>
                                    <span class="sr-only"><?php esc_html_e('(plays in a dialog on this page)', 'cadco-theme'); ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <?php
                /* Rendered disabled: a rail that fits on screen never offers a
                   control that would do nothing, and one whose script never
                   loads does not offer a dead one either. */
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
                    <?php $arrow('prev', __('Previous videos', 'cadco-theme')); ?>
                    <?php $arrow('next', __('More videos', 'cadco-theme')); ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($showRule) : ?>
            <hr class="mt-[88px] h-[6px] w-full border-0 bg-[#e8edf4]" />
        <?php endif; ?>
    </div>

    <?php /* One dialog for the strip rather than one per still: only one video can
             play at a time, and a <dialog> gives the focus trap, the Escape key
             and the inert background for free. The frame is written only when a
             still is opened, so no card costs a YouTube request until someone
             asks for it. */ ?>
    <?php if ($videos && ! $is_preview) : ?>
        <dialog data-cadco-video-modal
                class="w-[min(1100px,92vw)] rounded-[16px] border-0 bg-true-black p-0 backdrop:bg-black/70"
                aria-labelledby="cadco-video-modal-title">
            <div class="flex items-center justify-between gap-4 px-5 py-4">
                <h2 id="cadco-video-modal-title" class="m-0 font-display text-[18px] font-bold text-white"></h2>
                <button type="button" data-cadco-video-close
                        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-white transition-colors hover:bg-white/10"
                        aria-label="<?php esc_attr_e('Close the video', 'cadco-theme'); ?>">
                    <svg class="h-5 w-5" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                        <path d="m4 4 12 12M16 4 4 16" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                    </svg>
                </button>
            </div>
            <div class="aspect-video w-full bg-black" data-cadco-video-frame></div>
        </dialog>
    <?php endif; ?>
</section>
