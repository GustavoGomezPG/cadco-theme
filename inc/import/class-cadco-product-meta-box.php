<?php

/**
 * The CADCO Specifications panel on the product editor.
 *
 * Read-only on purpose. The workbook is the single source of truth, so
 * anything typed here would be overwritten by the next import — offering
 * editable inputs would be an invitation to lose work. The panel exists so
 * that imported values can be inspected and bad data spotted, and it links
 * back to the sheet and row each product came from.
 */

declare(strict_types=1);

final class CADCO_Product_Meta_Box
{
    private const PREFIX = '_cadco_';

    public static function init(): void
    {
        add_action('add_meta_boxes', [self::class, 'register']);
    }

    public static function register(): void
    {
        add_meta_box(
            'cadco-specifications',
            __('CADCO Specifications', 'cadco-theme'),
            [self::class, 'render'],
            'product',
            'normal',
            'default'
        );
    }

    /**
     * @return array<string, list<string>> group label => meta key suffixes
     */
    private static function groups(): array
    {
        return [
            __('Electrical', 'cadco-theme')  => ['wattage', 'amps'],
            __('Packaging', 'cadco-theme')   => ['package_height', 'package_width', 'package_length', 'package_weight'],
            // 'upc' is deliberately absent: it lives in WooCommerce's own
            // GTIN field on the Inventory tab, so repeating it here would
            // show the same value twice in two different places.
            __('Compliance', 'cadco-theme')  => ['prop65_affected', 'prop65_warning', 'warranty_info', 'warranty_url'],
            __('Documents', 'cadco-theme')   => ['spec_sheet_url', 'manual_url', 'video_url', 'image_url'],
            __('Catalogue', 'cadco-theme')   => ['footnote', 'disclaimer', 'second_category', 'cubic_feet', 'approvals', 'parent_model', 'legacy_url'],
            __('Source', 'cadco-theme')      => ['source_sheet', 'source_row', 'notes', 'import_hash'],
        ];
    }

    public static function render(WP_Post $post): void
    {
        $labels = array_flip(cadco_import_meta_columns());

        echo '<p class="description">';
        esc_html_e('Imported from the product workbook. These values are read-only: the workbook is the source of truth, so anything changed here is overwritten by the next import.', 'cadco-theme');
        echo '</p>';

        foreach (self::groups() as $group => $keys) {
            $rows = '';

            foreach ($keys as $key) {
                $value = get_post_meta($post->ID, self::PREFIX . $key, true);

                if ($value === '' || $value === false) {
                    continue;
                }

                $label = $labels[$key] ?? ucwords(str_replace('_', ' ', $key));
                $rows .= sprintf(
                    '<tr><th scope="row" style="width:16em">%s</th><td>%s</td></tr>',
                    esc_html($label),
                    self::format((string) $value)
                );
            }

            if ($rows === '') {
                continue;
            }

            printf('<h4 style="margin:1.2em 0 .3em">%s</h4>', esc_html($group));
            printf('<table class="widefat striped"><tbody>%s</tbody></table>', $rows);
        }
    }

    /**
     * A media cell may hold several links, so a value is rendered as a list
     * whenever more than one is found rather than as a single anchor. A cell
     * that is exactly one URL keeps its original single-anchor form; anything
     * with no link at all — 'n/a', or one of the bare filenames the workbook
     * still carries in these columns — stays plain text, which is what makes
     * those visible as the gaps they are.
     */
    private static function format(string $value): string
    {
        if (!class_exists('CADCO_Import_Media')) {
            return nl2br(esc_html($value));
        }

        // Every link the cell holds, resolved the way the importer resolves
        // it: a relative SharePoint link gets the tenant back on the front, a
        // gateway-wrapped link shows its real target. A link that cannot be
        // followed (a PC path) is listed as text, so it reads as the problem
        // it is.
        $tenant = (string) get_option('cadco_import_sharepoint_tenant', '');
        $items  = [];

        foreach (CADCO_Import_Media::links($value) as $link) {
            $url     = CADCO_Import_Media::resolve($link, $tenant)['url'];
            $items[] = $url !== '' ? self::link($url) : '<code>' . esc_html($link) . '</code>';
        }

        if ($items === []) {
            return nl2br(esc_html($value));
        }

        if (count($items) === 1) {
            return $items[0];
        }

        return sprintf('<ol style="margin:0;padding-left:1.4em"><li>%s</li></ol>', implode('</li><li>', $items));
    }

    private static function link(string $url): string
    {
        return sprintf(
            '<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
            esc_url($url),
            esc_html($url)
        );
    }
}
