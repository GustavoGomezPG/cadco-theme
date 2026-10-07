<?php
/**
 * Cadco Contact.
 *
 * The introduction and the ways to reach Cadco on the left, a form on the right.
 *
 * The form is chosen rather than written. Gravity Forms already owns fields,
 * validation, notifications and the entries; rebuilding any of that here would
 * mean a second place to maintain and a form the client cannot edit. The block
 * picks one by id and renders it, and the id comes from a select that Gravity
 * Forms itself populates, so a renamed form keeps working and a deleted one
 * fails visibly rather than silently posting nowhere.
 *
 * @var array         $attributes
 * @var WP_Block|null $block  Null in the editor preview.
 */

$heading     = (string) ($attributes['heading'] ?? '');
$body        = (string) ($attributes['body'] ?? '');
$formHeading = (string) ($attributes['formHeading'] ?? '');
$links       = $attributes['links'] ?? [];
$formId      = (string) ($attributes['formId'] ?? '');
$showTitle   = (bool) ($attributes['showTitle'] ?? false);

// $block is null in the editor preview.
$is_preview = ! isset($block) || $block === null;

/* Literal classes so Tailwind's scanner sees them. */
$surface = ($attributes['surface'] ?? 'paper') === 'tint' ? 'bg-[#eef2f6]' : 'bg-paper';

$reveal = $is_preview ? '' : 'data-proto-animate="manual" data-cadco-reveal-group';

/** Which icon a contact link gets, read from the link itself. */
$icon_for = static function (string $url): string {
    if (str_starts_with(strtolower($url), 'tel:'))    { return 'phone'; }
    if (str_starts_with(strtolower($url), 'mailto:')) { return 'mail'; }
    return 'link';
};

/* 62px below, not the 120 a section usually takes: the frame closes this one
   tight under the submit button, and the band beneath opens with its own space. */
$wrapper = get_block_wrapper_attributes([
    'class' => 'cadco-contact w-full ' . $surface . ' pt-[101px] pb-[62px]',
]);
?>
<section <?php echo $wrapper; ?> <?php echo $reveal; ?>>
    <div class="mx-auto w-full max-w-[1140px] px-6">
        <div class="flex flex-col gap-14 lg:flex-row lg:items-start lg:justify-between lg:gap-[80px]"
             data-cadco-reveal="items">

            <?php // ---------- Left: who to talk to ---------- ?>
            <div class="w-full lg:w-[472px] lg:shrink-0">
                <h1 data-proto-field="heading"
                    data-cadco-reveal="lines"
                    class="m-0 font-display text-[28px] font-bold leading-[43px] text-true-black md:text-[32px]">
                    <?php echo esc_html($heading); ?>
                </h1>

                <?php // Always rendered, empty or not, so it stays editable. ?>
                <p data-proto-field="body"
                   class="m-0 mt-6 font-display text-[16px] leading-[24px] text-[#11181c]">
                    <?php echo esc_html($body); ?>
                </p>

                <?php if (! empty($links) || $is_preview) : ?>
                    <div data-proto-repeater="links" class="mt-[34px] flex flex-col items-start gap-[13px]">
                        <?php foreach ($links as $row) :
                            $link = $row['link'] ?? [];
                            $url  = (string) ($link['url'] ?? '');
                            $text = (string) ($link['text'] ?? '');
                            $icon = $icon_for($url);
                            ?>
                            <div data-proto-repeater-item>
                                <a data-proto-field="link"
                                   href="<?php echo esc_url($url); ?>"
                                   <?php if (! empty($link['target'])) : ?>target="<?php echo esc_attr($link['target']); ?>"<?php endif; ?>
                                   <?php if (! empty($link['rel'])) : ?>rel="<?php echo esc_attr($link['rel']); ?>"<?php endif; ?>
                                   class="inline-flex min-h-[44px] items-center gap-2 rounded-[10px] border border-cadco-blue px-4 py-2 font-display text-[15px] font-bold leading-none text-cadco-blue no-underline transition-colors hover:bg-cadco-blue/5 md:min-h-[36px]">
                                    <?php /* Inline so each takes the link's colour and needs no asset. */ ?>
                                    <?php if ('phone' === $icon) : ?>
                                        <svg class="h-4 w-4 shrink-0" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                                            <path d="M6.3 3.5 8 7l-1.6 1.3a11 11 0 0 0 5.3 5.3L13 12l3.5 1.7v3A1.3 1.3 0 0 1 15.2 18 13.5 13.5 0 0 1 2 4.8 1.3 1.3 0 0 1 3.3 3.5h3Z"
                                                  stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" />
                                        </svg>
                                    <?php elseif ('mail' === $icon) : ?>
                                        <svg class="h-4 w-4 shrink-0" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                                            <rect x="2.5" y="4.5" width="15" height="11" rx="2" stroke="currentColor" stroke-width="1.5" />
                                            <path d="m3.5 6 6.5 4.5L16.5 6" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" />
                                        </svg>
                                    <?php endif; ?>
                                    <?php echo esc_html($text); ?>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php // ---------- Right: the form ---------- ?>
            <div class="w-full lg:w-[558px] lg:shrink-0">
                <h2 data-proto-field="formHeading"
                    class="m-0 mb-[29px] font-display text-[17px] font-bold leading-[1.2] text-true-black">
                    <?php echo esc_html($formHeading); ?>
                </h2>

                <div class="cadco-form">
                    <?php if ('' === $formId) : ?>
                        <?php /* Said plainly, and only to someone who can act on it. A
                                 visitor should never be told the page is misconfigured. */ ?>
                        <?php if (current_user_can('edit_posts')) : ?>
                            <p class="m-0 rounded-[10px] border border-dashed border-[#c2cfd8] px-5 py-6 font-display text-[15px] text-[#4a5a63]">
                                <?php esc_html_e('Choose a form in this block’s settings.', 'cadco-theme'); ?>
                            </p>
                        <?php endif; ?>
                    <?php elseif (! function_exists('gravity_form')) : ?>
                        <?php if (current_user_can('edit_posts')) : ?>
                            <p class="m-0 rounded-[10px] border border-dashed border-[#c2cfd8] px-5 py-6 font-display text-[15px] text-[#4a5a63]">
                                <?php esc_html_e('Gravity Forms is not active, so this form cannot be shown.', 'cadco-theme'); ?>
                            </p>
                        <?php endif; ?>
                    <?php elseif ($is_preview) : ?>
                        <?php /* The editor renders the block server-side without the
                                 form's scripts, and a half-initialised Gravity form is
                                 worse than a description of one. */ ?>
                        <p class="m-0 rounded-[10px] border border-dashed border-[#c2cfd8] px-5 py-6 font-display text-[15px] text-[#4a5a63]">
                            <?php
                            $formTitle = class_exists('GFAPI') ? (GFAPI::get_form((int) $formId)['title'] ?? '') : '';
                            printf(
                                /* translators: %s is the chosen form's title. */
                                esc_html__('The “%s” form appears here on the page.', 'cadco-theme'),
                                esc_html($formTitle !== '' ? $formTitle : __('selected', 'cadco-theme'))
                            );
                            ?>
                        </p>
                    <?php else : ?>
                        <?php gravity_form((int) $formId, $showTitle, false, false, null, true, 1, true); ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</section>
