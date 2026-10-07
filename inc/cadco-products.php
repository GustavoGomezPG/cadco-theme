<?php
/**
 * What the product blocks read, in one place.
 *
 * The catalogue listing, the product hero and the specification band all need
 * the same few things about a product, and two of them need the same card. The
 * blocks are templates, so anything they would otherwise each re-derive lives
 * here instead.
 *
 * Specifications are read back out of the product description because that is
 * where the catalogue puts them: the importer writes a <h3>Specifications</h3>
 * list of label/value pairs and a <h3>Features</h3> list, which is also the
 * shape a human editor produces in the block editor. If a future import carries
 * them as real WooCommerce attributes instead, filter `cadco_product_spec_rows`
 * and `cadco_product_features` and nothing above this file has to change.
 */

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

    return (array) apply_filters('cadco_product_spec_rows', $rows, $product_id);
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
        'Size'  => ['Unit Dimensions', 'Cavity Size', 'Shipping Weight', 'Ship Weight', 'Size', 'Shelves', 'Capacity', 'Pan Capacity', 'Weight'],
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

    return array_values(array_filter(array_map('trim', $parts), static fn ($p): bool => $p !== ''));
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
    $lines = cadco_product_summary_lines($product_id);

    ob_start();
    ?>
    <a href="<?php echo esc_url((string) get_permalink($product_id)); ?>" class="cadco-card">

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
            <?php echo esc_html(get_the_title($product_id)); ?>
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
