<?php
/**
 * Block: Cadco Logo Row
 *
 * @var array    $attributes Block attributes.
 * @var string   $innerBlocksContent Nested blocks HTML (inner-blocks field only).
 * @var WP_Block $block      Block instance.
 */

// Check if we're in editor preview mode (no block instance = preview)
$is_preview = ! isset( $block ) || $block === null;

$title = $attributes['title'] ?? '';
$content = $attributes['content'] ?? '';

$wrapper_attributes = get_block_wrapper_attributes( [
    'class' => 'wp-block-proto-blocks-cadco-logo-row',
] );
?>

<div <?php echo $wrapper_attributes; ?>>
    <h2 class="wp-block-proto-blocks-cadco-logo-row__title" data-proto-field="title"><?php
        if ( ! empty( $title ) ) {
            echo esc_html( $title );
        }
    ?></h2>

    <div class="wp-block-proto-blocks-cadco-logo-row__content" data-proto-field="content"><?php
        if ( ! empty( $content ) ) {
            echo wp_kses_post( $content );
        }
    ?></div>

</div>
