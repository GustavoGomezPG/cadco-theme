<?php
/**
 * Cadco site header.
 *
 * @var array         $attributes
 * @var WP_Block|null $block
 */

$logo         = $attributes['logo'] ?? [];
$cta          = $attributes['cta'] ?? [];
$megaColumns  = $attributes['megaColumns'] ?? [];
$miniCards    = $attributes['miniCards'] ?? [];
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

// Seed the editor so every region is discoverable and editable from a fresh
// insert: the parser learns a repeater's sub-fields from rendered item markup,
// so an empty repeater would have nothing to learn from.
if ($is_preview) {
    if (empty($navItems)) {
        $navItems = [
            ['label' => 'Products', 'url' => '#', 'children' => []],
            ['label' => 'Resource Center', 'url' => '#', 'children' => []],
            ['label' => 'Support', 'url' => '#', 'children' => []],
            ['label' => 'About', 'url' => '#', 'children' => []],
        ];
    }
    if (empty($megaColumns)) {
        $megaColumns = [[
            'id'      => 'preview-mega-1',
            'heading' => 'Column heading',
            'links'   => '<ul><li><a href="#">First link</a></li><li><a href="#">Second link</a></li></ul>',
            'seeAll'  => ['url' => '#', 'text' => 'See All'],
        ]];
    }
    if (empty($miniCards)) {
        $miniCards = [[
            'id'          => 'preview-mini-1',
            'cardLink'    => ['url' => '#', 'text' => 'Card title'],
            'description' => 'Short description of this destination.',
        ]];
    }
}

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

    <div class="mx-auto flex h-[74px] w-full max-w-[1440px] items-center gap-3 px-3 lg:gap-8 lg:px-10">

        <?php // Logo — always rendered so it stays editable when empty. ?>
        <a class="flex shrink-0 items-center" href="<?php echo esc_url(home_url('/')); ?>">
            <img
                data-proto-field="logo"
                class="h-7 w-auto max-w-[110px] object-contain lg:h-[46px] lg:max-w-none"
                src="<?php echo esc_url($logo['url'] ?? ''); ?>"
                alt="<?php echo esc_attr($logo['alt'] ?? get_bloginfo('name')); ?>"
            />
        </a>

        <?php // Navigation, read from the site's navigation menu.
        // One nav element for both layouts: below lg it is repositioned by CSS
        // into a drawer rather than duplicated, so the mega/mini panel ids stay
        // unique and the triggers keep working in both. ?>
        <nav class="cadco-nav hidden flex-1 items-center justify-center lg:flex" aria-label="<?php esc_attr_e('Main', 'cadco-theme'); ?>" data-cadco-nav>
            <ul class="flex list-none items-center gap-10 p-0 m-0">
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
                    <svg class="h-5 w-5" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                        <circle cx="9" cy="9" r="6.25" stroke="currentColor" stroke-width="1.5"/>
                        <path d="M13.5 13.5L17.5 17.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
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
               class="inline-flex items-center rounded-md bg-cadco-blue px-2 py-2 text-[12px] font-bold leading-none text-white no-underline transition-colors hover:bg-[#00395a] lg:px-6 lg:py-4 lg:text-[16px]"
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
        class="cadco-panel <?php echo $is_preview ? 'is-preview' : 'hidden'; ?> absolute inset-x-0 top-full w-full bg-panel text-ink"
        data-cadco-panel
    >
        <?php if ($is_preview) : ?>
            <p class="m-0 bg-cadco-blue px-6 py-2 text-[13px] font-bold text-white">
                <?php esc_html_e('Design A — mega menu (shown expanded while editing)', 'cadco-theme'); ?>
            </p>
        <?php endif; ?>

        <div class="mx-auto grid w-full max-w-[1440px] grid-cols-1 lg:grid-cols-4" data-proto-repeater="megaColumns">
            <?php foreach ($megaColumns as $column) :
                $seeAll = $column['seeAll'] ?? [];
                ?>
                <div class="flex flex-col gap-4 border-gray-300 px-8 py-12 lg:border-l lg:last:border-r" data-proto-repeater-item>
                    <span class="block text-[16px] font-bold leading-tight text-ink" data-proto-field="heading">
                        <?php echo esc_html($column['heading'] ?? ''); ?>
                    </span>

                    <div class="cadco-menu-links text-[16px] leading-relaxed text-gray-700" data-proto-field="links">
                        <?php echo wp_kses_post($column['links'] ?? ''); ?>
                    </div>

                    <a data-proto-field="seeAll"
                       class="mt-2 inline-flex w-fit items-center rounded-md bg-cadco-blue px-5 py-3 text-[15px] font-bold leading-none text-white no-underline transition-colors hover:bg-[#00395a]"
                       href="<?php echo esc_url($seeAll['url'] ?? '#'); ?>"
                    ><?php echo esc_html($seeAll['text'] ?? 'See All'); ?></a>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <?php // ---------- Design B: compact card menu ---------- ?>
    <div
        id="<?php echo esc_attr($panel_id($miniAttachTo)); ?>"
        class="cadco-panel <?php echo $is_preview ? 'is-preview' : 'hidden'; ?> absolute inset-x-0 top-full w-full"
        data-cadco-panel
    >
        <?php if ($is_preview) : ?>
            <p class="m-0 bg-cadco-blue px-6 py-2 text-[13px] font-bold text-white">
                <?php esc_html_e('Design B — mini menu (shown expanded while editing)', 'cadco-theme'); ?>
            </p>
        <?php endif; ?>

        <div class="mx-auto w-full max-w-[1440px] px-6 lg:px-10">
            <div class="ml-auto w-fit overflow-hidden rounded-lg bg-panel text-ink shadow-soft">
                <div class="grid grid-cols-1 sm:grid-cols-2" data-proto-repeater="miniCards">
                    <?php foreach ($miniCards as $card) :
                        $cardLink = $card['cardLink'] ?? [];
                        ?>
                        <div class="flex min-w-[240px] flex-col gap-3 border-gray-300 px-10 py-8 sm:border-l sm:first:border-l-0" data-proto-repeater-item>
                            <a data-proto-field="cardLink"
                               class="inline-flex items-center gap-2 text-[16px] font-bold leading-none text-cadco-blue no-underline hover:underline"
                               href="<?php echo esc_url($cardLink['url'] ?? '#'); ?>">
                                <span><?php echo esc_html($cardLink['text'] ?? ''); ?></span>
                                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                                    <path d="M3 10h13M12 5.5L16.5 10 12 14.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </a>
                            <p class="m-0 text-[16px] leading-snug text-gray-700" data-proto-field="description">
                                <?php echo esc_html($card['description'] ?? ''); ?>
                            </p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</header>
