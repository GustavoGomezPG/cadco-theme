<?php
/**
 * Fitting catalogue text into the places that show it.
 *
 * The workbook's product names run to 150 characters and a short description
 * can list a dozen comma-separated claims. A card is 234px wide, so the card
 * cuts both to a fixed budget, on a word boundary, with an ellipsis. And many
 * short descriptions are the product name again, word for word give or take
 * the brand — printed under the name that is noise, so it is left out.
 *
 * Pure functions, unit-tested without WordPress.
 */

declare(strict_types=1);

/**
 * $text cut to at most $max characters, on a word boundary, with '…' when
 * anything was cut.
 */
function cadco_text_truncate(string $text, int $max): string
{
    $text = trim((string) preg_replace('~\s+~u', ' ', $text));

    if ($max <= 1 || mb_strlen($text) <= $max) {
        return $text;
    }

    $cut  = mb_substr($text, 0, $max - 1);
    $last = mb_strrpos($cut, ' ');

    // Back to the last whole word, unless that would throw most of it away
    // (one very long word): then cut mid-word rather than show almost nothing.
    if ($last !== false && $last >= (int) floor(($max - 1) * 0.6)) {
        $cut = mb_substr($cut, 0, $last);
    }

    return rtrim($cut, " \t,;:–—-/(") . '…';
}

/**
 * The words of a string, lower-cased, without punctuation or noise.
 *
 * @return list<string>
 */
function cadco_text_words(string $text): array
{
    $text  = mb_strtolower(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $words = preg_split('~[^\pL\pN]+~u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

    return array_values(array_filter($words, static fn (string $w): bool => mb_strlen($w) > 1 || ctype_digit($w)));
}

/**
 * Whether a line says nothing the title has not already said: nearly every
 * one of its words is in the title. 'Bakerlux' and 'Stainless Steel' added to
 * a restated name do not make it new information.
 */
function cadco_text_repeats(string $line, string $title, float $threshold = 0.75): bool
{
    $words = cadco_text_words($line);

    if ($words === []) {
        return true;
    }

    $in_title = array_flip(cadco_text_words($title));
    $found    = 0;

    foreach ($words as $word) {
        if (isset($in_title[$word])) {
            $found++;
        }
    }

    return $found / count($words) >= $threshold;
}

/**
 * The lines a card shows: at most $max_lines, at most $max_chars in all, the
 * last one cut with an ellipsis when the budget runs out mid-line.
 *
 * @param list<string> $lines
 * @return list<string>
 */
function cadco_text_fit_lines(array $lines, int $max_chars, int $max_lines): array
{
    $out    = [];
    $budget = $max_chars;

    foreach ($lines as $line) {
        $line = trim((string) preg_replace('~\s+~u', ' ', $line));

        if ($line === '') {
            continue;
        }

        if (count($out) === $max_lines || $budget < 12) {
            // Something was left out: say so on the last line shown.
            if ($out !== [] && !str_ends_with($out[count($out) - 1], '…')) {
                $out[count($out) - 1] = rtrim($out[count($out) - 1], " .,;:") . '…';
            }

            break;
        }

        $fitted = cadco_text_truncate($line, $budget);
        $out[]  = $fitted;
        $budget -= mb_strlen($fitted);

        if (str_ends_with($fitted, '…')) {
            break;
        }
    }

    return $out;
}
