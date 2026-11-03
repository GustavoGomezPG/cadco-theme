<?php
/**
 * Cadco search results.
 *
 * Grouped by what was found rather than listed in one mixed run: products as
 * the catalogue's own cards, resources as rows that download, pages as links.
 * Each group is counted and can be narrowed to on its own.
 *
 * Products come first on purpose. An earlier build put every non-product match
 * above them and a search for "XAF" pushed the first card 1.4 screens down the
 * page, behind two dozen spec sheets. On a catalogue the product is what the
 * search was for.
 *
 * The chips are links, not script: each view has its own address, so a filtered
 * result can be shared and the page still works with scripting off.
 *
 * Its own queries rather than the search loop WordPress already ran: that loop
 * is one mixed list of every post type in date order, which is none of the
 * three things this page shows.
 *
 * @var array         $attributes
 * @var WP_Block|null $block  Null in the editor preview.
 */

$eyebrow         = (string) ($attributes['eyebrow'] ?? 'Search results');
$emptyTitle      = (string) ($attributes['emptyTitle'] ?? 'Search');
$productsPerPage = max(4, min(48, (int) ($attributes['productsPerPage'] ?? 16)));
$productPreview  = max(2, min(24, (int) ($attributes['productPreview'] ?? 8)));
$resourcePreview = max(2, min(24, (int) ($attributes['resourcePreview'] ?? 6)));
$showPrice       = (bool) ($attributes['showPrice'] ?? true);
$showRelated     = (bool) ($attributes['showRelated'] ?? true);

// $block is null in the editor preview.
$is_preview = ! isset($block) || $block === null;

/* Literal classes, so Tailwind's scanner sees them: it cannot generate a class
   from a string PHP assembles at run time. */
$surface = ($attributes['surface'] ?? 'paper') === 'tint' ? 'bg-[#eef2f6]' : 'bg-paper';

/* The search term is the one thing on this page a visitor writes, and it is
   echoed into a heading, an input value and every link on the page. Angle
   brackets are stripped as a belt (the value then carries no tag even if
   some layer re-parses it); quotes stay, because product searches carry
   them (9 7/8"), and esc_attr()/esc_html() at every output below handle
   them. Needs Proto-Blocks 2.14.5+, whose renderer no longer runs
   html_entity_decode() over the finished markup and so no longer undoes
   the escaping on the way out. */
$term = $is_preview
    ? 'VariKwik'
    : trim(str_replace(['<', '>'], '', wp_strip_all_tags((string) get_query_var('s'))));

$searchUrl = home_url('/');
$paged     = $is_preview ? 1 : max(1, (int) ($_GET['ppage'] ?? 1));

$show = $is_preview ? 'all' : (string) ($_GET['show'] ?? 'all');

if (! in_array($show, ['all', 'products', 'resources', 'pages'], true)) {
    $show = 'all';
}

/* Everything public and searchable that is not a product or a resource -- they
   have their own groups -- and not an attachment, which is the media library
   rather than anything a visitor searched for. Read from the registry so a
   post type added later is included without this file changing. */
$pageTypes = array_values(array_diff(
    get_post_types(['public' => true, 'exclude_from_search' => false], 'names'),
    ['product', 'resource', 'attachment']
));

/**
 * One group's matches, and how many there are in total.
 *
 * `found_posts` counts the whole match, not the slice asked for, so the chips
 * can report real totals while a group shows only its first few.
 */
$search = static function (array $types, int $per, int $page = 1) use ($term): ?WP_Query {
    if ($term === '' || $types === []) {
        return null;
    }

    return new WP_Query([
        'post_type'           => $types,
        'post_status'         => 'publish',
        's'                   => $term,
        'posts_per_page'      => $per,
        'paged'               => $page,
        'ignore_sticky_posts' => true,
    ]);
};

$productsOnly = 'products' === $show;
$resourcesOnly = 'resources' === $show;
$pagesOnly    = 'pages' === $show;

$productQ  = post_type_exists('product')
    ? $search(['product'], $productsOnly ? $productsPerPage : $productPreview, $productsOnly ? $paged : 1)
    : null;
$resourceQ = post_type_exists('resource')
    ? $search(['resource'], $resourcesOnly ? $productsPerPage : $resourcePreview, $resourcesOnly ? $paged : 1)
    : null;
$pageQ     = $search($pageTypes, $pagesOnly ? $productsPerPage : 10, $pagesOnly ? $paged : 1);

$productCount  = $productQ ? (int) $productQ->found_posts : 0;
$resourceCount = $resourceQ ? (int) $resourceQ->found_posts : 0;
$pageCount     = $pageQ ? (int) $pageQ->found_posts : 0;
$total         = $productCount + $resourceCount + $pageCount;

/** A view of this page with one thing changed, carrying the term along. */
$viewUrl = static function (array $changes) use ($searchUrl, $term): string {
    $args = array_merge(['s' => $term], $changes);
    $args = array_filter($args, static fn ($v): bool => '' !== $v && null !== $v && 0 !== $v);

    return esc_url(add_query_arg($args, $searchUrl));
};

/* A resource is a document. Its kind is the word CADCO filed it under, and the
   badge is read from the file the link actually points at -- never guessed
   from the title. Page counts and running times are not recorded anywhere, so
   the design's "2 pages" and "3:40" are left off rather than invented. */
$resourceMeta = static function (int $id): array {
    $destination = function_exists('cadco_resource_destination') ? cadco_resource_destination($id) : '';
    $terms       = get_the_terms($id, 'resource_media');
    $kind        = (! is_wp_error($terms) && $terms) ? $terms[0]->name : '';

    $ext    = 'WEB';
    $action = __('Open', 'cadco-theme');

    if (preg_match('~\.([a-z0-9]{2,4})(?:\?|#|$)~i', $destination, $m)) {
        $ext    = strtoupper($m[1]);
        $action = __('Download', 'cadco-theme');
    }

    if (preg_match('~youtube\.com|youtu\.be|vimeo\.com~i', $destination)) {
        $ext    = 'VIDEO';
        $action = __('Watch', 'cadco-theme');
    }

    return ['url' => $destination, 'kind' => $kind, 'ext' => $ext, 'action' => $action];
};

/** A permalink written the way the design shows it: host, then its segments. */
$readablePath = static function (string $url): string {
    $host = (string) wp_parse_url($url, PHP_URL_HOST);
    $path = trim((string) wp_parse_url($url, PHP_URL_PATH), '/');

    return $path === ''
        ? $host
        : $host . ' / ' . implode(' / ', explode('/', $path));
};

$reveal = $is_preview ? '' : 'data-proto-animate="manual" data-cadco-reveal-group';

$wrapper = get_block_wrapper_attributes([
    'class' => 'cadco-search-results w-full ' . $surface . ' pt-[72px] pb-[120px]',
]);
?>
<section <?php echo $wrapper; ?> <?php echo $reveal; ?>>
    <div class="mx-auto w-full max-w-[1440px] px-6 lg:px-10">

        <?php // ---------- Term, counts, and the box to search again ---------- ?>
        <div class="flex flex-col items-start justify-between gap-6 border-b-2 border-true-black pb-7 lg:flex-row lg:items-end lg:gap-10">
            <div class="min-w-0">
                <p class="m-0 font-display text-[12px] font-semibold uppercase tracking-[0.12em] text-[#6b6f75]">
                    <?php echo esc_html($eyebrow); ?>
                </p>

                <h1 data-cadco-reveal="lines"
                    class="m-0 mt-1.5 font-display text-[36px] font-extrabold leading-none text-true-black md:text-[48px] lg:text-[56px]">
                    <?php echo '' === $term ? esc_html($emptyTitle) : '&ldquo;' . esc_html($term) . '&rdquo;'; ?>
                </h1>

                <?php if ('' !== $term) : ?>
                    <p class="m-0 mt-2 text-[15px] text-[#555]">
                        <?php
                        printf(
                            /* translators: %s: number of results. */
                            esc_html(_n('%s result', '%s results', $total, 'cadco-theme')),
                            esc_html(number_format_i18n($total))
                        );
                        ?>
                        <?php if ($total > 0) : ?>
                            &middot;
                            <?php
                            $parts = [];

                            if ($productCount) {
                                /* translators: %s: number of products. */
                                $parts[] = sprintf(esc_html(_n('%s product', '%s products', $productCount, 'cadco-theme')), esc_html(number_format_i18n($productCount)));
                            }
                            if ($resourceCount) {
                                /* translators: %s: number of resources. */
                                $parts[] = sprintf(esc_html(_n('%s resource', '%s resources', $resourceCount, 'cadco-theme')), esc_html(number_format_i18n($resourceCount)));
                            }
                            if ($pageCount) {
                                /* translators: %s: number of pages. */
                                $parts[] = sprintf(esc_html(_n('%s page', '%s pages', $pageCount, 'cadco-theme')), esc_html(number_format_i18n($pageCount)));
                            }

                            echo implode(', ', $parts);
                            ?>
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
            </div>

            <form role="search" method="get" action="<?php echo esc_url($searchUrl); ?>"
                  class="w-full max-w-[520px] lg:flex-1">
                <label class="sr-only" for="cadco-search-results-field">
                    <?php esc_html_e('Search', 'cadco-theme'); ?>
                </label>
                <div class="flex h-[52px] items-center overflow-hidden rounded-md border-[1.5px] border-[#c9ccd1] bg-white focus-within:border-cadco-blue">
                    <span class="flex w-12 flex-none items-center justify-center text-[#6b6f75]" aria-hidden="true">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round">
                            <circle cx="11" cy="11" r="7"></circle><path d="M20 20l-3.5-3.5"></path>
                        </svg>
                    </span>
                    <input id="cadco-search-results-field" type="search" name="s"
                           value="<?php echo esc_attr($term); ?>"
                           placeholder="<?php esc_attr_e('Search products, spec sheets, guides…', 'cadco-theme'); ?>"
                           class="h-full min-w-0 flex-1 border-0 bg-transparent p-0 font-sans text-[16px] font-medium text-true-black focus:outline-none">
                    <button type="submit"
                            class="h-full flex-none cursor-pointer border-0 bg-cadco-blue px-[22px] font-sans text-[15px] font-bold text-white transition-colors hover:bg-[#00395a]">
                        <?php esc_html_e('Search', 'cadco-theme'); ?>
                    </button>
                </div>
            </form>
        </div>

        <?php if ('' === $term) : ?>
            <p class="py-16 text-center font-display text-[19px] text-[#4a5a63]">
                <?php esc_html_e('Type what you are looking for and we will find it.', 'cadco-theme'); ?>
            </p>
        <?php elseif (0 === $total) : ?>
            <p class="py-16 text-center font-display text-[19px] text-[#4a5a63]">
                <?php
                printf(
                    /* translators: %s: the search term. */
                    esc_html__('Nothing matched %s. Try a model number, or a word from the product name.', 'cadco-theme'),
                    '&ldquo;' . esc_html($term) . '&rdquo;'
                );
                ?>
            </p>
        <?php else : ?>

            <?php // ---------- Narrow to one kind ---------- ?>
            <?php
            $chips = [
                ['key' => 'all',       'label' => __('All', 'cadco-theme'),       'count' => $total],
                ['key' => 'products',  'label' => __('Products', 'cadco-theme'),  'count' => $productCount],
                ['key' => 'resources', 'label' => __('Resources', 'cadco-theme'), 'count' => $resourceCount],
                ['key' => 'pages',     'label' => __('Pages', 'cadco-theme'),     'count' => $pageCount],
            ];
            ?>
            <nav class="flex flex-wrap items-center gap-2.5 pb-11 pt-[22px]"
                 aria-label="<?php esc_attr_e('Filter results', 'cadco-theme'); ?>">
                <?php foreach ($chips as $chip) : ?>
                    <?php
                    // A kind with nothing in it is not offered: a chip leading to
                    // an empty page is a dead end the count already rules out.
                    if (0 === $chip['count'] && 'all' !== $chip['key']) {
                        continue;
                    }

                    $on = $show === $chip['key'];
                    ?>
                    <a href="<?php echo $viewUrl(['show' => 'all' === $chip['key'] ? '' : $chip['key']]); ?>"
                       <?php echo $on ? 'aria-current="page"' : ''; ?>
                       class="flex h-[38px] items-center gap-2 rounded-full border-[1.5px] px-4 font-sans text-[14px] font-semibold no-underline transition-colors <?php echo $on
                           ? 'border-true-black bg-true-black text-white'
                           : 'border-[#d6d9dd] bg-white text-true-black hover:border-[#b9bec5]'; ?>">
                        <?php echo esc_html($chip['label']); ?>
                        <span class="text-[12px] font-semibold opacity-65"><?php echo esc_html(number_format_i18n($chip['count'])); ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>

            <?php // ---------- Products ---------- ?>
            <?php if ($productQ && $productQ->have_posts() && ('all' === $show || $productsOnly)) : ?>
                <section class="mb-[72px]">
                    <div class="mb-[22px] flex items-baseline justify-between gap-4">
                        <h2 class="m-0 font-display text-[26px] font-extrabold uppercase leading-none tracking-[0.01em] text-true-black md:text-[30px]">
                            <?php esc_html_e('Products', 'cadco-theme'); ?>
                            <span class="font-bold text-[#8a8f96]"><?php echo esc_html(number_format_i18n($productCount)); ?></span>
                        </h2>

                        <?php if ('all' === $show && $productCount > $productPreview) : ?>
                            <a href="<?php echo $viewUrl(['show' => 'products']); ?>"
                               class="shrink-0 text-[14px] font-semibold text-cadco-blue no-underline hover:underline">
                                <?php
                                printf(
                                    /* translators: %s: number of products. */
                                    esc_html__('View all %s products', 'cadco-theme'),
                                    esc_html(number_format_i18n($productCount))
                                );
                                ?> &rarr;
                            </a>
                        <?php endif; ?>
                    </div>

                    <ul data-cadco-reveal="items"
                        class="m-0 grid list-none grid-cols-1 gap-5 p-0 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                        <?php while ($productQ->have_posts()) : $productQ->the_post(); ?>
                            <?php
                            /* "Best match" is only claimed where it is true: the
                               product's own SKU is what was typed. Anything looser
                               would be the page asserting a ranking the query does
                               not actually have. */
                            $sku   = function_exists('wc_get_product') && ($p = wc_get_product(get_the_ID())) ? (string) $p->get_sku() : '';
                            $exact = '' !== $sku && 0 === strcasecmp(trim($sku), $term);
                            ?>
                            <li class="relative m-0">
                                <?php if ($exact) : ?>
                                    <span class="pointer-events-none absolute left-3 top-3 z-10 rounded bg-true-black px-2 py-[5px] font-sans text-[11px] font-bold uppercase tracking-[0.08em] text-white">
                                        <?php esc_html_e('Best match', 'cadco-theme'); ?>
                                    </span>
                                <?php endif; ?>
                                <?php /* The catalogue's own card -- shared with the grid
                                         and the related rows via inc/cadco-products.php.
                                         Two grids drawing the same card from two copies
                                         of the markup is how they drift apart. */ ?>
                                <?php echo cadco_product_card_html(get_the_ID(), ['showPrice' => $showPrice]); ?>
                            </li>
                        <?php endwhile; ?>
                        <?php wp_reset_postdata(); ?>
                    </ul>
                </section>
            <?php endif; ?>

            <?php // ---------- Resources ---------- ?>
            <?php if ($resourceQ && $resourceQ->have_posts() && ('all' === $show || $resourcesOnly)) : ?>
                <section class="mb-[72px]">
                    <div class="mb-[18px] flex items-baseline justify-between gap-4">
                        <h2 class="m-0 font-display text-[26px] font-extrabold uppercase leading-none tracking-[0.01em] text-true-black md:text-[30px]">
                            <?php esc_html_e('Resources', 'cadco-theme'); ?>
                            <span class="font-bold text-[#8a8f96]"><?php echo esc_html(number_format_i18n($resourceCount)); ?></span>
                        </h2>

                        <?php if ('all' === $show && $resourceCount > $resourcePreview) : ?>
                            <a href="<?php echo $viewUrl(['show' => 'resources']); ?>"
                               class="shrink-0 text-[14px] font-semibold text-cadco-blue no-underline hover:underline">
                                <?php
                                printf(
                                    /* translators: %s: number of resources. */
                                    esc_html__('View all %s resources', 'cadco-theme'),
                                    esc_html(number_format_i18n($resourceCount))
                                );
                                ?> &rarr;
                            </a>
                        <?php endif; ?>
                    </div>

                    <ul data-cadco-reveal="items"
                        class="m-0 grid list-none grid-cols-1 gap-3 p-0 xl:grid-cols-2">
                        <?php while ($resourceQ->have_posts()) : $resourceQ->the_post(); ?>
                            <?php
                            $meta = $resourceMeta(get_the_ID());
                            $href = '' !== $meta['url'] ? $meta['url'] : (string) get_permalink();
                            $pdf  = 'PDF' === $meta['ext'];
                            $vid  = 'VIDEO' === $meta['ext'];
                            ?>
                            <li class="m-0">
                                <div class="flex items-center gap-4 rounded-[10px] border border-[#e4e6e9] bg-white p-4 transition-colors hover:border-[#b9bec5]">
                                    <span class="flex h-11 w-11 flex-none items-center justify-center rounded-lg font-display text-[11px] font-extrabold tracking-[0.06em] <?php echo $pdf
                                        ? 'bg-[#e8f0f6] text-cadco-blue'
                                        : ($vid ? 'bg-true-black text-white' : 'bg-[#f2f4f6] text-[#444]'); ?>"
                                          aria-hidden="true"><?php echo esc_html($meta['ext']); ?></span>

                                    <span class="flex min-w-0 flex-1 flex-col gap-[3px]">
                                        <?php if ('' !== $meta['kind']) : ?>
                                            <span class="font-sans text-[12px] font-semibold uppercase tracking-[0.08em] text-[#6b6f75]">
                                                <?php echo esc_html($meta['kind']); ?>
                                            </span>
                                        <?php endif; ?>
                                        <a href="<?php echo esc_url($href); ?>" target="_blank" rel="noopener noreferrer"
                                           class="font-sans text-[16px] font-semibold leading-[1.25] text-true-black no-underline hover:text-cadco-blue">
                                            <?php echo esc_html(get_the_title()); ?>
                                        </a>
                                    </span>

                                    <a href="<?php echo esc_url($href); ?>" target="_blank" rel="noopener noreferrer"
                                       class="flex h-9 flex-none items-center gap-1.5 rounded-md border-[1.5px] border-[#d6d9dd] px-3 font-sans text-[13px] font-semibold text-true-black no-underline transition-colors hover:border-cadco-blue hover:text-cadco-blue">
                                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                             stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                            <?php if ($vid) : ?>
                                                <path d="M8 5l11 7-11 7V5z" />
                                            <?php else : ?>
                                                <path d="M12 4v11M7 10l5 5 5-5M5 20h14" />
                                            <?php endif; ?>
                                        </svg>
                                        <?php echo esc_html($meta['action']); ?>
                                        <span class="sr-only"> <?php echo esc_html(get_the_title()); ?></span>
                                    </a>
                                </div>
                            </li>
                        <?php endwhile; ?>
                        <?php wp_reset_postdata(); ?>
                    </ul>
                </section>
            <?php endif; ?>

            <?php // ---------- Pages ---------- ?>
            <?php if ($pageQ && $pageQ->have_posts() && ('all' === $show || $pagesOnly)) : ?>
                <section class="mb-[72px]">
                    <h2 class="m-0 mb-4 font-display text-[26px] font-extrabold uppercase leading-none tracking-[0.01em] text-true-black md:text-[30px]">
                        <?php esc_html_e('Pages', 'cadco-theme'); ?>
                        <span class="font-bold text-[#8a8f96]"><?php echo esc_html(number_format_i18n($pageCount)); ?></span>
                    </h2>

                    <ul data-cadco-reveal="items" class="m-0 flex max-w-[720px] list-none flex-col gap-3 p-0">
                        <?php while ($pageQ->have_posts()) : $pageQ->the_post(); ?>
                            <li class="m-0">
                                <a href="<?php echo esc_url((string) get_permalink()); ?>"
                                   class="flex items-center justify-between gap-4 rounded-[10px] border border-[#e4e6e9] px-5 py-[18px] no-underline transition-colors hover:border-[#b9bec5]">
                                    <span class="flex min-w-0 flex-col gap-[3px]">
                                        <span class="font-sans text-[16px] font-semibold text-true-black"><?php echo esc_html(get_the_title()); ?></span>
                                        <span class="truncate font-sans text-[13px] text-[#6b6f75]"><?php echo esc_html($readablePath((string) get_permalink())); ?></span>
                                    </span>
                                    <span class="shrink-0 font-sans text-[13px] font-semibold text-cadco-blue"><?php esc_html_e('Open', 'cadco-theme'); ?> &rarr;</span>
                                </a>
                            </li>
                        <?php endwhile; ?>
                        <?php wp_reset_postdata(); ?>
                    </ul>
                </section>
            <?php endif; ?>

            <?php // ---------- Paging, when one kind is shown on its own ---------- ?>
            <?php
            $pagedQuery = $productsOnly ? $productQ : ($resourcesOnly ? $resourceQ : ($pagesOnly ? $pageQ : null));
            ?>
            <?php if ($pagedQuery && $pagedQuery->max_num_pages > 1) : ?>
                <nav class="mb-[72px] mt-[-24px] flex flex-wrap items-center justify-center gap-7 font-display text-[17px]"
                     aria-label="<?php esc_attr_e('Result pages', 'cadco-theme'); ?>">
                    <?php for ($n = 1; $n <= (int) $pagedQuery->max_num_pages; $n++) : ?>
                        <?php if ($n === $paged) : ?>
                            <span aria-current="page" class="border-b-2 border-cadco-blue pb-1 font-bold text-cadco-blue"><?php echo (int) $n; ?></span>
                        <?php else : ?>
                            <a href="<?php echo $viewUrl(['show' => $show, 'ppage' => $n > 1 ? $n : '']); ?>"
                               class="pb-1 font-medium text-true-black no-underline hover:text-cadco-blue"><?php echo (int) $n; ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                </nav>
            <?php endif; ?>

            <?php // ---------- Browse categories ---------- ?>
            <?php
            /* Built from the categories the matching products are actually filed
               under, so every chip leads to a category that holds at least one of
               them. Invented suggestions would send people to empty pages.

               Biggest first: these are offered as somewhere to go next, and the
               broad category is the more useful answer to "not quite what I
               wanted" than whichever narrow one happens to sort first.

               The ranking is done here rather than with get_terms()' own
               `orderby`, for two reasons. The count that matters is the one the
               catalogue shows -- inc/cadco-woocommerce.php's get_terms filter
               rewrites ->count to exclude hidden children, after the query has
               run -- and WooCommerce re-orders product_cat by menu_order in SQL,
               so an `orderby` here would not survive. Taking the cap in PHP too
               means the six kept are the six biggest, not six arbitrary terms
               that were then sorted. */
            $related = [];

            if ($showRelated && $productQ && $productCount > 0) {
                $ids = wp_list_pluck($productQ->posts, 'ID');

                foreach ((array) get_terms([
                    'taxonomy'   => 'product_cat',
                    'object_ids' => $ids,
                    'hide_empty' => true,
                ]) as $relTerm) {
                    if ($relTerm instanceof WP_Term && 0 !== strcasecmp($relTerm->name, $term)) {
                        $related[] = $relTerm;
                    }
                }

                // Name breaks the ties, so equal-sized categories keep a stable order.
                usort($related, static function (WP_Term $a, WP_Term $b): int {
                    return ((int) $b->count <=> (int) $a->count)
                        ?: strcasecmp($a->name, $b->name);
                });

                $related = array_slice($related, 0, 6);
            }
            ?>
            <?php if ($related) : ?>
                <div class="flex flex-wrap items-center gap-x-3.5 gap-y-2.5 border-t border-[#e4e6e9] pt-7">
                    <span class="mr-1.5 font-sans text-[13px] font-semibold uppercase tracking-[0.08em] text-[#6b6f75]">
                        <?php esc_html_e('Browse categories', 'cadco-theme'); ?>
                    </span>
                    <?php foreach ($related as $relTerm) : ?>
                        <?php $relUrl = get_term_link($relTerm); ?>
                        <a href="<?php echo esc_url(is_wp_error($relUrl) ? $viewUrl(['s' => $relTerm->name, 'show' => '']) : $relUrl); ?>"
                           class="rounded-full bg-[#f2f4f6] px-3.5 py-[7px] font-sans text-[14px] font-medium text-true-black no-underline transition-colors hover:bg-[#e6e9ec]">
                            <?php echo esc_html($relTerm->name); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        <?php endif; ?>
    </div>
</section>
