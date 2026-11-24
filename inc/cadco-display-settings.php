<?php

/**
 * Products → Display Settings.
 *
 * Catalogue-wide display choices that are not a property of any one block or
 * any one product, and so have nowhere else to live. A block control would
 * have to be set again on every page carrying that block, and a product field
 * would have to be set again on every product.
 *
 * There is one decision here so far: whether a product that names a Parent
 * Product in the workbook belongs in the catalogue listing at all. CADCO's
 * accessories — a steam pan holder sold for four sizes of warming table, a
 * drip tray for six — are products in their own right with their own page and
 * their own UPC, but as catalogue entries they bury the equipment they attach
 * to. Hiding them leaves the listing to the equipment and reaches the
 * accessories from the product they belong to.
 *
 * The settings are read on the front end, so this file loads on every request;
 * only the screen itself is registered inside wp-admin.
 */

declare(strict_types=1);

final class CADCO_Display_Settings
{
    private const SLUG       = 'cadco-display-settings';
    private const OPTION     = 'cadco_display_settings';
    private const GROUP      = 'cadco_display_settings_group';
    private const CAPABILITY = 'manage_woocommerce';

    /** Children are added to the existing Related Products row. */
    public const PLACEMENT_RELATED = 'related';

    /** Children get their own row, above Related Products. */
    public const PLACEMENT_SECTION = 'section';

    public static function init(): void
    {
        if (is_admin()) {
            add_action('admin_menu', [self::class, 'menu']);
            add_action('admin_init', [self::class, 'register']);
        }
    }

    /**
     * @return array{hide_children: bool, children_placement: string, children_heading: string, related_children_only: bool}
     */
    public static function defaults(): array
    {
        return [
            // Off, so that activating this release changes nothing until
            // somebody chooses it. Hiding products is not a change to make on
            // a site's behalf.
            'hide_children'      => false,
            'children_placement' => self::PLACEMENT_RELATED,
            'children_heading'   => __('Accessories', 'cadco-theme'),
            // Off: the row keeps filling from the product's categories.
            'related_children_only' => false,
        ];
    }

    /**
     * @return array{hide_children: bool, children_placement: string, children_heading: string, related_children_only: bool}
     */
    public static function all(): array
    {
        $stored = get_option(self::OPTION);

        return self::sanitise(is_array($stored) ? $stored : []);
    }

    /**
     * Whether a product that names a Parent Product is kept out of the
     * catalogue listings.
     */
    public static function hide_children(): bool
    {
        return self::all()['hide_children'];
    }

    /**
     * Whether the Related Products row shows only the product's own children.
     * A product with none then shows no row, rather than a row of unrelated
     * products from the same category.
     */
    public static function related_children_only(): bool
    {
        return self::all()['related_children_only'];
    }

    public static function children_placement(): string
    {
        return self::all()['children_placement'];
    }

    /**
     * The heading for the children's own row. Never empty: an empty box in
     * the settings screen means "use the default" rather than "no heading",
     * because a row of cards with no heading reads as a layout fault.
     */
    public static function children_heading(): string
    {
        return self::all()['children_heading'];
    }

    public static function menu(): void
    {
        add_submenu_page(
            'edit.php?post_type=product',
            __('Display settings', 'cadco-theme'),
            __('Display Settings', 'cadco-theme'),
            self::CAPABILITY,
            self::SLUG,
            [self::class, 'render']
        );
    }

    public static function register(): void
    {
        register_setting(self::GROUP, self::OPTION, [
            'type'              => 'array',
            'sanitize_callback' => [self::class, 'sanitise'],
            'default'           => self::defaults(),
        ]);
    }

    /**
     * @param mixed $input
     * @return array{hide_children: bool, children_placement: string, children_heading: string, related_children_only: bool}
     */
    public static function sanitise($input): array
    {
        $defaults = self::defaults();
        $input    = is_array($input) ? $input : [];

        $placement = (string) ($input['children_placement'] ?? '');

        if (!in_array($placement, [self::PLACEMENT_RELATED, self::PLACEMENT_SECTION], true)) {
            $placement = $defaults['children_placement'];
        }

        $heading = sanitize_text_field((string) ($input['children_heading'] ?? ''));

        return [
            'hide_children'      => !empty($input['hide_children']),
            'children_placement' => $placement,
            'children_heading'   => $heading !== '' ? $heading : $defaults['children_heading'],
            'related_children_only' => !empty($input['related_children_only']),
        ];
    }

    public static function render(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to change these settings.', 'cadco-theme'));
        }

        $settings = self::all();
        $defaults = self::defaults();
        $children = self::linked_product_count();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Display settings', 'cadco-theme'); ?></h1>

            <p class="description" style="max-width:46em">
                <?php esc_html_e('How the catalogue presents products that belong to another product. These apply across the site, not to one page.', 'cadco-theme'); ?>
            </p>

            <?php if ($children === 0) : ?>
                <div class="notice notice-info inline" style="max-width:46em;margin:1em 0">
                    <p>
                        <?php esc_html_e('No product currently names a Parent Product, so these settings will not change anything yet. The link is created by the Parent Product column when products are imported.', 'cadco-theme'); ?>
                    </p>
                </div>
            <?php endif; ?>

            <form action="options.php" method="post">
                <?php settings_fields(self::GROUP); ?>

                <h2 class="title"><?php esc_html_e('Products with a parent', 'cadco-theme'); ?></h2>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Catalogue listing', 'cadco-theme'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox"
                                       name="<?php echo esc_attr(self::OPTION); ?>[hide_children]"
                                       value="1" <?php checked($settings['hide_children']); ?>>
                                <?php esc_html_e('Hide products that have a parent from the product listing', 'cadco-theme'); ?>
                            </label>
                            <p class="description">
                                <?php
                                printf(
                                    /* translators: %d: number of products that name a parent. */
                                    esc_html__('They keep their own page and stay reachable by direct link; they are reached from the product they belong to. Also applies to the listing\'s refinement filter and to automatically chosen featured products. %d product(s) currently name a parent.', 'cadco-theme'),
                                    (int) $children
                                );
                                ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php esc_html_e('Where children appear', 'cadco-theme'); ?></th>
                        <td>
                            <fieldset>
                                <legend class="screen-reader-text">
                                    <?php esc_html_e('Where children appear on the parent product page', 'cadco-theme'); ?>
                                </legend>

                                <label style="display:block;margin-bottom:.5em">
                                    <input type="radio"
                                           name="<?php echo esc_attr(self::OPTION); ?>[children_placement]"
                                           value="<?php echo esc_attr(self::PLACEMENT_RELATED); ?>"
                                        <?php checked($settings['children_placement'], self::PLACEMENT_RELATED); ?>>
                                    <?php esc_html_e('Add them to the Related Products row', 'cadco-theme'); ?>
                                </label>

                                <label style="display:block">
                                    <input type="radio"
                                           name="<?php echo esc_attr(self::OPTION); ?>[children_placement]"
                                           value="<?php echo esc_attr(self::PLACEMENT_SECTION); ?>"
                                        <?php checked($settings['children_placement'], self::PLACEMENT_SECTION); ?>>
                                    <?php esc_html_e('Give them their own row, above Related Products', 'cadco-theme'); ?>
                                </label>
                            </fieldset>

                            <p class="description">
                                <?php esc_html_e('Added to the Related Products row, a product\'s children come first and the rest of the row fills with the same category as before.', 'cadco-theme'); ?>
                            </p>
                        </td>
                    </tr>

                    <?php
                    /* Only meaningful with the second option, so it is only
                       shown with it. The initial state is decided here rather
                       than by the script below, so the field does not appear
                       and then vanish on every page load; the script takes
                       over from the first time the choice is changed. With
                       scripting off it follows the saved choice, which is the
                       honest fallback -- the field reappears once the choice
                       that uses it has been saved. */
                    $headingRowStyle = $settings['children_placement'] === self::PLACEMENT_SECTION
                        ? ''
                        : ' style="display:none"';
                    ?>
                    <tr id="cadco-children-heading-row"<?php echo $headingRowStyle; ?>>
                        <th scope="row">
                            <label for="cadco-children-heading">
                                <?php esc_html_e('Row heading', 'cadco-theme'); ?>
                            </label>
                        </th>
                        <td>
                            <input type="text" class="regular-text" id="cadco-children-heading"
                                   name="<?php echo esc_attr(self::OPTION); ?>[children_heading]"
                                   value="<?php echo esc_attr($settings['children_heading']); ?>">
                            <p class="description">
                                <?php
                                printf(
                                    /* translators: %s: the default heading. */
                                    esc_html__('Heading for the children\'s own row. Leave empty for %s.', 'cadco-theme'),
                                    '<code>' . esc_html($defaults['children_heading']) . '</code>'
                                );
                                ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <h2 class="title"><?php esc_html_e('Related Products', 'cadco-theme'); ?></h2>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Products shown', 'cadco-theme'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox"
                                       name="<?php echo esc_attr(self::OPTION); ?>[related_children_only]"
                                       value="1" <?php checked($settings['related_children_only']); ?>>
                                <?php esc_html_e('Only show the product\'s own child products', 'cadco-theme'); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e('Hides the products the row otherwise adds from the same category. A product with no children shows no Related Products row.', 'cadco-theme'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
        self::heading_visibility_script();
    }

    /**
     * Show the row heading only while the row it names is the chosen one.
     *
     * Inline rather than a file: it is eleven lines, it belongs to this screen
     * alone, and a separate .js would be one more request and one more thing
     * to keep in step with the field names it depends on. Those names are
     * passed in from the constants rather than written out again here, so
     * renaming the option cannot leave the script pointing at nothing.
     */
    private static function heading_visibility_script(): void
    {
        $field = sprintf('%s[children_placement]', self::OPTION);

        $js = sprintf(
            '(function () {
    var name = %s, section = %s;
    var radios = document.querySelectorAll(\'input[name="\' + name + \'"]\');
    var row = document.getElementById("cadco-children-heading-row");

    if (!row || !radios.length) {
        return;
    }

    function sync() {
        var chosen = document.querySelector(\'input[name="\' + name + \'"]:checked\');
        row.style.display = chosen && chosen.value === section ? "" : "none";
    }

    Array.prototype.forEach.call(radios, function (radio) {
        radio.addEventListener("change", sync);
    });

    sync();
}());',
            wp_json_encode($field),
            wp_json_encode(self::PLACEMENT_SECTION)
        );

        wp_print_inline_script_tag($js);
    }

    /**
     * How many published products name a parent.
     *
     * Shown on the screen so that somebody turning the first setting on can
     * see whether it has anything to act on — the link arrives with an import,
     * and before the first one the answer is none.
     */
    private static function linked_product_count(): int
    {
        global $wpdb;

        if (!post_type_exists('product')) {
            return 0;
        }

        return (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT pm.post_id)
               FROM {$wpdb->postmeta} pm
         INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
              WHERE pm.meta_key = '_cadco_parent_model'
                AND pm.meta_value <> ''
                AND p.post_type = 'product'
                AND p.post_status = 'publish'"
        );
    }
}

CADCO_Display_Settings::init();
