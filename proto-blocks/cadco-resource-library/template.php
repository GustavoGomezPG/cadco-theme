<?php
/**
 * Cadco Resource Library.
 *
 * Filters, a grid of resource cards, and pagination.
 *
 * The filtering is done on the server from query parameters rather than in the
 * browser from a rendered list. A library is paginated, so the browser only
 * ever holds one page of it and could not filter the rest; doing it in the
 * query also means every filtered view has its own URL that can be linked,
 * shared and indexed, and that the page still works with no JavaScript. The
 * script beside this file only submits the form when a select changes, which is
 * a convenience over the button, not the mechanism.
 *
 * @var array         $attributes
 * @var WP_Block|null $block  Null in the editor preview.
 */

$footnote     = (string) ($attributes['footnote'] ?? '');
$perPage      = max(1, (int) ($attributes['perPage'] ?? 12));
$showFilters  = (bool) ($attributes['showFilters'] ?? true);
$mediaLabel   = (string) ($attributes['mediaLabel'] ?? 'Show All Media');
$productLabel = (string) ($attributes['productLabel'] ?? 'Show All Products');

// $block is null in the editor preview.
$is_preview = ! isset($block) || $block === null;

/* Literal classes so Tailwind's scanner sees them: it cannot generate a class
   from a value computed in PHP. */
$surface = ($attributes['surface'] ?? 'paper') === 'tint' ? 'bg-[#eef2f6]' : 'bg-paper';

$cols = (string) ($attributes['columns'] ?? '3');
$gridCols = '2' === $cols ? 'sm:grid-cols-2'
    : ('4' === $cols ? 'sm:grid-cols-2 lg:grid-cols-4' : 'sm:grid-cols-2 lg:grid-cols-3');

/* The editor preview must not read the visitor's query string: it has none, and
   a preview that paginated would be showing the editor's own URL. */
/* The parameters are namespaced because the obvious names are taken. WordPress
   resolves ?product= against WooCommerce's product post type, so that URL stops
   being this page at all and the block never renders; ?s= is core search, which
   would search the whole site rather than this library. */
$selMedia   = $is_preview ? '' : sanitize_title((string) ($_GET['rmedia'] ?? ''));
$selProduct = $is_preview ? '' : sanitize_title((string) ($_GET['rproduct'] ?? ''));
$search     = $is_preview ? '' : sanitize_text_field((string) ($_GET['rs'] ?? ''));
$paged      = $is_preview ? 1 : max(1, (int) ($_GET['rpage'] ?? 1));

$taxQuery = [];

if ('' !== $selMedia) {
    $taxQuery[] = ['taxonomy' => 'resource_media', 'field' => 'slug', 'terms' => $selMedia];
}

if ('' !== $selProduct) {
    $taxQuery[] = ['taxonomy' => 'resource_product', 'field' => 'slug', 'terms' => $selProduct];
}

if (count($taxQuery) > 1) {
    $taxQuery['relation'] = 'AND';
}

$query = new WP_Query([
    'post_type'      => 'resource',
    'post_status'    => 'publish',
    'posts_per_page' => $perPage,
    'paged'          => $paged,
    'orderby'        => 'date',
    'order'          => 'DESC',
    's'              => $search !== '' ? $search : null,
    'tax_query'      => $taxQuery ?: null,
]);

/* The page's own address, taken before the loop runs.
   get_permalink() with no argument answers for whatever the global post is, and
   the_post() repoints that at each resource in turn. Read inside the pagination
   -- which renders after the loop -- it returned the last resource, so every
   page link pointed at /resources/<last-card>/?rpage=2 instead of at this page.
   Resolved once here, where the global post is still the page. */
$selfUrl = (string) get_permalink(get_queried_object_id());

if ('' === (string) $selfUrl) {
    $selfUrl = home_url(add_query_arg([], $GLOBALS['wp']->request ?? ''));
}

$mediaTerms   = get_terms(['taxonomy' => 'resource_media', 'hide_empty' => true]);
$productTerms = get_terms(['taxonomy' => 'resource_product', 'hide_empty' => true]);
$mediaTerms   = is_wp_error($mediaTerms) ? [] : $mediaTerms;
$productTerms = is_wp_error($productTerms) ? [] : $productTerms;

/* Page links keep whatever the reader already chose, so paging does not quietly
   drop their filters. */
$pageUrl = static function (int $n) use ($selMedia, $selProduct, $search, &$selfUrl): string {
    $args = array_filter([
        'rmedia'   => $selMedia,
        'rproduct' => $selProduct,
        'rs'      => $search,
        'rpage'   => $n > 1 ? $n : '',
    ], static fn ($v): bool => '' !== $v && null !== $v);

    return esc_url(add_query_arg($args, $selfUrl));
};

/**
 * The embed form of a video URL.
 *
 * The client will paste whatever the address bar gave them -- a watch link, a
 * share link, sometimes already an embed link -- and only the embed form loads
 * in a frame, so all three are accepted and normalised here rather than asking
 * anyone to convert by hand. Anything unrecognised is returned untouched, which
 * makes it open in a new tab instead of silently failing in the modal.
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

$reveal = $is_preview ? '' : 'data-proto-animate="manual" data-cadco-reveal-group';

$wrapper = get_block_wrapper_attributes([
    'class' => 'cadco-resource-library w-full ' . $surface . ' pt-[108px] pb-[120px]',
]);
?>
<section <?php echo $wrapper; ?> <?php echo $reveal; ?>>
    <div class="mx-auto w-full max-w-[1140px] px-6">

        <?php /* The frame gives this section no visible heading, so the page ran
                 from the hero's h1 straight to the cards' h3 and skipped a level.
                 Someone moving by headings would hear the library announced as a
                 sub-part of nothing. Named here for them, and hidden from sight
                 so the frame is unchanged. */ ?>
        <h2 class="sr-only"><?php esc_html_e('Resource library', 'cadco-theme'); ?></h2>

        <?php if ($showFilters) : ?>
            <?php /* A GET form: the filtered view is a URL, so it can be linked and
                     shared, and it still works with the script switched off. */ ?>
            <form method="get" action="<?php echo esc_url($selfUrl); ?>"
                  data-cadco-resource-filters
                  class="mb-[78px] flex flex-col gap-6 md:flex-row md:items-end md:gap-[54px]">

                <label class="flex w-full flex-col gap-1 md:w-[214px]">
                    <span class="sr-only"><?php echo esc_html($mediaLabel); ?></span>
                    <select name="rmedia"
                            class="min-h-[44px] w-full cursor-pointer appearance-none border-0 border-b border-cadco-blue bg-transparent pb-3 pr-6 font-display text-[20px] font-medium text-cadco-blue focus:outline-none focus-visible:ring-2 focus-visible:ring-cadco-blue md:min-h-0">
                        <option value=""><?php echo esc_html($mediaLabel); ?></option>
                        <?php foreach ($mediaTerms as $term) : ?>
                            <option value="<?php echo esc_attr($term->slug); ?>" <?php selected($selMedia, $term->slug); ?>>
                                <?php echo esc_html($term->name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label class="flex w-full flex-col gap-1 md:w-[214px]">
                    <span class="sr-only"><?php echo esc_html($productLabel); ?></span>
                    <select name="rproduct"
                            class="min-h-[44px] w-full cursor-pointer appearance-none border-0 border-b border-cadco-blue bg-transparent pb-3 pr-6 font-display text-[20px] font-medium text-cadco-blue focus:outline-none focus-visible:ring-2 focus-visible:ring-cadco-blue md:min-h-0">
                        <option value=""><?php echo esc_html($productLabel); ?></option>
                        <?php foreach ($productTerms as $term) : ?>
                            <option value="<?php echo esc_attr($term->slug); ?>" <?php selected($selProduct, $term->slug); ?>>
                                <?php echo esc_html($term->name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <div class="relative w-full md:ml-auto md:w-[488px]">
                    <label class="sr-only" for="cadco-resource-search"><?php esc_html_e('Search resources', 'cadco-theme'); ?></label>
                    <input id="cadco-resource-search" type="search" name="rs"
                           value="<?php echo esc_attr($search); ?>"
                           placeholder="<?php esc_attr_e('Search', 'cadco-theme'); ?>"
                           class="h-[46px] w-full rounded-[8px] border border-[#00476e] bg-white pl-5 pr-12 font-display text-[17px] text-true-black placeholder:text-[#1b2a33] focus:border-cadco-blue focus:outline-none" />
                    <button type="submit"
                            class="absolute right-2 top-1/2 flex h-11 w-11 -translate-y-1/2 items-center justify-center text-cadco-blue md:right-4 md:h-7 md:w-7"
                            aria-label="<?php esc_attr_e('Search resources', 'cadco-theme'); ?>">
                        <svg class="h-[18px] w-[18px]" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                            <circle cx="9" cy="9" r="6" stroke="currentColor" stroke-width="1.8" />
                            <path d="m13.5 13.5 3.5 3.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                        </svg>
                    </button>
                </div>
            </form>
        <?php endif; ?>

        <?php if ($query->have_posts()) : ?>
            <div class="grid grid-cols-1 gap-[21px] <?php echo esc_attr($gridCols); ?>" data-cadco-reveal="items">
                <?php while ($query->have_posts()) : $query->the_post();
                    $id    = get_the_ID();
                    $link  = (string) get_post_meta($id, 'cadco_resource_url', true);
                    $href  = '' !== $link && '#' !== $link ? $link : get_permalink($id);
                    $terms = get_the_terms($id, 'resource_media');
                    $kind  = (! is_wp_error($terms) && $terms) ? $terms[0]->name : '';

                    /* A video still is a photograph and fills the frame; a document
                       is a page, and cropping one cuts off the thing the reader came
                       to recognise, so it is shown whole on the card's tint. */
                    $isStill = 'video' === strtolower($kind);
                    $fit     = $isStill ? 'object-cover' : 'object-contain p-5';

                    /*
                     * What a card does is read from the resource's own destination
                     * kind, not from its media type. The two answer different
                     * questions and come apart in the real library: a guide is a PDF
                     * on one row and a Dropbox folder on the next, and both are
                     * guides. A kind left empty still behaves sensibly, so a resource
                     * that predates the field does not break.
                     */
                    $kind_of  = (string) get_post_meta($id, 'cadco_resource_link_type', true);
                    $hasUrl   = '' !== $link && '#' !== $link;

                    if ('' === $kind_of && $hasUrl) {
                        $kind_of = $isStill && '' !== $embedUrl($link) ? 'video' : 'link';
                    }

                    $embed      = 'video' === $kind_of ? $embedUrl($link) : '';
                    $opensModal = '' !== $embed;
                    /* A file and a link both leave the page; they are kept apart so
                       the card can say which it is, and so the client can change one
                       without re-reading the other. */
                    $opensTab   = ! $opensModal && $hasUrl && in_array($kind_of, ['file', 'link'], true);
                    $href       = ($opensModal || $opensTab) ? $link : get_permalink($id);
                    ?>
                    <a href="<?php echo esc_url($href); ?>"
                       <?php if ($opensModal) : ?>
                           data-cadco-resource-video="<?php echo esc_url($embed); ?>"
                           data-cadco-resource-title="<?php echo esc_attr(get_the_title($id)); ?>"
                       <?php elseif ($opensTab) : ?>
                           target="_blank" rel="noopener noreferrer"
                       <?php endif; ?>
                       class="group flex flex-col overflow-hidden rounded-[22px] border border-[#cfdeeb] bg-white no-underline transition-shadow hover:shadow-[0_6px_24px_rgba(0,0,0,0.08)]">

                        <div class="relative aspect-[342/202] w-full overflow-hidden bg-[#dbe7f3]">
                            <?php if (has_post_thumbnail($id)) : ?>
                                <?php echo get_the_post_thumbnail($id, 'large', [
                                    'class'   => 'h-full w-full ' . $fit,
                                    'loading' => 'lazy',
                                ]); ?>
                            <?php endif; ?>

                            <?php if ('' !== $kind) : ?>
                                <span class="absolute bottom-3 right-3 rounded-[6px] bg-true-black px-3 py-1 font-display text-[14px] font-medium lowercase text-white">
                                    <?php echo esc_html($kind); ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <div class="flex flex-1 flex-col px-[36px] pb-[30px] pt-[24px]">
                            <h3 class="m-0 font-display text-[21px] font-bold leading-[1.25] text-true-black">
                                <?php echo esc_html(get_the_title($id)); ?>
                                <?php /* A link that opens elsewhere says so, rather than
                                         surprising someone who cannot see the new tab
                                         appear. */ ?>
                                <?php if ($opensTab) : ?>
                                    <span class="sr-only"><?php
                                        echo 'file' === $kind_of
                                            ? esc_html__('(opens the document in a new tab)', 'cadco-theme')
                                            : esc_html__('(opens another site in a new tab)', 'cadco-theme');
                                    ?></span>
                                <?php elseif ($opensModal) : ?>
                                    <span class="sr-only"><?php esc_html_e('(plays in a dialog on this page)', 'cadco-theme'); ?></span>
                                <?php endif; ?>
                            </h3>
                            <?php $excerpt = get_the_excerpt($id); ?>
                            <?php if ('' !== $excerpt) : ?>
                                <p class="m-0 mt-3 line-clamp-2 font-display text-[16px] leading-[24px] text-[#11181c]">
                                    <?php echo esc_html($excerpt); ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    </a>
                <?php endwhile; ?>
                <?php /* Reset here rather than at the end of the block: everything
                         below reads the page, not a card, and leaving the last
                         card in place is what sent the page links to it. */ ?>
                <?php wp_reset_postdata(); ?>
            </div>

            <?php if ($query->max_num_pages > 1) : ?>
                <nav class="mt-[72px] flex items-center justify-center gap-7 font-display text-[17px]"
                     aria-label="<?php esc_attr_e('Resource pages', 'cadco-theme'); ?>">
                    <?php for ($n = 1; $n <= (int) $query->max_num_pages; $n++) : ?>
                        <?php if ($n === $paged) : ?>
                            <span aria-current="page" class="border-b-2 border-cadco-blue pb-1 font-bold text-cadco-blue"><?php echo (int) $n; ?></span>
                        <?php else : ?>
                            <a href="<?php echo $pageUrl($n); ?>" class="pb-1 font-medium text-true-black no-underline hover:text-cadco-blue"><?php echo (int) $n; ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($paged < (int) $query->max_num_pages) : ?>
                        <a href="<?php echo $pageUrl($paged + 1); ?>" class="inline-flex items-center gap-2 font-bold text-true-black no-underline hover:text-cadco-blue">
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
                <?php esc_html_e('No resources match those filters yet.', 'cadco-theme'); ?>
            </p>
        <?php endif; ?>

        <?php /* One dialog for the whole grid rather than one per card: only one
                 video can play at a time, and a <dialog> gives the focus trap, the
                 Escape key and the inert background for free. The frame is only
                 written when a card is opened, so no card costs a YouTube request
                 until someone asks for it, and clearing it on close stops playback
                 without having to talk to the player. */ ?>
        <?php if (! $is_preview) : ?>
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

        <?php // Rendered whenever it has copy, and always in the editor so it stays editable. ?>
        <?php if ($footnote !== '' || $is_preview) : ?>
            <div data-proto-field="footnote"
                 class="cadco-resource-note mx-auto mt-[150px] max-w-[580px] text-center">
                <?php echo wp_kses_post($footnote); ?>
            </div>
        <?php endif; ?>
    </div>
</section>
