<?php

/**
 * Cleans values, and records everything it changed.
 *
 * The recording matters as much as the cleaning. Under the all-or-nothing
 * validation policy these variants also block the import, so the normaliser's
 * real job is to name each near-miss precisely — 'Steam Table/ Chafer Supplies'
 * differs from 'Steam Table / Chafer Supplies' by one space, which nobody spots
 * by eye in a 238-row sheet.
 *
 * Canonical spelling is chosen by frequency: whichever variant appears most
 * often in the workbook wins. Ties go to the first seen, which is stable
 * because rows arrive in sheet order.
 */

declare(strict_types=1);

final class CADCO_Import_Normaliser
{
    /**
     * Words kept lowercase inside a title, unless they lead it.
     */
    private const SMALL_WORDS = [
        'a', 'an', 'and', 'as', 'at', 'but', 'by', 'for', 'if', 'in',
        'of', 'on', 'or', 'the', 'to', 'v', 'via', 'vs', 'with',
    ];

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{rows: list<array<string, mixed>>, changes: list<array<string, mixed>>}
     */
    public static function normalise(array $rows): array
    {
        $changes = [];

        // Pass 1 — whitespace hygiene on every column.
        foreach ($rows as $i => $row) {
            foreach ($row as $column => $value) {
                if (str_starts_with($column, '__') || !is_string($value)) {
                    continue;
                }

                $clean = self::whitespace($value, $column);

                if ($clean !== $value) {
                    $changes[]              = self::change($row, $column, $value, $clean);
                    $rows[$i][$column]      = $clean;
                }
            }
        }

        // Pass 2 — declared aliases. Applied before the frequency pass so an
        // aliased value joins its target's group rather than forming its own.
        foreach (cadco_import_value_aliases() as $column => $aliases) {
            foreach ($rows as $i => $row) {
                $value = (string) ($row[$column] ?? '');

                if ($value === '') {
                    continue;
                }

                $clean = $aliases[mb_strtolower(trim($value))] ?? $value;

                if ($clean !== $value) {
                    $changes[]         = self::change($row, $column, $value, $clean);
                    $rows[$i][$column] = $clean;
                }
            }
        }

        // Pass 3 — collapse variant spellings onto the most frequent form.
        foreach (cadco_import_consistency_columns() as $column) {
            $canonical = $column === 'Specialties'
                ? self::canonical_map_multi($rows, $column)
                : self::canonical_map_single($rows, $column);

            foreach ($rows as $i => $row) {
                $value = (string) ($row[$column] ?? '');

                if ($value === '') {
                    continue;
                }

                $clean = $column === 'Specialties'
                    ? self::apply_multi($value, $canonical)
                    : ($canonical[self::canonical_key($value)] ?? $value);

                if ($clean !== $value) {
                    $changes[]         = self::change($row, $column, $value, $clean);
                    $rows[$i][$column] = $clean;
                }
            }
        }

        return ['rows' => array_values($rows), 'changes' => $changes];
    }

    /**
     * Trim, collapse runs of spaces, and tidy comma spacing in Type.
     *
     * Line breaks are preserved: the bullet columns depend on them.
     */
    private static function whitespace(string $value, string $column): string
    {
        $value = preg_replace('/[ \t]+/', ' ', $value) ?? $value;
        $value = preg_replace('/[ \t]*\n[ \t]*/', "\n", $value) ?? $value;
        $value = trim($value);

        if ($column === 'Type') {
            $value = preg_replace('/\s*,\s*/', ', ', $value) ?? $value;
        }

        return $value;
    }

    /**
     * Title-case that leaves deliberate capitalisation alone.
     *
     * A word is recapitalised only when it is entirely upper or entirely lower
     * case. 'MobileServ®' and 'VariKwik' carry internal capitals on purpose, so
     * they pass through untouched — ucwords() would flatten them.
     */
    public static function title_case(string $value): string
    {
        $words = preg_split('/(\s+)/u', $value, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $out   = '';
        $index = 0;

        foreach ($words as $word) {
            if (trim($word) === '') {
                $out .= $word;
                continue;
            }

            $bare = preg_replace('/[^\p{L}]/u', '', $word) ?? $word;
            $mixed = $bare !== ''
                && mb_strtoupper($bare) !== $bare
                && mb_strtolower($bare) !== $bare;

            if (!$mixed) {
                $lower = mb_strtolower($word);
                $word  = ($index > 0 && in_array(rtrim($lower, ".,;:"), self::SMALL_WORDS, true))
                    ? $lower
                    : mb_strtoupper(mb_substr($lower, 0, 1)) . mb_substr($lower, 1);
            }

            $out .= $word;
            $index++;
        }

        return $out;
    }

    /**
     * Split a bullet block into its individual values.
     *
     * A bullet appearing mid-line is a lost line break, not a separator between
     * two values — 'Countertop & Tabletop •Cooking/Warming' is one tag whose
     * canonical spelling has no inner bullet. It is removed rather than split
     * on, because splitting would invent a tag that does not exist elsewhere.
     *
     * @return list<string>
     */
    public static function bullets(string $value): array
    {
        $out = [];

        foreach (explode("\n", $value) as $line) {
            $line = ltrim(trim($line), "•*- \t");
            $line = preg_replace('/\s*•\s*/u', ' ', $line) ?? $line;
            $line = trim(preg_replace('/\s+/u', ' ', $line) ?? $line, " \t\"'");

            if ($line !== '' && !in_array($line, $out, true)) {
                $out[] = $line;
            }
        }

        return $out;
    }

    /**
     * The key two spellings share when they mean the same thing.
     */
    public static function canonical_key(string $value): string
    {
        $key = mb_strtolower(trim($value));
        $key = str_replace(['"', "'", '•'], '', $key);
        $key = preg_replace('/\s*\/\s*/u', '/', $key) ?? $key;
        $key = preg_replace('/\s+/u', ' ', $key) ?? $key;

        return trim($key);
    }

    /**
     * Winning spelling per canonical key, for a single-value column.
     *
     * @return array<string, string>
     */
    private static function canonical_map_single(array $rows, string $column): array
    {
        $counts = [];

        foreach ($rows as $row) {
            $value = trim((string) ($row[$column] ?? ''));

            if ($value !== '') {
                $counts[self::canonical_key($value)][$value] ??= 0;
                $counts[self::canonical_key($value)][$value]++;
            }
        }

        return self::winners($counts);
    }

    /**
     * @return array<string, string>
     */
    private static function canonical_map_multi(array $rows, string $column): array
    {
        $counts = [];

        foreach ($rows as $row) {
            foreach (self::bullets((string) ($row[$column] ?? '')) as $value) {
                $counts[self::canonical_key($value)][$value] ??= 0;
                $counts[self::canonical_key($value)][$value]++;
            }
        }

        return self::winners($counts);
    }

    /**
     * @param array<string, array<string, int>> $counts
     * @return array<string, string>
     */
    private static function winners(array $counts): array
    {
        $winners = [];

        foreach ($counts as $key => $spellings) {
            arsort($spellings);
            $winners[$key] = (string) array_key_first($spellings);
        }

        return $winners;
    }

    /**
     * @param array<string, string> $canonical
     */
    private static function apply_multi(string $value, array $canonical): string
    {
        $out = [];

        foreach (self::bullets($value) as $item) {
            $clean = $canonical[self::canonical_key($item)] ?? $item;

            if (!in_array($clean, $out, true)) {
                $out[] = $clean;
            }
        }

        return implode("\n", array_map(static fn ($v) => '•' . $v, $out));
    }

    private static function change(array $row, string $column, string $before, string $after): array
    {
        return [
            'sheet'  => (string) ($row['__sheet'] ?? ''),
            'row'    => (int) ($row['__row'] ?? 0),
            'model'  => (string) ($row['Model #'] ?? ''),
            'column' => $column,
            'before' => $before,
            'after'  => $after,
        ];
    }
}
