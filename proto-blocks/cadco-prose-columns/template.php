<?php
/**
 * Cadco Prose Columns.
 *
 * A section heading over two columns of rich text. The warranty page uses it
 * twice: once for the coverage and exclusions, once for the additional
 * information below them.
 *
 * Each column is a single wysiwyg region rather than a set of discrete fields.
 * The columns are runs of prose whose own headings, paragraphs and lists belong
 * to the copy, and the two instances on the warranty page do not even have the
 * same shape -- one is two headed passages and a button, the other is three.
 * Splitting that into fields would have fixed an order the copy owns.
 *
 * @var array         $attributes
 * @var WP_Block|null $block  Null in the editor preview.
 */

$heading = (string) ($attributes['heading'] ?? '');
$left    = (string) ($attributes['left'] ?? '');
$right   = (string) ($attributes['right'] ?? '');
$cta     = $attributes['cta'] ?? [];

$ctaUrl  = (string) ($cta['url'] ?? '');
$ctaText = (string) ($cta['text'] ?? '');

// $block is null in the editor preview.
$is_preview = ! isset($block) || $block === null;

/* Literal classes so Tailwind's scanner sees them. */
$surface = ($attributes['surface'] ?? 'paper') === 'tint' ? 'bg-[#eef2f6]' : 'bg-paper';
$outline = ($attributes['ctaStyle'] ?? 'outline') !== 'solid';

$reveal = $is_preview ? '' : 'data-proto-animate="manual" data-cadco-reveal-group';

$wrapper = get_block_wrapper_attributes([
    'class' => 'cadco-prose-columns w-full ' . $surface . ' py-[88px] md:pt-[96px] md:pb-[98px]',
]);
?>
<section <?php echo $wrapper; ?> <?php echo $reveal; ?>>
    <div class="mx-auto w-full max-w-[1140px] px-6">

        <?php // Rendered whenever it has copy, and always in the editor so it stays editable. ?>
        <?php if ($heading !== '' || $is_preview) : ?>
            <h2 data-proto-field="heading"
                data-cadco-reveal="lines"
                class="m-0 mb-12 font-display text-[28px] font-bold leading-[1.2] text-true-black md:text-[36px]">
                <?php echo esc_html($heading); ?>
            </h2>
        <?php endif; ?>

        <div class="flex flex-col gap-12 lg:flex-row lg:items-start lg:justify-between lg:gap-[64px]"
             data-cadco-reveal="items">

            <div class="w-full lg:w-[542px] lg:shrink-0">
                <div data-proto-field="left" class="cadco-prose">
                    <?php echo wp_kses_post($left); ?>
                </div>

                <?php if ($ctaUrl !== '' || $ctaText !== '' || $is_preview) : ?>
                    <a data-proto-field="cta"
                       href="<?php echo esc_url($ctaUrl); ?>"
                       <?php if (! empty($cta['target'])) : ?>target="<?php echo esc_attr($cta['target']); ?>"<?php endif; ?>
                       <?php if (! empty($cta['rel'])) : ?>rel="<?php echo esc_attr($cta['rel']); ?>"<?php endif; ?>
                       class="mt-10 inline-flex min-h-[44px] items-center gap-2 rounded-[10px] px-5 py-2 md:min-h-0 md:h-9 md:py-0 font-display text-[15px] font-bold leading-none no-underline transition-colors <?php
                           echo $outline
                               ? 'border border-cadco-blue text-cadco-blue hover:bg-cadco-blue/5'
                               : 'bg-cadco-blue text-white hover:bg-[#00395a]'; ?>">
                        <?php if ($outline) : ?>
                            <?php /* Inline so it takes the link's colour and needs no asset. */ ?>
                            <svg class="h-4 w-4 shrink-0" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                                <path d="M6.3 3.5 8 7l-1.6 1.3a11 11 0 0 0 5.3 5.3L13 12l3.5 1.7v3A1.3 1.3 0 0 1 15.2 18 13.5 13.5 0 0 1 2 4.8 1.3 1.3 0 0 1 3.3 3.5h3Z"
                                      stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" />
                            </svg>
                        <?php endif; ?>
                        <?php echo esc_html($ctaText); ?>
                    </a>
                <?php endif; ?>
            </div>

            <div class="w-full lg:w-[486px] lg:shrink-0">
                <div data-proto-field="right" class="cadco-prose">
                    <?php echo wp_kses_post($right); ?>
                </div>
            </div>
        </div>
    </div>
</section>
