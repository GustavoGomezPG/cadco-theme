<?php
/**
 * What the product blocks read, in one place.
 *
 * The catalogue listing, the product hero and the specification band all need
 * the same few things about a product, and two of them need the same card. The
 * blocks are templates, so anything they would otherwise each re-derive lives
 * here instead.
 *
 * Specifications come from a <h3>Specifications</h3> list of label/value pairs
 * in the description when an editor wrote one, and otherwise from the
 * product's structured data — native dimensions and weight, attributes,
 * `_cadco_*` meta — which is where the importer puts them
 * (inc/cadco-product-specs.php). Filter `cadco_product_spec_rows`
 * to change either.
 */

require_once __DIR__ . '/cadco-product-specs.php';
require_once __DIR__ . '/cadco-product-text.php';

/**
 * The product's spec rows as `label => value`, in document order.
 *
 * @return array<string, string>
 */
function cadco_product_spec_rows(int $product_id): array
{
    $rows = [];
    $body = (string) get_post_field('post_content', $product_id);

    /* The list that follows the Specifications heading, and only that one --
       Features follows its own heading and is a different shape. */
    if (preg_match('~<h[2-4][^>]*>\s*Specifications\s*</h[2-4]>(.*?)(?:<h[2-4]|$)~is', $body, $section)) {
        if (preg_match_all('~<li[^>]*>(.*?)</li>~is', $section[1], $items)) {
            foreach ($items[1] as $item) {
                if (!preg_match('~<strong[^>]*>(.*?)</strong>(.*)~is', $item, $pair)) {
                    continue;
                }

                $label = trim(wp_strip_all_tags($pair[1]), " \t\n\r\0\x0B:");
                $value = trim(wp_strip_all_tags($pair[2]));

                if ($label !== '' && $value !== '') {
                    $rows[$label] = $value;
                }
            }
        }
    }

    // A list written into the description is someone's deliberate choice
    // and is kept as written. Without one — every imported product — the
    // rows come from the product's structured data.
    if ($rows === []) {
        $rows = cadco_product_structured_spec_rows($product_id);
    }

    return (array) apply_filters('cadco_product_spec_rows', $rows, $product_id);
}

/**
 * A product's spec rows from its dimensions, attributes and `_cadco_*` meta.
 *
 * @return array<string, string>
 */
function cadco_product_structured_spec_rows(int $product_id): array
{
    $product = function_exists('wc_get_product') ? wc_get_product($product_id) : null;

    if (!$product instanceof WC_Product) {
        return [];
    }

    $attribute = static function (string $slug) use ($product_id): string {
        $taxonomy = 'pa_' . $slug;

        if (!taxonomy_exists($taxonomy)) {
            return '';
        }

        $names = wc_get_product_terms($product_id, $taxonomy, ['fields' => 'names']);

        return is_array($names) ? html_entity_decode(implode(', ', $names), ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
    };

    $meta = static fn (string $key): string => trim((string) get_post_meta($product_id, '_cadco_' . $key, true));

    return cadco_product_spec_rows_from_values([
        'width'          => (string) $product->get_width(),
        'depth'          => (string) $product->get_length(),
        'height'         => (string) $product->get_height(),
        'weight'         => (string) $product->get_weight(),
        'package_width'  => $meta('package_width'),
        'package_depth'  => $meta('package_length'),
        'package_height' => $meta('package_height'),
        'package_weight' => $meta('package_weight'),
        'capacity'       => $attribute('capacity'),
        'size'           => $attribute('size'),
        'cubic_feet'     => $meta('cubic_feet'),
        'voltage'        => $attribute('voltage'),
        'wattage'        => $meta('wattage'),
        'amps'           => $meta('amps'),
        'plug'           => $attribute('plug-type'),
        'material'       => $attribute('material'),
        'color'          => $attribute('color'),
        'certifications' => $attribute('certifications'),
        'approvals'      => $meta('approvals'),
        'country'        => $attribute('country-of-origin'),
        'lead_time'      => $attribute('lead-time'),
    ]);
}

/**
 * The product's feature bullets.
 *
 * @return string[]
 */
function cadco_product_features(int $product_id): array
{
    $features = [];
    $body     = (string) get_post_field('post_content', $product_id);

    if (preg_match('~<h[2-4][^>]*>\s*Features\s*</h[2-4]>(.*?)(?:<h[2-4]|$)~is', $body, $section)) {
        if (preg_match_all('~<li[^>]*>(.*?)</li>~is', $section[1], $items)) {
            foreach ($items[1] as $item) {
                $text = trim(wp_strip_all_tags($item));

                if ($text !== '') {
                    $features[] = $text;
                }
            }
        }
    }

    return (array) apply_filters('cadco_product_features', $features, $product_id);
}

/**
 * Which spec rows belong under which heading in the specification band.
 *
 * The design groups them -- Size, Power, Freight Class -- rather than printing
 * one long table, so the grouping has to be stated somewhere. Keys are matched
 * case-insensitively against the start of the row's label, so "Volts" catches
 * "Volts" and "Volts (per phase)" alike.
 *
 * @return array<string, string[]>
 */
function cadco_product_spec_groups(): array
{
    return (array) apply_filters('cadco_product_spec_groups', [
        'Size'  => ['Unit Dimensions', 'Cavity Size', 'Shipping Dimensions', 'Shipping Weight', 'Ship Weight', 'Size', 'Shelves', 'Capacity', 'Pan Capacity', 'Cubic Feet', 'Weight'],
        'Power' => ['Volts', 'Watts', 'Amps', 'Hertz', 'Phase', 'Plug', 'NEMA'],
    ]);
}

/**
 * The spec rows arranged into the design's groups.
 *
 * A row no group claims is not dropped: it falls into a trailing "Details"
 * group, so a catalogue that gains a new specification shows it rather than
 * silently losing it. Freight class is its own group and comes from meta --
 * on the source catalogue it sits beside the spec table, not inside it.
 *
 * @return array<int, array{label: string, rows: array<string, string>, text: string}>
 */
function cadco_product_grouped_specs(int $product_id): array
{
    $rows   = cadco_product_spec_rows($product_id);
    $groups = cadco_product_spec_groups();
    $out    = [];
    $taken  = [];

    foreach ($groups as $label => $prefixes) {
        $matched = [];

        foreach ($rows as $key => $value) {
            foreach ($prefixes as $prefix) {
                if (stripos($key, $prefix) === 0) {
                    $matched[$key] = $value;
                    $taken[$key]   = true;
                    break;
                }
            }
        }

        if ($matched) {
            $out[] = ['label' => $label, 'rows' => $matched, 'text' => ''];
        }
    }

    $rest = array_diff_key($rows, $taken);

    if ($rest) {
        $out[] = ['label' => __('Details', 'cadco-theme'), 'rows' => $rest, 'text' => ''];
    }

    $freight = trim((string) get_post_meta($product_id, '_cadco_freight_class', true));

    // The importer stores freight class as the Freight Class attribute.
    if ($freight === '' && taxonomy_exists('pa_freight-class')) {
        $names   = wc_get_product_terms($product_id, 'pa_freight-class', ['fields' => 'names']);
        $freight = is_array($names) ? trim(implode(', ', $names)) : '';
    }

    if (cadco_spec_is_empty($freight)) {
        $freight = '';
    }

    if ($freight !== '') {
        $out[] = ['label' => __('Freight Class', 'cadco-theme'), 'rows' => [], 'text' => $freight];
    }

    return $out;
}

/**
 * The short description as the separate claims it is made of.
 *
 * The catalogue writes it as one comma-separated line -- "3 shelf, 120v (No
 * Humidity), GO Panel" -- which the card prints as lines and the hero as
 * bullets. Commas inside brackets are not separators: "(No Humidity)" is one
 * claim however it is punctuated.
 *
 * @return string[]
 */
function cadco_product_summary_lines(int $product_id): array
{
    $summary = trim(wp_strip_all_tags((string) get_post_field('post_excerpt', $product_id)));

    if ($summary === '') {
        return [];
    }

    $parts = [];
    $depth = 0;
    $cur   = '';

    foreach (str_split($summary) as $ch) {
        if ($ch === '(') {
            $depth++;
        } elseif ($ch === ')') {
            $depth = max(0, $depth - 1);
        }

        if ($ch === ',' && $depth === 0) {
            $parts[] = $cur;
            $cur     = '';
            continue;
        }

        $cur .= $ch;
    }

    $parts[] = $cur;

    // A claim that only restates the product's name — most of the
    // workbook's short descriptions are the name again, plus 'Bakerlux' or
    // 'Stainless Steel' — tells nobody anything printed under that name.
    $title = (string) get_the_title($product_id);

    return array_values(array_filter(
        array_map('trim', $parts),
        static fn ($p): bool => $p !== '' && !cadco_text_repeats($p, $title)
    ));
}

/**
 * What a card says under the product's name.
 *
 * The short description's claims, when they say something the name does not.
 * More than half the catalogue's short descriptions only restate the name
 * ("Medium Heavy Duty Stainless Buffet Warming Shelf with Clear Rolltop Lid"
 * under "Medium Heavy Duty Warming Shelf with Clear Rolltop Lid"), which left
 * those cards blank; they fall back to the product's first feature bullets,
 * the same list the product page opens with.
 *
 * @return string[]
 */
function cadco_product_card_lines(int $product_id): array
{
    $lines = cadco_product_summary_lines($product_id);

    if ($lines !== []) {
        return $lines;
    }

    $title = (string) get_the_title($product_id);
    $intro = cadco_product_intro($product_id);
    $out   = [];

    if (preg_match_all('~<li[^>]*>(.*?)</li>~is', $intro, $items)) {
        foreach ($items[1] as $item) {
            $text = trim(html_entity_decode(wp_strip_all_tags($item), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            if ($text !== '' && !cadco_text_repeats($text, $title)) {
                $out[] = $text;
            }
        }
    }

    return $out;
}

/**
 * The product's "Additional information" list — the workbook's Secondary
 * Description, which the importer writes under that heading.
 *
 * @return string[]
 */
function cadco_product_additional_info(int $product_id): array
{
    $body  = (string) get_post_field('post_content', $product_id);
    $items = [];

    if (preg_match('~<h[2-4][^>]*>\s*Additional information\s*</h[2-4]>(.*?)(?:<h[2-4]|$)~is', $body, $section)
        && preg_match_all('~<li[^>]*>(.*?)</li>~is', $section[1], $found)
    ) {
        foreach ($found[1] as $item) {
            $text = trim(html_entity_decode(wp_strip_all_tags($item), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            if ($text !== '') {
                $items[] = $text;
            }
        }
    }

    return $items;
}

/**
 * The description's opening prose, before the first heading.
 *
 * This is the product's introduction: whatever an editor writes above the
 * specification tables. A product that goes straight into its specifications
 * has none, and the hero simply leaves the paragraph out.
 */
function cadco_product_intro(int $product_id): string
{
    $body = (string) get_post_field('post_content', $product_id);
    $head = preg_split('~<h[2-4][^>]*>~i', $body, 2)[0] ?? '';

    return trim(wp_kses_post($head));
}

/**
 * One product card, as the catalogue grid and the related-products carousel
 * both draw it.
 *
 * Returned rather than echoed so a caller can decide whether it has anything
 * to render before it opens a grid around nothing.
 *
 * Styled by assets/css/cadco-product-card.css rather than by Tailwind
 * utilities: the Tailwind scanner only reads files inside a block's own
 * folder, so utilities written here compile to nothing at all.
 */
function cadco_product_card_html(int $product_id, array $opts = []): string
{
    $opts = $opts + ['showPrice' => true, 'headingLevel' => 'h3'];

    $product = function_exists('wc_get_product') ? wc_get_product($product_id) : null;

    if (!$product) {
        return '';
    }

    $tag   = in_array($opts['headingLevel'], ['h2', 'h3', 'h4'], true) ? $opts['headingLevel'] : 'h3';
    $price = (string) $product->get_price_html();

    /* Budgets, so every card in a row ends up the same height whatever the
       workbook says: a name runs to three lines at most, the description to
       three short lines. The full name stays in the link's title. */
    $title = html_entity_decode((string) get_the_title($product_id), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $name  = cadco_text_truncate($title, (int) apply_filters('cadco_card_title_chars', 70));
    $lines = cadco_text_fit_lines(
        cadco_product_card_lines($product_id),
        (int) apply_filters('cadco_card_description_chars', 100),
        3
    );

    ob_start();
    ?>
    <a href="<?php echo esc_url((string) get_permalink($product_id)); ?>" class="cadco-card"<?php echo $name !== $title ? ' title="' . esc_attr($title) . '"' : ''; ?>>

        <span class="cadco-card__media<?php echo has_post_thumbnail($product_id) ? '' : ' cadco-card__media--empty'; ?>">
            <?php if (has_post_thumbnail($product_id)) : ?>
                <?php echo wp_get_attachment_image(get_post_thumbnail_id($product_id), 'medium_large', false, [
                    'alt'      => the_title_attribute(['echo' => false, 'post' => $product_id]),
                    'loading'  => 'lazy',
                    'decoding' => 'async',
                    /* The slot is 234px wide on the design's grid and grows from
                       there. Stated explicitly or the browser reads WordPress'
                       default `sizes` and pulls the 768px file for every card. */
                    'sizes'    => '(min-width: 1024px) 300px, (min-width: 640px) 44vw, 78vw',
                ]); ?>
            <?php else : ?>
                <img src="<?php echo esc_url(get_theme_file_uri('assets/img/cadco-mark.svg')); ?>"
                     alt="" aria-hidden="true" loading="lazy" decoding="async" />
            <?php endif; ?>
        </span>

        <<?php echo $tag; ?> class="cadco-card__title">
            <?php echo esc_html($name); ?>
        </<?php echo $tag; ?>>

        <?php if ($lines) : ?>
            <ul class="cadco-card__lines">
                <?php foreach ($lines as $line) : ?>
                    <li><?php echo esc_html($line); ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($opts['showPrice'] && $price !== '') : ?>
            <p class="cadco-card__price"><?php echo wp_kses_post($price); ?></p>
        <?php endif; ?>
    </a>
    <?php
    return (string) ob_get_clean();
}

/**
 * The resources tagged to a product.
 *
 * The link is the `resource_product` taxonomy, whose term slug matches the
 * product's own slug -- the convention the resource centre already follows.
 * It is deliberately not WooCommerce's product taxonomy: a term here says
 * "this resource is about that product", which is a different statement.
 *
 * @param string[] $mediaSlugs Restrict to these resource_media terms; empty for all.
 *
 * @return WP_Post[]
 */
function cadco_product_resources(int $product_id, array $mediaSlugs = [], int $limit = -1): array
{
    if (!taxonomy_exists('resource_product') || !post_type_exists('resource')) {
        return [];
    }

    $slug = (string) get_post_field('post_name', $product_id);

    if ($slug === '') {
        return [];
    }

    $tax = [[
        'taxonomy' => 'resource_product',
        'field'    => 'slug',
        'terms'    => $slug,
    ]];

    if ($mediaSlugs) {
        $tax[] = [
            'taxonomy' => 'resource_media',
            'field'    => 'slug',
            'terms'    => $mediaSlugs,
        ];
        $tax['relation'] = 'AND';
    }

    return get_posts([
        'post_type'      => 'resource',
        'post_status'    => 'publish',
        'posts_per_page' => $limit,
        'orderby'        => 'menu_order title',
        'order'          => 'ASC',
        'tax_query'      => $tax,
    ]);
}

/**
 * The download glyph the design uses on its buttons and resource cards.
 *
 * Inline rather than a file because the same glyph is drawn in two colours --
 * brand blue on a button, black on a card -- and an <img> cannot inherit one.
 * The path is the design's own, unchanged; only the fill is handed to CSS.
 */
function cadco_download_icon(string $classes = 'h-[15px] w-[15px]'): string
{
    return '<svg class="' . esc_attr($classes) . '" viewBox="0 0 14.7617 14.7617" fill="currentColor"'
        . ' aria-hidden="true" focusable="false">'
        . '<path d="M7.38086 11.0713L2.76782 6.45825L4.05947 5.12047L6.45825 7.51925V0H8.30347V7.51925L10.7022'
        . ' 5.12047L11.9939 6.45825L7.38086 11.0713ZM1.84521 14.7617C1.33778 14.7617 0.903386 14.581 0.542032'
        . ' 14.2197C0.180677 13.8583 0 13.4239 0 12.9165V10.1487H1.84521V12.9165H12.9165V10.1487H14.7617V12.9165C14.7617'
        . ' 13.4239 14.581 13.8583 14.2197 14.2197C13.8583 14.581 13.4239 14.7617 12.9165 14.7617H1.84521Z" />'
        . '</svg>';
}

/**
 * The published products that name this product as their Parent Product.
 *
 * Read from `_cadco_related_children`, which the importer rebuilds on every
 * run from the workbook's Parent Product column (see
 * CADCO_Import_Applier::link_related_products()). Reading the stored list
 * rather than searching the children's own `_cadco_parent_model` keeps this to
 * one meta read: an accessory names several parents in one cell, so finding
 * children the other way round means a LIKE across the whole catalogue.
 *
 * Re-checked against the posts table on the way out, because the list is only
 * as fresh as the last import — a child trashed or unpublished since then is
 * still in it, and would otherwise be rendered as a card linking to nothing.
 *
 * @return list<int>
 */
function cadco_product_children(int $product_id): array
{
    $stored = get_post_meta($product_id, '_cadco_related_children', true);

    if (!is_array($stored) || $stored === []) {
        return [];
    }

    $ids = array_values(array_unique(array_filter(array_map('intval', $stored))));

    if ($ids === []) {
        return [];
    }

    $live = get_posts([
        'post_type'              => 'product',
        'post_status'            => 'publish',
        'post__in'               => $ids,
        'orderby'                => 'post__in',
        'posts_per_page'         => count($ids),
        'fields'                 => 'ids',
        'no_found_rows'          => true,
        'ignore_sticky_posts'    => true,
        'update_post_term_cache' => false,
    ]);

    return array_map('intval', $live);
}

/**
 * Whether this product names a Parent Product in the workbook.
 */
function cadco_product_has_parent(int $product_id): bool
{
    return '' !== trim((string) get_post_meta($product_id, '_cadco_parent_model', true));
}

/**
 * The meta_query clause that keeps products with a parent out of a listing.
 *
 * Empty when the setting is off, so a caller can merge it unconditionally:
 * an empty array added to a meta_query changes nothing, where a null or a
 * malformed clause would match no rows at all.
 *
 * 'NOT EXISTS' alone is not enough. A product whose Parent Product cell was
 * filled and later emptied keeps the meta key with an empty string, so it
 * exists and is blank — the OR is what lets it back into the catalogue.
 *
 * @return array<int|string, mixed>
 */
function cadco_product_catalogue_meta_query(): array
{
    if (!class_exists('CADCO_Display_Settings') || !CADCO_Display_Settings::hide_children()) {
        return [];
    }

    return [
        'relation' => 'OR',
        ['key' => '_cadco_parent_model', 'compare' => 'NOT EXISTS'],
        ['key' => '_cadco_parent_model', 'value' => '', 'compare' => '='],
    ];
}

/**
 * Product counts per product_cat term, with hidden children left out.
 *
 * Counted the way the archive lists: a term's figure includes everything in
 * its descendants, because that is what clicking it shows and what the
 * listing's own "N results" reports. WordPress stores `count` as direct
 * assignments only, which is the same number here — the importer puts a
 * product in its sub-category and its top-level category both — but the two
 * part company the moment a product is filed only in a leaf, and the count
 * that matters is the one that matches the page.
 *
 * One query for the visible products and one for their terms, then the
 * ancestors are walked in PHP. Per-term queries would be 25 of them on a page
 * that is already drawing a category rail.
 *
 * Returns an empty array when the setting is off: there is nothing to correct,
 * and the caller leaves WordPress's own counts alone.
 *
 * @return array<int, int> term_id => number of visible products
 */
function cadco_product_term_counts(): array
{
    /* Keyed on whether the exclusion is active, not a plain static: saving the
       setting and rendering in the same request would otherwise answer from
       the cache the old setting filled. */
    static $cache = [];

    $exclusion = cadco_product_catalogue_meta_query();
    $key       = $exclusion === [] ? 'off' : 'on';

    if (isset($cache[$key])) {
        return $cache[$key];
    }

    if ($exclusion === [] || !taxonomy_exists('product_cat')) {
        return $cache[$key] = [];
    }

    $ids = get_posts([
        'post_type'              => 'product',
        'post_status'            => 'publish',
        'posts_per_page'         => -1,
        'fields'                 => 'ids',
        'no_found_rows'          => true,
        'ignore_sticky_posts'    => true,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
        'meta_query'             => $exclusion,
    ]);

    if (!$ids) {
        return $cache[$key] = [];
    }

    $assigned = wp_get_object_terms($ids, 'product_cat', ['fields' => 'all_with_object_id']);

    if (is_wp_error($assigned)) {
        return $cache[$key] = [];
    }

    /* Counted as a set per term rather than incremented, because a product
       filed in two sub-categories of the same parent reaches that parent
       twice and must still count once. */
    $seen      = [];
    $ancestors = [];

    foreach ($assigned as $term) {
        $termId = (int) $term->term_id;

        if (!isset($ancestors[$termId])) {
            $ancestors[$termId] = array_map('intval', get_ancestors($termId, 'product_cat', 'taxonomy'));
        }

        foreach (array_merge([$termId], $ancestors[$termId]) as $id) {
            $seen[$id][(int) $term->object_id] = true;
        }
    }

    return $cache[$key] = array_map('count', $seen);
}
