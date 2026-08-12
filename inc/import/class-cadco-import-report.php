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

        fputcsv($handle, ['Tier', 'Sheet', 'Row', 'Column', 'Found', 'Problem', 'How to fix'], ',', '"', '');

        foreach ($this->issues as $issue) {
            fputcsv($handle, [
                $issue->tier,
                $issue->sheet,
                $issue->row === null ? '' : (string) $issue->row,
                $issue->column,
                $issue->found,
                $issue->message,
                $issue->fix,
            ], ',', '"', '');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
