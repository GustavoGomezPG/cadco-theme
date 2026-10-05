<?php
/**
 * Cadco Comparison Table.
 *
 * One table per tab, each built from CSV rather than from a field per cell.
 *
 * The alternative was a row repeater with a toggle per service column, which
 * would have fixed the columns in the block: adding a seventh would have been a
 * code change rather than a content one, and twenty-four toggles a row is not
 * something anyone would want to maintain. With CSV the first line is the
 * headings and the shape of the table is content.
 *
 * The CSV arrives from a wysiwyg field, so it carries paragraph markup; that is
 * stripped before parsing. A wysiwyg rather than a textarea because a textarea
 * is a control type and cannot sit inside a repeater, and a plain text field
 * would give a single-line input for a whole table.
 *
 * @var array         $attributes
 * @var WP_Block|null $block  Null in the editor preview.
 */

$heading  = (string) ($attributes['heading'] ?? '');
$tabs     = $attributes['tabs'] ?? [];
$footnote = (string) ($attributes['footnote'] ?? '');

// $block is null in the editor preview.
$is_preview = ! isset($block) || $block === null;

$firstCol = max(12, min(45, (int) ($attributes['firstColumnWidth'] ?? 24)));

/**
 * Turn the authored CSV into rows.
 *
 * Accepts the wysiwyg's markup, a plain paste, or the contents of a linked
 * file. Blank lines are dropped so a trailing newline does not become an empty
 * row.
 */
$parse_csv = static function (string $raw): array {
    /* <br> and </p> are line breaks before tags are stripped, or every row
       would run together into one line. */
    $text = preg_replace('#<br\s*/?>#i', "\n", $raw);
    $text = preg_replace('#</p\s*>#i', "\n", (string) $text);
    $text = wp_strip_all_tags((string) $text);
    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');

    $rows = [];

    foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
        if (trim($line) === '') {
            continue;
        }

        $rows[] = array_map('trim', str_getcsv($line));
    }

    return $rows;
};

/** Which cell values mean "this applies". */
$is_tick = static function (string $cell): bool {
    return in_array(strtolower(trim($cell)), ['x', 'yes', 'y', 'true', '1', '✓', '✔'], true);
};

/**
 * Read a linked CSV once per hour rather than on every render.
 *
 * Only a file on this site is read, and only through the media library: a URL
 * pointing anywhere else would make rendering a page depend on a third party,
 * and would let an edited link turn into a request to an arbitrary host.
 */
$read_file = static function (string $url): string {
    if ($url === '') {
        return '';
    }

    $id = attachment_url_to_postid($url);

    if (! $id) {
        return '';
    }

    $key    = 'cadco_csv_' . $id . '_' . get_post_modified_time('U', true, $id);
    $cached = get_transient($key);

    if (is_string($cached)) {
        return $cached;
    }

    $path = get_attached_file($id);

    if (! $path || ! is_readable($path)) {
        return '';
    }

    $body = (string) file_get_contents($path);

    set_transient($key, $body, HOUR_IN_SECONDS);

    return $body;
};

$reveal = $is_preview ? '' : 'data-proto-animate="manual" data-cadco-reveal-group';

$wrapper = get_block_wrapper_attributes([
    'class' => 'cadco-comparison-table w-full bg-gradient-to-b from-[#e8edf4] via-[#f4f7fa] to-paper pt-[142px] pb-[176px]',
]);

$panelId = static function (int $i): string {
    return 'cadco-cmp-' . $i;
};
?>
<section <?php echo $wrapper; ?> <?php echo $reveal; ?> data-cadco-comparison>
    <div class="mx-auto w-full max-w-[1140px] px-6">

        <div class="flex flex-col gap-6 md:flex-row md:items-center md:justify-between">
            <h2 data-proto-field="heading"
                data-cadco-reveal="rise"
                class="m-0 font-display text-[28px] font-bold leading-[1.2] text-true-black md:text-[36px]">
                <?php echo esc_html($heading); ?>
            </h2>

            <?php if (count((array) $tabs) > 1) : ?>
                <div class="flex flex-wrap gap-3" role="tablist" aria-label="<?php esc_attr_e('Product group', 'cadco-theme'); ?>">
                    <?php foreach ((array) $tabs as $i => $tab) : ?>
                        <button type="button"
                                role="tab"
                                id="<?php echo esc_attr($panelId((int) $i) . '-tab'); ?>"
                                aria-controls="<?php echo esc_attr($panelId((int) $i)); ?>"
                                aria-selected="<?php echo $i === 0 ? 'true' : 'false'; ?>"
                                data-cadco-cmp-tab="<?php echo (int) $i; ?>"
                                class="cadco-cmp__tab h-9 rounded-[8px] border px-4 font-display text-[14px] font-bold leading-none transition-colors">
                            <?php echo esc_html((string) ($tab['label'] ?? '')); ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if (empty($tabs) && $is_preview) : ?>
            <p class="mt-10 text-body-sm text-gray-500">
                <?php esc_html_e('Add a tab in the block sidebar and paste its table as CSV.', 'cadco-theme'); ?>
            </p>
        <?php endif; ?>

        <div data-proto-repeater="tabs" class="mt-[40px]">
            <?php foreach ((array) $tabs as $i => $tab) : ?>
                <?php
                $linked = $read_file((string) (($tab['csvFile']['url'] ?? '')));
                $rows   = $parse_csv($linked !== '' ? $linked : (string) ($tab['csv'] ?? ''));
                $head   = array_shift($rows);
                $cols   = is_array($head) ? count($head) : 0;
                $rest   = $cols > 1 ? round((100 - $firstCol) / ($cols - 1), 4) : 0;
                ?>
                <div data-proto-repeater-item
                     id="<?php echo esc_attr($panelId((int) $i)); ?>"
                     role="tabpanel"
                     aria-labelledby="<?php echo esc_attr($panelId((int) $i) . '-tab'); ?>"
                     data-cadco-cmp-panel="<?php echo (int) $i; ?>"
                     <?php echo $i === 0 ? '' : 'hidden'; ?>>

                    <?php /* The tab's own name is editable here rather than only in
                             the button above, which is rendered from the same value. */ ?>
                    <span data-proto-field="label" class="sr-only"><?php echo esc_html((string) ($tab['label'] ?? '')); ?></span>

                    <?php if (empty($head)) : ?>
                        <p class="rounded-[16px] bg-white p-8 text-body-sm text-gray-500">
                            <?php esc_html_e('This tab has no table yet. Paste its CSV, or link a .csv file.', 'cadco-theme'); ?>
                        </p>
                    <?php else : ?>
                        <?php /* The frame draws a white card with the table inside it;
                                 the wrapper scrolls sideways on a narrow screen so a
                                 seven-column table never widens the page. */ ?>
                        <div class="cadco-cmp__card overflow-x-auto rounded-[16px] bg-white">
                            <table class="w-full min-w-[860px] table-fixed border-collapse text-left">
                                <thead>
                                    <tr>
                                        <?php foreach ($head as $c => $cell) : ?>
                                            <th scope="col"
                                                style="width:<?php echo esc_attr($c === 0 ? $firstCol : $rest); ?>%"
                                                class="border-b border-[#e3e8ee] px-4 py-[17px] font-display text-[14px] font-bold leading-[19px] text-true-black <?php echo $c === 0 ? 'pl-7' : 'text-center'; ?>">
                                                <?php echo esc_html($cell); ?>
                                            </th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>

                                <tbody>
                                    <?php foreach ($rows as $row) : ?>
                                        <tr>
                                            <?php for ($c = 0; $c < $cols; $c++) : ?>
                                                <?php $cell = (string) ($row[$c] ?? ''); ?>
                                                <td class="border-b border-[#edf1f5] px-4 py-[25px] font-display text-[15px] leading-[24px] text-true-black <?php echo $c === 0 ? 'pl-7' : 'text-center'; ?>">
                                                    <?php if ($c === 0) : ?>
                                                        <?php echo esc_html($cell); ?>
                                                    <?php elseif ($is_tick($cell)) : ?>
                                                        <svg class="mx-auto h-[26px] w-[26px] text-[#2f86e0]" viewBox="0 0 26 20" fill="none" aria-hidden="true">
                                                            <path d="M2 10.5 9.2 18 24 2.5" stroke="currentColor" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round" />
                                                        </svg>
                                                        <span class="sr-only"><?php esc_html_e('Included', 'cadco-theme'); ?></span>
                                                    <?php else : ?>
                                                        <span class="sr-only"><?php esc_html_e('Not included', 'cadco-theme'); ?></span>
                                                    <?php endif; ?>
                                                </td>
                                            <?php endfor; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>

                            <?php if ($footnote !== '' || $is_preview) : ?>
                                <div data-proto-field="footnote"
                                     class="cadco-cmp__note px-7 pb-7 pt-5 font-display text-[13px] font-normal leading-[20px] text-true-black">
                                    <?php echo wp_kses_post($footnote); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
