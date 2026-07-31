<?php
/**
 * Cadco site header.
 *
 * @var array         $attributes
 * @var WP_Block|null $block
 */

$logo         = $attributes['logo'] ?? [];
$cta          = $attributes['cta'] ?? [];
$navMenu      = (int) ($attributes['navMenu'] ?? 0);
$megaAttachTo = $attributes['megaAttachTo'] ?? '';
$miniAttachTo = $attributes['miniAttachTo'] ?? '';
$showSearch   = $attributes['showSearch'] ?? true;
$searchUrl    = $attributes['searchUrl'] ?? '/?s=';

// $block is null in the editor preview. The panels are dropdowns on the front
// end, but in the editor they are rendered open and stacked — a repeater inside
// a display:none panel cannot be edited.
$is_preview = ! isset($block) || $block === null;

$navItems = function_exists('cadco_get_nav_items') ? cadco_get_nav_items($navMenu) : [];

// Show the shape of a menu in the editor when none is chosen yet, so the block
// is not a bare bar with nothing to look at.
if ($is_preview && empty($navItems)) {
    $navItems = [
        ['label' => 'Products', 'url' => '#', 'description' => '', 'children' => []],
        ['label' => 'Resource Center', 'url' => '#', 'description' => '', 'children' => []],
        ['label' => 'Support', 'url' => '#', 'description' => '', 'children' => []],
        ['label' => 'About', 'url' => '#', 'description' => '', 'children' => []],
    ];
}

/**
 * The children of the item a panel is attached to.
 *
 * Both panels are projections of the navigation menu — the mega menu renders a
 * child as a column and its own children as that column's links; the mini menu
 * renders each child as a card. Nothing about the menu is restated in the
 * block's own attributes, so the catalogue has exactly one home.
 */
$children_of = static function (string $label) use ($navItems): array {
    if ($label === '') {
        return [];
    }

    foreach ($navItems as $item) {
        if ($item['label'] === $label) {
            return $item['children'];
        }
    }

    return [];
};

$megaColumns = $children_of($megaAttachTo);
$miniCards   = $children_of($miniAttachTo);

$hasMega = $megaAttachTo !== '' && ! empty($megaColumns);
$hasMini = $miniAttachTo !== '' && ! empty($miniCards);

$wrapper = get_block_wrapper_attributes([
    'class' => 'cadco-header relative z-50 bg-header text-white',
]);

/** Panel ids are derived from the attached label so trigger and panel can pair up. */
$panel_id = static function (string $key): string {
    return 'cadco-panel-' . sanitize_title($key !== '' ? $key : 'panel');
};
?>
<header <?php echo $wrapper; ?> data-cadco-header>

    <div class="mx-auto flex h-[74px] w-full max-w-[1440px] items-center justify-between gap-3 px-3 lg:gap-8 lg:px-10">

        <?php // Logo — always rendered so it stays editable when empty. ?>
        <a class="flex shrink-0 items-center" href="<?php echo esc_url(home_url('/')); ?>">
            <img
                data-proto-field="logo"
<?php /* Sized by width on mobile so the mark is a predictable 110px whatever
                     aspect ratio the uploaded logo has; height leads on desktop. */ ?>
                class="h-auto w-[110px] object-contain lg:h-14 lg:w-auto"
                src="<?php echo esc_url($logo['url'] ?? ''); ?>"
                alt="<?php echo esc_attr($logo['alt'] ?? get_bloginfo('name')); ?>"
            />
        </a>

        <?php // Navigation, read from the site's navigation menu. Desktop only —
        // the mobile menu below renders its own markup from the same data. ?>
        <?php /* Right-anchored, not centred: the design groups the links with the
                 search and call to action at the right of the bar rather than
                 floating them in the space left over beside the logo. The extra
                 right margin, on top of the bar's own gap, gives the ~72px the
                 design leaves between the last link and the search icon. */ ?>
        <nav class="cadco-nav hidden flex-1 items-center justify-end lg:mr-10 lg:flex" aria-label="<?php esc_attr_e('Main', 'cadco-theme'); ?>" data-cadco-nav>
            <ul class="flex list-none items-center gap-16 xl:gap-[84px] p-0 m-0">
                <?php foreach ($navItems as $item) :
                    $label   = $item['label'] ?? '';
                    $isMega  = $hasMega && $label === $megaAttachTo;
                    $isMini  = $hasMini && $label === $miniAttachTo;
                    $hasMenu = $isMega || $isMini;
                    $target  = $isMega ? $panel_id($megaAttachTo) : ($isMini ? $panel_id($miniAttachTo) : '');
                    ?>
                    <li class="relative list-none" <?php echo $hasMenu ? 'data-cadco-has-panel' : ''; ?>>
                        <a
                            class="block whitespace-nowrap py-6 text-[16px] leading-none text-white no-underline transition-opacity hover:opacity-70"
                            href="<?php echo esc_url($item['url'] ?? '#'); ?>"
                            <?php if ($hasMenu) : ?>
                                aria-expanded="false"
                                aria-controls="<?php echo esc_attr($target); ?>"
                                data-cadco-trigger="<?php echo esc_attr($target); ?>"
                            <?php endif; ?>
                        ><?php echo esc_html($label); ?></a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </nav>

        <div class="flex shrink-0 items-center gap-2 lg:gap-6">
            <?php if ($showSearch) : ?>
                <button type="button"
                        class="flex items-center bg-transparent p-0 text-white transition-opacity hover:opacity-70 cursor-pointer border-0"
                        aria-label="<?php esc_attr_e('Search', 'cadco-theme'); ?>"
                        aria-expanded="false"
                        aria-controls="cadco-search"
                        data-cadco-search-toggle>
                    <?php // 18x18 to match the Figma vector, circle set upper-left
                    // with the handle running to the corner. ?>
                    <svg class="h-[18px] w-[18px]" viewBox="0 0 18 18" fill="none" aria-hidden="true">
                        <circle cx="7" cy="7" r="5.6" stroke="currentColor" stroke-width="1.5"/>
                        <path d="M11.2 11.2L17 17" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                    </svg>
                </button>
            <?php endif; ?>

            <?php // Hamburger — only below the lg breakpoint. ?>
            <button type="button"
                    class="flex items-center border-0 bg-transparent p-0 text-white transition-opacity hover:opacity-70 cursor-pointer lg:hidden"
                    aria-label="<?php esc_attr_e('Menu', 'cadco-theme'); ?>"
                    aria-expanded="false"
                    data-cadco-menu-toggle>
                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path class="cadco-burger__top" d="M3 6h18" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/>
                    <path class="cadco-burger__mid" d="M3 12h18" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/>
                    <path class="cadco-burger__bot" d="M3 18h18" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/>
                </svg>
            </button>

            <a data-proto-field="cta"
               class="hidden items-center rounded-md bg-cadco-blue px-6 py-4 text-[16px] font-bold leading-none text-white no-underline transition-colors hover:bg-[#00395a] lg:inline-flex"
               href="<?php echo esc_url($cta['url'] ?? '#'); ?>"
               <?php echo ! empty($cta['target']) ? 'target="' . esc_attr($cta['target']) . '"' : ''; ?>
            ><?php echo esc_html($cta['text'] ?? 'Contact Cadco'); ?></a>
        </div>
    </div>

    <?php // ---------- Search: slides down from the bar ---------- ?>
    <?php if ($showSearch) : ?>
        <div id="cadco-search" class="cadco-search absolute inset-x-0 top-full w-full bg-header" data-cadco-search>
            <div class="cadco-search__inner">
                <form role="search" method="get" class="mx-auto flex w-full max-w-[1440px] items-center gap-3 px-6 py-5 lg:px-10"
                      action="<?php echo esc_url(home_url('/')); ?>">
                    <label class="sr-only" for="cadco-search-field"><?php esc_html_e('Search', 'cadco-theme'); ?></label>
                    <input
                        id="cadco-search-field"
                        type="search"
                        name="s"
                        value="<?php echo esc_attr(get_search_query()); ?>"
                        placeholder="<?php esc_attr_e('Search Cadco…', 'cadco-theme'); ?>"
                        class="min-w-0 flex-1 rounded-md border-0 bg-white px-4 py-3 text-[16px] text-ink outline-none focus:ring-2 focus:ring-cadco-blue"
                    />
                    <button type="submit"
                            class="shrink-0 rounded-md bg-cadco-blue px-6 py-3 text-[16px] font-bold leading-none text-white transition-colors hover:bg-[#00395a] cursor-pointer border-0">
                        <?php esc_html_e('Search', 'cadco-theme'); ?>
                    </button>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php // ---------- Design A: full-width mega menu ---------- ?>
    <div
        id="<?php echo esc_attr($panel_id($megaAttachTo)); ?>"
        class="cadco-panel <?php echo $is_preview ? "is-preview" : ""; ?> absolute inset-x-0 top-full w-full bg-panel text-ink"
        data-cadco-panel
    >
        <?php if ($is_preview) : ?>
            <p class="m-0 bg-cadco-blue px-6 py-2 text-[13px] font-bold text-white">
                <?php esc_html_e('Design A — mega menu (shown expanded while editing)', 'cadco-theme'); ?>
            </p>
        <?php endif; ?>

        <?php /* Columns come from the menu, not from the block: a child of the
                 attached item is a column, its own children are that column's
                 links, and the child's URL is where See All goes. The catalogue
                 is therefore edited once, in the navigation menu. */ ?>
        <div class="mx-auto grid w-full max-w-[1440px] grid-cols-1 lg:grid-cols-4">
            <?php foreach ($megaColumns as $column) : ?>
                <div class="flex flex-col gap-4 border-gray-300 px-8 py-12 lg:border-l lg:last:border-r">
                    <span class="block text-[16px] font-bold leading-tight text-ink">
                        <?php echo esc_html($column['label']); ?>
                    </span>

                    <ul class="cadco-menu-links m-0 list-none p-0 text-[16px] leading-relaxed text-gray-700">
                        <?php foreach ($column['children'] as $link) : ?>
                            <li>
                                <a href="<?php echo esc_url($link['url']); ?>"><?php echo esc_html($link['label']); ?></a>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                    <?php if ($column['url'] !== '') : ?>
                        <a class="mt-2 inline-flex w-fit items-center rounded-md bg-cadco-blue px-5 py-3 text-[15px] font-bold leading-none text-white no-underline transition-colors hover:bg-[#00395a]"
                           href="<?php echo esc_url($column['url']); ?>"
                        ><?php esc_html_e('See All', 'cadco-theme'); ?></a>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <?php // ---------- Design B: compact card menu ---------- ?>
    <div
        id="<?php echo esc_attr($panel_id($miniAttachTo)); ?>"
        class="cadco-panel <?php echo $is_preview ? "is-preview" : ""; ?> absolute inset-x-0 top-full w-full"
        data-cadco-panel
    >
        <?php if ($is_preview) : ?>
            <p class="m-0 bg-cadco-blue px-6 py-2 text-[13px] font-bold text-white">
                <?php esc_html_e('Design B — mini menu (shown expanded while editing)', 'cadco-theme'); ?>
            </p>
        <?php endif; ?>

        <div class="mx-auto w-full max-w-[1440px] px-6 lg:px-10">
            <div class="ml-auto w-fit overflow-hidden rounded-lg bg-panel text-ink shadow-soft">
                <?php /* Cards come from the menu too: one per child of the attached
                         item. The supporting line is the linked page's excerpt or
                         the linked term's description — see
                         cadco_resolve_nav_description(). */ ?>
                <div class="grid grid-cols-1 sm:grid-cols-2">
                    <?php foreach ($miniCards as $card) : ?>
                        <?php /* Capped at the design's 278px card. Without it the card
                                 grows to fit the description on one line, which pushed the
                                 panel out to 783px and left it reaching into the middle of
                                 the header instead of sitting under the menu. The cap makes
                                 the description wrap to two lines, as designed. */ ?>
                        <div class="flex min-w-[240px] flex-col gap-3 border-gray-300 px-10 py-8 sm:max-w-[278px] sm:border-l sm:first:border-l-0">
                            <a class="inline-flex items-center gap-2 text-[16px] font-bold leading-none text-cadco-blue no-underline hover:underline"
                               href="<?php echo esc_url($card['url']); ?>"
                            >
                                <?php echo esc_html($card['label']); ?>
                                <svg class="h-4 w-4 shrink-0" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                                    <path d="M3 10h13M12 5.5L16.5 10 12 14.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </a>
                            <?php if ($card['description'] !== '') : ?>
                                <p class="m-0 text-[16px] leading-snug text-gray-700">
                                    <?php echo esc_html($card['description']); ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <?php // ---------- Mobile menu: overlay card with collapsible sections ----
    // Its own markup rather than the desktop nav repositioned by CSS. The
    // desktop panels are wide, four-column layouts that do not fold sensibly
    // into a phone, and squeezing them in produced a 22-link wall. Here each
    // top-level item that owns a panel becomes a collapsible section instead.
    //
    // The logo and call to action are re-rendered from the same attributes
    // rather than carrying data-proto-field a second time: two elements bound
    // to one field would give the editor two bindings for it. Editing either
    // field still updates both, because both are rendered from the same value.
    ?>
    <div class="cadco-mobile absolute inset-x-0 top-full z-40 w-full overflow-hidden bg-header lg:hidden" data-cadco-mobile hidden>
        <div class="cadco-mobile__inner max-h-[calc(100vh-74px)] overflow-y-auto border-t border-white/10">

            <nav aria-label="<?php esc_attr_e('Mobile', 'cadco-theme'); ?>">
                <?php foreach ($navItems as $item) :
                    $label   = $item['label'] ?? '';
                    $isMega  = $hasMega && $label === $megaAttachTo;
                    $isMini  = $hasMini && $label === $miniAttachTo;
                    $section = sanitize_title($label);
                    ?>
                    <?php if ($isMega || $isMini) : ?>
                        <div class="border-b border-white/10 last:border-b-0" data-cadco-acc>
                            <button type="button"
                                    class="flex w-full cursor-pointer items-center justify-between border-0 bg-transparent px-5 py-4 text-left text-[17px] font-medium text-white transition-colors hover:bg-white/5"
                                    aria-expanded="false"
                                    aria-controls="cadco-acc-<?php echo esc_attr($section); ?>"
                                    data-cadco-acc-toggle>
                                <span><?php echo esc_html($label); ?></span>
                                <svg class="cadco-acc__chevron h-5 w-5 shrink-0 text-white/70" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                                    <path d="M5.5 7.5L10 12l4.5-4.5" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </button>

                            <div id="cadco-acc-<?php echo esc_attr($section); ?>" class="cadco-acc__panel" data-cadco-acc-panel>
                                <div class="px-5 pb-5">
                                    <?php if ($isMega) : ?>
                                        <?php // Each category collapses too — anything with nesting is a
                                        // collapse, so the section opens to a short list of categories
                                        // rather than every product link at once. ?>
                                        <?php foreach ($megaColumns as $index => $column) :
                                            $subId = 'cadco-acc-' . $section . '-' . (int) $index;
                                            ?>
                                            <div class="border-t border-white/10 first:border-t-0" data-cadco-acc>
                                                <button type="button"
                                                        class="flex w-full cursor-pointer items-center justify-between gap-3 border-0 bg-transparent py-3 text-left text-[15px] font-bold text-white transition-colors hover:text-white/75"
                                                        aria-expanded="false"
                                                        aria-controls="<?php echo esc_attr($subId); ?>"
                                                        data-cadco-acc-toggle>
                                                    <span><?php echo esc_html($column['label']); ?></span>
                                                    <svg class="cadco-acc__chevron h-4 w-4 shrink-0 text-white/60" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                                                        <path d="M5.5 7.5L10 12l4.5-4.5" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/>
                                                    </svg>
                                                </button>

                                                <div id="<?php echo esc_attr($subId); ?>" class="cadco-acc__panel" data-cadco-acc-panel>
                                                    <div class="pb-4">
                                                        <ul class="cadco-mobile-links m-0 list-none p-0 text-[15px] text-white/70">
                                                            <?php foreach ($column['children'] as $link) : ?>
                                                                <li>
                                                                    <a href="<?php echo esc_url($link['url']); ?>"><?php echo esc_html($link['label']); ?></a>
                                                                </li>
                                                            <?php endforeach; ?>
                                                        </ul>
                                                        <?php if ($column['url'] !== '') : ?>
                                                            <a class="mt-3 inline-flex items-center rounded-md bg-cadco-blue px-4 py-2 text-[14px] font-bold leading-none text-white no-underline transition-colors hover:bg-[#00395a]"
                                                               href="<?php echo esc_url($column['url']); ?>">
                                                                <?php esc_html_e('See All', 'cadco-theme'); ?>
                                                            </a>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else : ?>
                                        <?php foreach ($miniCards as $card) : ?>
                                            <a class="block rounded-lg px-3 py-3 -mx-3 no-underline transition-colors hover:bg-white/5"
                                               href="<?php echo esc_url($card['url']); ?>">
                                                <span class="block text-[15px] font-bold text-white"><?php echo esc_html($card['label']); ?></span>
                                                <?php if ($card['description'] !== '') : ?>
                                                    <span class="mt-1 block text-[14px] leading-snug text-white/60"><?php echo esc_html($card['description']); ?></span>
                                                <?php endif; ?>
                                            </a>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php else : ?>
                        <a class="block border-b border-white/10 px-5 py-4 text-[17px] font-medium text-white no-underline transition-colors last:border-b-0 hover:bg-white/5"
                           href="<?php echo esc_url($item['url'] ?? '#'); ?>">
                            <?php echo esc_html($label); ?>
                        </a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </nav>

            <div class="border-t border-white/10 p-5">
                <a class="flex w-full items-center justify-center rounded-md bg-cadco-blue px-4 py-4 text-[16px] font-bold leading-none text-white no-underline transition-colors hover:bg-[#00395a]"
                   href="<?php echo esc_url($cta['url'] ?? '#'); ?>">
                    <?php echo esc_html($cta['text'] ?? 'Contact Cadco'); ?>
                </a>
            </div>
        </div>
    </div>
</header>
