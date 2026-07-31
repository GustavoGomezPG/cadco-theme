<?php
/**
 * Navigation helpers for the header block.
 *
 * The site's menu is a real WordPress navigation menu — a `wp_navigation` post,
 * edited in the Site Editor sidebar like any other menu. The header block reads
 * it rather than inventing its own list of links, so editing the menu stays a
 * normal WordPress task.
 *
 * These helpers parse that menu into a simple array, and expose its top-level
 * items to Proto-Blocks as a select `optionsSource`, so the block's "which item
 * opens which panel" settings list the actual menu items by name.
 */

/**
 * The navigation menu post to read.
 *
 * Falls back to the most recently published menu, which is what the core
 * Navigation block does when no specific menu is chosen.
 */
function cadco_get_nav_menu_post(int $menu_id = 0): ?WP_Post
{
    if ($menu_id > 0) {
        $post = get_post($menu_id);
        if ($post instanceof WP_Post && 'wp_navigation' === $post->post_type) {
            return $post;
        }
    }

    $menus = get_posts([
        'post_type'      => 'wp_navigation',
        'posts_per_page' => 1,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'post_status'    => 'publish',
    ]);

    return $menus ? $menus[0] : null;
}

/**
 * Parse a navigation menu into a flat list of top-level items.
 *
 * Returns: [ ['label' => string, 'url' => string, 'children' => [...]], ... ]
 *
 * Handles the three block types a menu can contain: navigation-link,
 * navigation-submenu (a link that also has children), and page-list, which is
 * dynamic and expanded here to the site's top-level pages so a default menu
 * still produces something usable.
 */
function cadco_get_nav_items(int $menu_id = 0): array
{
    $menu = cadco_get_nav_menu_post($menu_id);
    if (!$menu) {
        return [];
    }

    $items = [];

    foreach (parse_blocks($menu->post_content) as $block) {
        $name = $block['blockName'] ?? '';

        if ('core/navigation-link' === $name || 'core/navigation-submenu' === $name) {
            $attrs = $block['attrs'] ?? [];
            $label = $attrs['label'] ?? '';

            if ('' === $label) {
                continue;
            }

            $children = [];
            foreach ($block['innerBlocks'] ?? [] as $child) {
                $childAttrs = $child['attrs'] ?? [];
                if (!empty($childAttrs['label'])) {
                    $children[] = [
                        'label' => $childAttrs['label'],
                        'url'   => cadco_resolve_nav_url($childAttrs),
                    ];
                }
            }

            $items[] = [
                'label'    => $label,
                'url'      => cadco_resolve_nav_url($attrs),
                'children' => $children,
            ];
            continue;
        }

        if ('core/page-list' === $name) {
            foreach (get_pages(['parent' => 0, 'sort_column' => 'menu_order,post_title']) as $page) {
                $items[] = [
                    'label'    => $page->post_title,
                    'url'      => (string) get_permalink($page),
                    'children' => [],
                ];
            }
        }
    }

    return $items;
}

/**
 * Resolve a navigation item's href.
 *
 * Items that point at a post or term store an id and kind rather than a URL, so
 * a stored permalink cannot go stale. Prefer resolving from the id; fall back to
 * the literal url a custom link carries.
 */
function cadco_resolve_nav_url(array $attrs): string
{
    $kind = $attrs['kind'] ?? '';
    $id   = isset($attrs['id']) ? (int) $attrs['id'] : 0;

    if ($id > 0 && 'post-type' === $kind) {
        $link = get_permalink($id);
        if ($link) {
            return (string) $link;
        }
    }

    if ($id > 0 && 'taxonomy' === $kind) {
        $link = get_term_link($id);
        if (!is_wp_error($link)) {
            return (string) $link;
        }
    }

    return (string) ($attrs['url'] ?? '');
}

/**
 * Expose the menu's top-level items as a Proto-Blocks select source.
 *
 * Lets the header block's panel settings offer the real menu items — "Products",
 * "Support" — instead of a hardcoded list that drifts the moment someone renames
 * a menu item. The stored value is the item label, because that is what the
 * template matches on when deciding which item opens a panel.
 */
add_action('proto_blocks_register_options_providers', function ($providers) {
    $providers->register('cadco:nav-items', function (array $args): array {
        $options = [['key' => '', 'label' => '— None —']];

        foreach (cadco_get_nav_items((int) ($args['menu_id'] ?? 0)) as $item) {
            $options[] = ['key' => $item['label'], 'label' => $item['label']];
        }

        return $options;
    }, ['menu_id']);
});
