<?php

/**
 * Decides whether the workbook may be imported at all.
 *
 * All three tiers block. That is a deliberate choice by the site owner: the
 * catalogue is only as trustworthy as the sheet behind it, so the sheet is
 * cleaned at source rather than patched on the way in.
 *
 * The consequence is that this class produces the run's entire output most of
 * the time. Every issue therefore carries a location and a concrete fix — a
 * report saying "12 problems" without saying where would be worthless.
 */

declare(strict_types=1);

final class CADCO_Import_Validator
{
    /**
     * @param list<array<string, mixed>> $rows normalised rows
     * @param list<array<string, mixed>> $structural_errors from the Reader
     */
    public static function validate(array $rows, array $structural_errors = []): CADCO_Import_Report
    {
        $report = new CADCO_Import_Report();

        foreach ($structural_errors as $error) {
            $report->add(new CADCO_Import_Issue(
                (string) ($error['tier'] ?? 'A'),
                (string) ($error['sheet'] ?? ''),
                isset($error['row']) ? (int) $error['row'] : null,
                (string) ($error['column'] ?? ''),
                '',
                (string) ($error['message'] ?? ''),
                'Correct the workbook structure and re-upload.'
            ));
        }

        self::tier_a($rows, $report);
        self::tier_b($rows, $report);
        self::tier_c($rows, $report);

        return $report;
    }

    /**
     * Identity. Without a unique, well-formed key per product the planner
     * cannot tell an update from a new product.
     */
    private static function tier_a(array $rows, CADCO_Import_Report $report): void
    {
        $models = [];
        $upcs   = [];

        foreach ($rows as $row) {
            $model = self::get($row, 'Model #');
            $upc   = self::get($row, 'UPC#');

            if ($model !== '') {
                $models[$model][] = $row;
            }

            if ($upc !== '') {
                $upcs[$upc][] = $row;
            }

            if ($upc !== '' && preg_match(cadco_import_upc_pattern(), $upc) !== 1) {
                $report->add(new CADCO_Import_Issue(
                    'A',
                    self::sheet($row),
                    self::row_no($row),
                    'UPC#',
                    $upc,
                    'The UPC is not in the canonical format.',
                    'Rewrite it as NNNNNN-NNNNN-N, for example 654796-52113-5.'
                ));
            }
        }

        foreach ($models as $model => $matching) {
            if (count($matching) > 1) {
                $report->add(new CADCO_Import_Issue(
                    'A',
                    self::sheet($matching[0]),
                    self::row_no($matching[0]),
                    'Model #',
                    $model,
                    sprintf('Model # appears %d times (%s).', count($matching), self::locations($matching)),
                    'List each product once. Use a comma-separated Type for several sub-categories instead of repeating the row.'
                ));
            }
        }

        foreach ($upcs as $upc => $matching) {
            $distinct = array_unique(array_map(
                static fn (array $r): string => self::get($r, 'Model #'),
                $matching
            ));

            if (count($distinct) > 1) {
                $report->add(new CADCO_Import_Issue(
                    'A',
                    self::sheet($matching[0]),
                    self::row_no($matching[0]),
                    'UPC#',
                    (string) $upc,
                    sprintf('UPC is shared by different products: %s.', implode(', ', $distinct)),
                    'Give each product its own UPC. The UPC is what lets a renamed product keep its page.'
                ));
            }
        }

        // Parent Product must name a model that exists in this workbook.
        foreach ($rows as $row) {
            $parent = self::get($row, 'Parent Product');

            if ($parent !== '' && !isset($models[$parent])) {
                $report->add(new CADCO_Import_Issue(
                    'A',
                    self::sheet($row),
                    self::row_no($row),
                    'Parent Product',
                    $parent,
                    'Parent Product names a Model # that is not in the workbook.',
                    'Correct the model number, or clear the cell.'
                ));
            }
        }
    }

    /**
     * Consistency. One real-world value written several ways becomes several
     * entries on the website.
     */
    private static function tier_b(array $rows, CADCO_Import_Report $report): void
    {
        foreach (cadco_import_consistency_columns() as $column) {
            $spellings = [];

            foreach ($rows as $row) {
                $values = $column === 'Specialties'
                    ? CADCO_Import_Normaliser::bullets(self::get($row, $column))
                    : array_filter([self::get($row, $column)], static fn ($v) => $v !== '');

                foreach ($values as $value) {
                    $spellings[$value] ??= $row;
                }
            }

            foreach (self::variant_groups(array_keys($spellings)) as $group) {
                $first = (array) $spellings[$group[0]];

                $report->add(new CADCO_Import_Issue(
                    'B',
                    self::sheet($first),
                    self::row_no($first),
                    $column,
                    implode(' | ', $group),
                    sprintf('The same value appears to be spelled %d different ways.', count($group)),
                    sprintf('Pick one spelling and use it everywhere, e.g. "%s".', $group[0])
                ));
            }
        }
    }

    /**
     * Group spellings that look like the same value written differently.
     *
     * This deliberately uses a looser test than the normaliser's canonical
     * key. The normaliser has already merged everything sharing that key, so
     * grouping by it here would never match anything — the interesting cases
     * are precisely the ones it could not merge safely.
     *
     * Two rules, both needed:
     *
     *   1. Same letters and digits, different punctuation or spacing.
     *      'MET (=UL & CSA)' and 'MET (= UL & CSA)'.
     *
     *   2. Within a ONE-character edit of each other, provided their digits
     *      are identical. That catches 'Healthcare facilties' against
     *      'Healthcare Facilities', the plural in 'Steam Tables/Chafer
     *      Supplies', and 'Grey' against 'Gray'.
     *
     * Both thresholds were tuned against the real workbook and are tighter
     * than they look like they should be. A distance of 2 merges
     * 'MET (=UL & CSA)' with 'ETL (=UL & CSA)' — two different certification
     * bodies — because deleting one letter and inserting another gets from
     * 'metulcsa' to 'etlulcsa'. The digits rule is what stops 'NEMA 5-15P'
     * matching 'NEMA 6-15P'. A report that cries wolf is a report CADCO stops
     * reading, so false positives cost more here than misses.
     *
     * @param list<string> $values distinct spellings
     * @return list<list<string>>
     */
    private static function variant_groups(array $values): array
    {
        $groups = [];
        $taken  = [];

        foreach ($values as $i => $a) {
            if (isset($taken[$a])) {
                continue;
            }

            $group = [$a];

            foreach (array_slice($values, $i + 1) as $b) {
                if (isset($taken[$b]) || !self::looks_like_variant($a, $b)) {
                    continue;
                }

                $group[]    = $b;
                $taken[$b]  = true;
            }

            if (count($group) > 1) {
                $taken[$a] = true;
                $groups[]  = $group;
            }
        }

        return $groups;
    }

    private static function looks_like_variant(string $a, string $b): bool
    {
        $ka = self::fuzzy_key($a);
        $kb = self::fuzzy_key($b);

        if ($ka === '' || $kb === '' || $ka === $kb) {
            return $ka === $kb;
        }

        if (self::digits($a) !== self::digits($b)) {
            return false;
        }

        return min(strlen($ka), strlen($kb)) > 3 && levenshtein($ka, $kb) <= 1;
    }

    /**
     * Letters and digits only, lowercased.
     */
    private static function fuzzy_key(string $value): string
    {
        return strtolower((string) preg_replace('/[^\p{L}\p{N}]/u', '', $value));
    }

    private static function digits(string $value): string
    {
        return (string) preg_replace('/\D/', '', $value);
    }

    /**
     * Completeness. A blank cell cannot be told apart from "not filled in
     * yet", so a field that does not apply must say n/a out loud.
     */
    private static function tier_c(array $rows, CADCO_Import_Report $report): void
    {
        $required = cadco_import_required_fields();
        $na       = cadco_import_na_fields();

        foreach ($rows as $row) {
            foreach ($required as $column) {
                if (self::get($row, $column) === '') {
                    $report->add(new CADCO_Import_Issue(
                        'C',
                        self::sheet($row),
                        self::row_no($row),
                        $column,
                        '',
                        sprintf('%s is required and is blank.', $column),
                        'Supply the real value. This field cannot be n/a.'
                    ));
                }
            }

            foreach ($na as $column) {
                // Only complain about a column the sheet actually carries.
                if (array_key_exists($column, $row) && self::get($row, $column) === '') {
                    $report->add(new CADCO_Import_Issue(
                        'C',
                        self::sheet($row),
                        self::row_no($row),
                        $column,
                        '',
                        sprintf('%s is blank.', $column),
                        'Write the value, or n/a if it genuinely does not apply to this product.'
                    ));
                }
            }
        }
    }

    private static function get(array $row, string $column): string
    {
        return trim((string) ($row[$column] ?? ''));
    }

    private static function sheet(array $row): string
    {
        return (string) ($row['__sheet'] ?? '');
    }

    private static function row_no(array $row): ?int
    {
        return isset($row['__row']) ? (int) $row['__row'] : null;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private static function locations(array $rows): string
    {
        return implode(', ', array_map(
            static fn (array $r): string => sprintf('%s row %d', self::sheet($r), (int) self::row_no($r)),
            $rows
        ));
    }
}
