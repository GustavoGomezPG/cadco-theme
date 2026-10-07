<?php
/**
 * Cadco Sales Reps.
 *
 * A card per representative, filtered by the state a visitor is in.
 *
 * The filter's options are derived from the representatives rather than kept as
 * a separate list of states, so a state can never be offered that nobody covers,
 * and adding a territory to a rep is the whole job. Filtering runs in the
 * browser because every card is already on the page -- there is no second page
 * to fetch, and reloading to hide two cards would be slower and lose the
 * visitor's place.
 *
 * @var array         $attributes
 * @var WP_Block|null $block  Null in the editor preview.
 */

$heading     = (string) ($attributes['heading'] ?? '');
$reps        = $attributes['reps'] ?? [];
$filterLabel = (string) ($attributes['filterLabel'] ?? 'Select State');

// $block is null in the editor preview.
$is_preview = ! isset($block) || $block === null;

/* Literal classes so Tailwind's scanner sees them. */
$surface = ($attributes['surface'] ?? 'tint') === 'paper' ? 'bg-paper' : 'bg-[#e8edf5]';

/** Split a territory into its states, trimmed, with the design's trailing ellipsis dropped. */
$states_of = static function (string $territory): array {
    $parts = array_map('trim', explode(',', $territory));
    $parts = array_map(static fn (string $p): string => rtrim($p, ". \u{2026}"), $parts);

    return array_values(array_filter($parts, static fn (string $p): bool => '' !== $p));
};

/* Every state anybody covers, in alphabetical order, each offered once. */
$allStates = [];

foreach ($reps as $rep) {
    foreach ($states_of((string) ($rep['territory'] ?? '')) as $state) {
        $allStates[$state] = true;
    }
}

$allStates = array_keys($allStates);
sort($allStates, SORT_NATURAL | SORT_FLAG_CASE);

$reveal = $is_preview ? '' : 'data-proto-animate="manual" data-cadco-reveal-group';

$wrapper = get_block_wrapper_attributes([
    'class' => 'cadco-sales-reps w-full ' . $surface . ' pt-[79px] pb-[120px]',
]);
?>
<section <?php echo $wrapper; ?> <?php echo $reveal; ?> data-cadco-reps>
    <?php /* Narrower than the site's usual container, because the frame runs it
             that way: the cards are 288px across where this page's others are
             354, and stretching them to the standard measure left the row
             reaching 100px past where the frame ends it. */ ?>
    <div class="mx-auto w-full max-w-[943px] px-6">

        <div class="mb-[40px] flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
            <h2 data-proto-field="heading"
                class="m-0 font-display text-[17px] font-bold leading-[1.2] text-true-black">
                <?php echo esc_html($heading); ?>
            </h2>

            <?php if (! empty($allStates) && ! $is_preview) : ?>
                <label class="relative inline-flex">
                    <span class="sr-only"><?php echo esc_html($filterLabel); ?></span>
                    <select data-cadco-reps-filter
                            class="cadco-reps__select min-h-[44px] w-full cursor-pointer appearance-none rounded-[8px] border border-cadco-blue bg-white py-2 pl-5 pr-10 font-display text-[15px] font-bold text-cadco-blue focus:outline-none focus-visible:ring-2 focus-visible:ring-cadco-blue sm:min-h-[36px] sm:w-[151px]">
                        <option value=""><?php echo esc_html($filterLabel); ?></option>
                        <?php foreach ($allStates as $state) : ?>
                            <option value="<?php echo esc_attr(strtolower($state)); ?>"><?php echo esc_html($state); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endif; ?>
        </div>

        <div data-proto-repeater="reps"
             <?php /* auto-rows-fr so every row is the same height. Without it a row sizes
                 to its tallest card, and since the logos are different shapes --
                 the frame's own are 215, 236 and 172 wide -- the rows stepped
                 down the page as the logos got shorter. */ ?>
             class="grid auto-rows-fr grid-cols-1 gap-[15px] sm:grid-cols-2 lg:grid-cols-3"
             data-cadco-reveal="items">

            <?php foreach ($reps as $rep) :
                $logo      = $rep['logo'] ?? [];
                $name      = (string) ($rep['name'] ?? '');
                $territory = (string) ($rep['territory'] ?? '');
                $website   = (string) ($rep['website'] ?? '');
                $phone     = (string) ($rep['phone'] ?? '');
                $email     = (string) ($rep['email'] ?? '');
                $address   = (string) ($rep['address'] ?? '');
                $states    = $states_of($territory);

                /* A card is only worth opening when there is something behind it. */
                $hasDetail = '' !== $phone || '' !== $email || '' !== $address || '' !== $website;
                ?>
                <div data-proto-repeater-item
                     data-cadco-rep
                     data-states="<?php echo esc_attr(strtolower(implode('|', $states))); ?>"
                     <?php if ($hasDetail && ! $is_preview) : ?>
                         data-rep-name="<?php echo esc_attr($name); ?>"
                         data-rep-logo="<?php echo esc_attr((string) ($logo['url'] ?? '')); ?>"
                         data-rep-logo-alt="<?php echo esc_attr((string) ($logo['alt'] ?? '')); ?>"
                         data-rep-territory="<?php echo esc_attr($territory); ?>"
                         data-rep-phone="<?php echo esc_attr($phone); ?>"
                         data-rep-email="<?php echo esc_attr($email); ?>"
                         data-rep-address="<?php echo esc_attr($address); ?>"
                         data-rep-website="<?php echo esc_attr($website); ?>"
                     <?php endif; ?>
                     class="flex flex-col rounded-[14px] bg-white px-[26px] pb-[30px] pt-[26px]<?php echo $hasDetail ? ' cadco-rep--openable' : ''; ?>">

                    <div class="flex h-[164px] items-center justify-center">
                        <span data-proto-field="logo" class="block">
                            <?php if (! empty($logo['url'])) : ?>
                                <img src="<?php echo esc_url($logo['url']); ?>"
                                     alt="<?php echo esc_attr($logo['alt'] ?? ''); ?>"
                                     class="block h-auto max-h-[150px] w-auto max-w-[223px]"
                                     loading="lazy" />
                            <?php endif; ?>
                        </span>
                    </div>

                    <?php /* Plain text, not a link. The card is the control: a link
                             around the name would be a second target inside it,
                             competing with the dialog and giving a keyboard two
                             stops where the reader sees one thing. The website
                             goes in the dialog with the phone and the email. */ ?>
                    <h3 class="m-0 mt-[14px] font-display text-[17px] font-bold leading-[1.25] text-true-black">
                        <span data-proto-field="name"><?php echo esc_html($name); ?></span>
                    </h3>

                    <p data-proto-field="territory"
                       class="m-0 mt-3 line-clamp-3 font-display text-[14px] leading-[17px] text-[#11181c]">
                        <?php echo esc_html($territory); ?>
                    </p>

                    <?php /* A real button rather than a click handler on the card, so
                             it is reachable by keyboard and announced as a control.
                             It covers the card through CSS, which keeps the card's own
                             markup -- the logo, the heading, the territory -- as
                             ordinary content rather than burying it inside a button. */ ?>
                    <?php if ($hasDetail && ! $is_preview) : ?>
                        <button type="button" data-cadco-rep-open
                                class="cadco-rep__open">
                            <span class="sr-only"><?php
                                printf(
                                    /* translators: %s is the representative's name. */
                                    esc_html__('Contact details for %s', 'cadco-theme'),
                                    esc_html($name)
                                );
                            ?></span>
                        </button>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <?php /* One dialog for the grid: only one rep is read at a time, and
                 <dialog> brings the focus trap, Escape and the inert background
                 with it. Filled by the script from the card that opened it. */ ?>
        <?php if (! $is_preview) : ?>
            <dialog data-cadco-rep-modal
                    class="w-[min(520px,92vw)] rounded-[16px] border-0 bg-white p-0 backdrop:bg-black/60"
                    aria-labelledby="cadco-rep-modal-title">
                <div class="flex items-start justify-between gap-4 px-7 pt-6">
                    <span data-rep-modal-logo class="flex h-[76px] items-center"></span>
                    <button type="button" data-cadco-rep-close
                            class="-mr-2 flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-[#4a5a63] transition-colors hover:bg-black/5"
                            aria-label="<?php esc_attr_e('Close', 'cadco-theme'); ?>">
                        <svg class="h-5 w-5" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                            <path d="m4 4 12 12M16 4 4 16" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                        </svg>
                    </button>
                </div>

                <div class="px-7 pb-8 pt-2">
                    <h3 id="cadco-rep-modal-title" data-rep-modal-name
                        class="m-0 font-display text-[21px] font-bold leading-[1.25] text-true-black"></h3>

                    <p data-rep-modal-territory
                       class="m-0 mt-2 font-display text-[15px] leading-[23px] text-[#4a5a63]"></p>

                    <p data-rep-modal-address
                       class="m-0 mt-5 font-display text-[16px] leading-[24px] text-[#11181c]"></p>

                    <div class="mt-5 flex flex-col items-start gap-[10px]" data-rep-modal-links></div>
                    <p class="m-0 mt-4"><a data-rep-modal-site target="_blank" rel="noopener noreferrer"
                       class="font-display text-[15px] font-bold text-cadco-blue underline underline-offset-4"></a></p>
                </div>
            </dialog>
        <?php endif; ?>

        <?php /* Spoken, not just shown: a filter that empties the grid with no word
                 about it looks broken. Hidden until the script needs it. */ ?>
        <p data-cadco-reps-empty hidden role="status"
           class="mt-10 text-center font-display text-[17px] text-[#4a5a63]">
            <?php esc_html_e('No representative is listed for that state yet.', 'cadco-theme'); ?>
        </p>
    </div>
</section>
