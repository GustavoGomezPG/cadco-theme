<?php
/**
 * Resource library: the post type behind the resource centre, and the two
 * taxonomies it is filtered by.
 *
 * Media type is hierarchical and product is not, which follows what each one is
 * for rather than habit: a resource has exactly one kind -- it is a video or a
 * catalogue, never both -- and that kind is a fixed list the client chooses
 * from, which is what a hierarchical taxonomy's term picker gives. The products
 * a resource covers are an open set, several per resource, and new ones arrive
 * with the catalogue, so they behave like tags.
 *
 * Both are registered against the `resource` post type only. The product
 * taxonomy is deliberately NOT shared with WooCommerce's own product post type:
 * a term here means "this resource is about that product", which is a different
 * statement from WooCommerce's own taxonomies and would muddle both if merged.
 */

add_action('init', static function (): void {
    register_post_type('resource', [
        'labels' => [
            'name'               => __('Resources', 'cadco-theme'),
            'singular_name'      => __('Resource', 'cadco-theme'),
            'add_new_item'       => __('Add New Resource', 'cadco-theme'),
            'edit_item'          => __('Edit Resource', 'cadco-theme'),
            'search_items'       => __('Search Resources', 'cadco-theme'),
            'not_found'          => __('No resources found', 'cadco-theme'),
            'all_items'          => __('All Resources', 'cadco-theme'),
        ],
        'public'       => true,
        'show_in_rest' => true,          // so the block editor and the REST route can both read it
        'menu_icon'    => 'dashicons-media-document',
        'menu_position'=> 22,
        'has_archive'  => false,         // the resource centre page is the archive
        'rewrite'      => ['slug' => 'resources', 'with_front' => false],
        'supports'     => ['title', 'editor', 'excerpt', 'thumbnail', 'revisions'],
    ]);

    register_taxonomy('resource_media', ['resource'], [
        'labels' => [
            'name'          => __('Media Types', 'cadco-theme'),
            'singular_name' => __('Media Type', 'cadco-theme'),
            'all_items'     => __('All Media Types', 'cadco-theme'),
        ],
        'public'            => true,
        'show_in_rest'      => true,
        'hierarchical'      => true,     // a fixed list to choose from, not free text
        'show_admin_column' => true,
        'rewrite'           => ['slug' => 'media-type', 'with_front' => false],
    ]);

    register_taxonomy('resource_product', ['resource'], [
        'labels' => [
            'name'          => __('Products', 'cadco-theme'),
            'singular_name' => __('Product', 'cadco-theme'),
            'all_items'     => __('All Products', 'cadco-theme'),
        ],
        'public'            => true,
        'show_in_rest'      => true,
        'hierarchical'      => false,    // an open set, several per resource
        'show_admin_column' => true,
        'rewrite'           => ['slug' => 'resource-product', 'with_front' => false],
    ]);
});

/**
 * Where a resource points, and what kind of destination it is.
 *
 * The kind is stored rather than guessed from the media type, because the two
 * answer different questions. Media type is what the thing IS, and the library
 * filters on it. The kind is what should HAPPEN when it is clicked, and those
 * come apart: a guide is a PDF on one row and a Dropbox folder on the next, and
 * both are guides. Three kinds cover it -- a video plays in a dialog, a file
 * opens in a new tab, a link opens in a new tab on somebody else's site -- and
 * a resource with no destination falls back to its own page.
 */
function cadco_resource_link_types(): array
{
    return [
        'video' => __('Video — plays in a dialog on the page', 'cadco-theme'),
        'file'  => __('File — opens the document in a new tab', 'cadco-theme'),
        'link'  => __('Link — opens another site in a new tab', 'cadco-theme'),
    ];
}

add_action('init', static function (): void {
    register_post_meta('resource', 'cadco_resource_link_type', [
        'type'              => 'string',
        'single'            => true,
        'show_in_rest'      => true,
        'sanitize_callback' => static fn ($v): string => array_key_exists((string) $v, cadco_resource_link_types()) ? (string) $v : '',
        'auth_callback'     => static fn (): bool => current_user_can('edit_posts'),
    ]);

    register_post_meta('resource', 'cadco_resource_url', [
        'type'              => 'string',
        'single'            => true,
        'show_in_rest'      => true,
        'sanitize_callback' => 'esc_url_raw',
        'auth_callback'     => static fn (): bool => current_user_can('edit_posts'),
    ]);
});

/** The meta box, so the link is editable without touching code. */
add_action('add_meta_boxes', static function (): void {
    add_meta_box(
        'cadco-resource-url',
        __('Resource link', 'cadco-theme'),
        static function (WP_Post $post): void {
            wp_nonce_field('cadco_resource_url', 'cadco_resource_url_nonce');
            $value = (string) get_post_meta($post->ID, 'cadco_resource_url', true);
            $kind  = (string) get_post_meta($post->ID, 'cadco_resource_link_type', true);

            echo '<p><label for="cadco_resource_link_type"><strong>' . esc_html__('What happens on click', 'cadco-theme') . '</strong></label>';
            echo '<select name="cadco_resource_link_type" id="cadco_resource_link_type" class="widefat">';
            echo '<option value="">' . esc_html__('Nothing — open this resource\'s own page', 'cadco-theme') . '</option>';

            foreach (cadco_resource_link_types() as $key => $label) {
                printf('<option value="%s"%s>%s</option>', esc_attr($key), selected($kind, $key, false), esc_html($label));
            }

            echo '</select></p>';

            printf(
                '<p><label for="cadco_resource_url"><strong>%s</strong></label>
                 <input type="url" id="cadco_resource_url" name="cadco_resource_url" value="%s" class="widefat" placeholder="https://" /></p>
                 <p class="description">%s</p>',
                esc_html__('Address', 'cadco-theme'),
                esc_attr($value),
                esc_html__('A YouTube or Vimeo address for a video; the PDF for a file; any address for a link. Leave both empty and the card opens this resource\'s own page.', 'cadco-theme')
            );
        },
        'resource',
        'side'
    );
});

add_action('save_post_resource', static function (int $post_id): void {
    if (! isset($_POST['cadco_resource_url_nonce'])
        || ! wp_verify_nonce(sanitize_key($_POST['cadco_resource_url_nonce']), 'cadco_resource_url')) {
        return;
    }

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    if (! current_user_can('edit_post', $post_id)) {
        return;
    }

    update_post_meta($post_id, 'cadco_resource_url', esc_url_raw(wp_unslash($_POST['cadco_resource_url'] ?? '')));

    $kind = sanitize_key(wp_unslash($_POST['cadco_resource_link_type'] ?? ''));
    update_post_meta($post_id, 'cadco_resource_link_type', array_key_exists($kind, cadco_resource_link_types()) ? $kind : '');
});
