<?php
/**
 * Cadco site footer.
 *
 * @var array         $attributes
 * @var WP_Block|null $block
 */

$logo       = $attributes['logo'] ?? [];
$columns    = $attributes['columns'] ?? [];
$ctaHeading = $attributes['ctaHeading'] ?? '';
$cta        = $attributes['cta'] ?? [];
$copyright  = $attributes['copyright'] ?? '';
$legal      = $attributes['legal'] ?? [];

// $block is null in the editor preview.
$is_preview = ! isset($block) || $block === null;

// Seed the editor so every region is discoverable from a fresh insert: the
// parser learns a repeater's sub-fields from rendered item markup, so an empty
// repeater would leave the author with nothing to click.
if ($is_preview && empty($columns)) {
    $columns = [[
        'id'      => 'preview-column-1',
        'heading' => 'Column heading',
        'links'   => '<ul><li><a href="#">First link</a></li><li><a href="#">Second link</a></li></ul>',
    ]];
}

$wrapper = get_block_wrapper_attributes([
    'class' => 'cadco-footer bg-true-black text-white',
]);

$logoUrl = $logo['url'] ?? '';
$logoAlt = $logo['alt'] ?? '';

$ctaUrl    = $cta['url'] ?? '';
$ctaText   = $cta['text'] ?? '';
$legalUrl  = $legal['url'] ?? '';
$legalText = $legal['text'] ?? '';
?>
<footer <?php echo $wrapper; ?>>
    <div class="mx-auto w-full max-w-[1440px] px-6 pt-14 pb-8 lg:px-10 lg:pt-20 lg:pb-16">

        <div class="grid gap-10 lg:grid-cols-5 lg:gap-8">

            <?php /* Brand. The logo is decorative here — the site name is already
                     linked from the header — so an empty alt keeps screen readers
                     from hearing it twice. */ ?>
            <div class="lg:col-span-1">
                <a href="<?php echo esc_url(home_url('/')); ?>" class="inline-block">
                    <img
                        data-proto-field="logo"
                        src="<?php echo esc_url($logoUrl); ?>"
                        alt="<?php echo esc_attr($logoAlt); ?>"
                        class="h-auto w-[106px] max-w-full object-contain"
                        <?php /* Hidden only on the front end: a src-less img would draw a
                                 broken-image box there. In the editor it must stay visible
                                 or the author has nothing to click to pick the logo. */ ?>
                        <?php echo ($logoUrl === '' && ! $is_preview) ? ' hidden' : ''; ?>
                    >
                </a>
            </div>

            <?php /* Link columns. One repeater item is one column: its heading plus
                     one wysiwyg holding the whole list. A column that needs a second
                     group (Support, under Resources in the design) carries it as a
                     bold line inside that same wysiwyg — styled as a sub-heading in
                     style.css — rather than costing the author another field. */ ?>
            <?php /* The column span lives on this wrapper, not on the repeater itself.
                     Once the repeater becomes editable the editor wraps it in a div of
                     its own, and that wrapper — not ours — becomes the grid child, so a
                     span on the repeater is stranded a level down and the three link
                     columns collapse into one track's width. Spanning from outside the
                     repeater keeps the grid child ours in both contexts. */ ?>
            <div class="lg:col-span-3">
                <div
                    class="grid grid-cols-2 gap-8 sm:grid-cols-3 lg:gap-8"
                    data-proto-repeater="columns"
                >
                    <?php foreach ($columns as $column) : ?>
                        <div data-proto-repeater-item class="min-w-0">
                            <span
                                data-proto-field="heading"
                                class="block text-base font-bold text-white"
                            ><?php echo esc_html($column['heading'] ?? ''); ?></span>
                            <div
                                data-proto-field="links"
                                class="cadco-footer-links text-[18px] leading-[1.43]"
                            ><?php echo wp_kses_post($column['links'] ?? ''); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php /* Closing call to action. */ ?>
            <div class="lg:col-span-1">
                <?php /* m-0: the theme gives paragraphs a block margin, which would drop this
                         heading below the link-column headings it has to line up with. */ ?>
                <p
                    data-proto-field="ctaHeading"
                    class="m-0 max-w-[16ch] text-xl font-bold leading-snug text-white lg:text-2xl"
                ><?php echo esc_html($ctaHeading); ?></p>
                <a
                    data-proto-field="cta"
                    href="<?php echo esc_url($ctaUrl); ?>"
                    class="mt-6 inline-flex items-center rounded bg-cadco-blue px-4 py-2 text-sm font-bold text-white transition-colors hover:bg-cadco-blue/85 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
                    <?php echo ! empty($cta['target']) ? ' target="' . esc_attr($cta['target']) . '"' : ''; ?>
                    <?php echo ! empty($cta['rel']) ? ' rel="' . esc_attr($cta['rel']) . '"' : ''; ?>
                ><?php echo esc_html($ctaText); ?></a>
            </div>

        </div>

        <?php /* Legal row. */ ?>
        <div class="mt-12 flex flex-col gap-3 border-t border-white/20 pt-6 text-sm text-white/70 sm:flex-row sm:items-center sm:justify-between lg:mt-32 lg:pt-10">
            <span data-proto-field="copyright"><?php echo esc_html($copyright); ?></span>
            <a
                data-proto-field="legal"
                href="<?php echo esc_url($legalUrl); ?>"
                class="transition-colors hover:text-white"
                <?php echo ! empty($legal['target']) ? ' target="' . esc_attr($legal['target']) . '"' : ''; ?>
                <?php echo ! empty($legal['rel']) ? ' rel="' . esc_attr($legal['rel']) . '"' : ''; ?>
            ><?php echo esc_html($legalText); ?></a>
        </div>

    </div>
</footer>
