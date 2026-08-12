<?php

/**
 * The result of validating a workbook.
 *
 * The report is the primary deliverable of this system, not a side effect.
 * Because validation is all-or-nothing, a failing report is the entire output
 * of a run — so it has to be legible enough to hand to CADCO as-is.
 */

declare(strict_types=1);

final class CADCO_Import_Report
{
    private const TIERS = ['A', 'B', 'C'];

    /** @var list<CADCO_Import_Issue> */
    private array $issues = [];

    public function add(CADCO_Import_Issue $issue): void
    {
        $this->issues[] = $issue;
    }

    /**
     * @param list<CADCO_Import_Issue> $issues
     */
    public function add_many(array $issues): void
    {
        foreach ($issues as $issue) {
            $this->add($issue);
        }
    }

    /**
     * Any issue at all blocks the import. There is no severity threshold.
     */
    public function passed(): bool
    {
        return $this->issues === [];
    }

    public function count(): int
    {
        return count($this->issues);
    }

    /**
     * @return list<CADCO_Import_Issue>
     */
    public function all(): array
    {
        return $this->issues;
    }

    /**
     * @return array<string, list<CADCO_Import_Issue>>
     */
    public function by_tier(): array
    {
        $grouped = [];

        foreach (self::TIERS as $tier) {
            $matching = array_values(array_filter(
                $this->issues,
                static fn (CADCO_Import_Issue $i): bool => $i->tier === $tier
            ));

            if ($matching !== []) {
                $grouped[$tier] = $matching;
            }
        }

        return $grouped;
    }

    /**
     * @return array<string, int>
     */
    public function tier_counts(): array
    {
        return array_map('count', $this->by_tier());
    }

    public function to_csv(): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return '';
        }

        fputcsv($handle, array_map(
            [self::class, 'csv_safe'],
            ['Tier', 'Sheet', 'Row', 'Column', 'Found', 'Problem', 'How to fix']
        ), ',', '"', '');

        foreach ($this->issues as $issue) {
            fputcsv($handle, array_map([self::class, 'csv_safe'], [
                $issue->tier,
                $issue->sheet,
                $issue->row === null ? '' : (string) $issue->row,
                $issue->column,
                $issue->found,
                $issue->message,
                $issue->fix,
            ]), ',', '"', '');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * Neutralise a value that a spreadsheet application would treat as a formula.
     *
     * Every value in this export comes from the uploaded workbook, and the
     * recipients open these files in Excel. A cell beginning '=', '+', '-', '@',
     * tab or carriage return is executed on open, so a product name is enough to
     * run code on someone else's machine. Prefixing an apostrophe is the standard
     * mitigation: Excel treats the value as literal text and does not display it.
     *
     * Shared with CADCO_Import_Admin::maybe_export_redirects(), the other CSV
     * export in this system — both exports carry values that trace back to the
     * uploaded workbook, so the same defence applies to both rather than living
     * twice.
     */
    public static function csv_safe(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'" . $value : $value;
    }
}
