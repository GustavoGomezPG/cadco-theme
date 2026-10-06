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
 * A resource points somewhere: a PDF to download, or a video to watch. The link
 * is a plain meta field rather than a taxonomy or the post body, because it is
 * one value the card needs and nothing else reads.
 */
add_action('init', static function (): void {
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
            printf(
                '<p><input type="url" name="cadco_resource_url" value="%s" class="widefat" placeholder="https://" /></p>
                 <p class="description">%s</p>',
                esc_attr($value),
                esc_html__('Where the card goes: the PDF, the video, or the page that holds it.', 'cadco-theme')
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
});
