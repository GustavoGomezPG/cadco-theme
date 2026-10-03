<?php
/**
 * One milestone's copy: year, title, body.
 *
 * Included from template.php inside the repeater loop, so `$item` is the
 * current milestone. It is a partial rather than duplicated markup because the
 * above-rail and below-rail arrangements differ only in which side the image
 * sits on — the copy itself is identical, and two copies of it would drift.
 *
 * @var array $item
 */

$year  = (string) ($item['year'] ?? '');
$title = (string) ($item['title'] ?? '');
$body  = (string) ($item['body'] ?? '');
?>
<p data-proto-field="year"
   class="m-0 font-display text-[24px] font-bold leading-[1.2] text-cadco-blue">
    <?php echo esc_html($year); ?>
</p>

<h3 data-proto-field="title"
    class="m-0 mt-2 font-display text-[24px] font-bold leading-[1.2] text-true-black">
    <?php echo esc_html($title); ?>
</h3>

<p data-proto-field="body"
   class="m-0 mt-3 font-display text-[15px] font-normal leading-[24px] text-true-black">
    <?php echo esc_html($body); ?>
</p>
