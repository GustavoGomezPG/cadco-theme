<?php

/**
 * Everything a run will do, decided before anything is written.
 *
 * The plan is what the operator approves. Because the dry run is simply the
 * pipeline stopping here, whatever this object says is exactly what the
 * applier will carry out — the preview cannot drift from the result.
 *
 * The category-derivation rules live here rather than in the applier because
 * this is the object that names the terms, and the admin preview has to show
 * those names before any term exists.
 */

declare(strict_types=1);

final class CADCO_Import_Plan
{
    /** @var list<array<string, mixed>> */
    private array $creates = [];

    /** @var list<array<string, mixed>> */
    private array $updates = [];

    /** @var list<array<string, mixed>> */
    private array $renames = [];

    /** @var list<array<string, mixed>> */
    private array $trashes = [];

    /** @var list<string> */
    private array $skips = [];

    public function add_create(array $row): void
    {
        $this->creates[] = ['row' => $row];
    }

    /**
     * @param array<string, array{0: string, 1: string}> $diff column => [before, after]
     */
    public function add_update(array $row, int $post_id, array $diff): void
    {
        $this->updates[] = ['row' => $row, 'post_id' => $post_id, 'diff' => $diff];
    }

    public function add_rename(array $row, int $post_id, string $old_sku): void
    {
        $this->renames[] = [
            'row'      => $row,
            'post_id'  => $post_id,
            'old_sku'  => $old_sku,
            'new_sku'  => (string) ($row['Model #'] ?? ''),
            'upc'      => (string) ($row['UPC#'] ?? ''),
            'approved' => false,
        ];
    }

    /**
     * Mark a rename as approved by the operator.
     *
     * Renames are opt-in and the planner never sets this. An unapproved
     * rename is simply not queued, so the product is left exactly as it is
     * rather than being renamed on a guess.
     */
    public function approve_rename(int $index): void
    {
        if (isset($this->renames[$index])) {
            $this->renames[$index]['approved'] = true;
        }
    }

    public function add_trash(int $post_id, string $sku): void
    {
        $this->trashes[] = ['post_id' => $post_id, 'sku' => $sku];
    }

    public function add_skip(string $sku): void
    {
        $this->skips[] = $sku;
    }

    public function creates(): array
    {
        return $this->creates;
    }

    public function updates(): array
    {
        return $this->updates;
    }

    public function renames(): array
    {
        return $this->renames;
    }

    public function trashes(): array
    {
        return $this->trashes;
    }

    public function skips(): array
    {
        return $this->skips;
    }

    /**
     * @return array{create:int,update:int,rename:int,trash:int,skip:int}
     */
    public function counts(): array
    {
        return [
            'create' => count($this->creates),
            'update' => count($this->updates),
            'rename' => count($this->renames),
            'trash'  => count($this->trashes),
            'skip'   => count($this->skips),
        ];
    }

    /**
     * Skips are excluded: an unchanged row costs no writes at all.
     */
    public function total_writes(): int
    {
        return count($this->creates) + count($this->updates)
            + count($this->renames) + count($this->trashes);
    }

    public function is_empty(): bool
    {
        return $this->total_writes() === 0;
    }

    /**
     * The parent/child category pairs a row belongs to.
     *
     * Top level is the sheet name, sub-level is Type. A comma-separated Type
     * places the product in several sub-categories, all beneath the sheet it
     * is listed on.
     *
     * @return list<array{parent: string, child: string}>
     */
    public static function categories_for(array $row): array
    {
        $parent = CADCO_Import_Normaliser::title_case(trim((string) ($row['__sheet'] ?? '')));
        $type   = trim((string) ($row['Type'] ?? ''));

        if ($parent === '' || $type === '') {
            return [];
        }

        $pairs = [];

        foreach (explode(',', $type) as $part) {
            $child = CADCO_Import_Normaliser::title_case(trim($part));

            if ($child === '') {
                continue;
            }

            $pair = ['parent' => $parent, 'child' => $child];

            if (!in_array($pair, $pairs, true)) {
                $pairs[] = $pair;
            }
        }

        return $pairs;
    }

    /**
     * @return list<string>
     */
    public static function tags_for(array $row): array
    {
        return CADCO_Import_Normaliser::bullets((string) ($row['Specialties'] ?? ''));
    }

    /**
     * Every term the workbook implies, deduplicated.
     *
     * @param list<array<string, mixed>> $rows
     * @return array{categories: list<array{parent:string,child:string}>, tags: list<string>, brands: list<string>}
     */
    public static function all_terms(array $rows): array
    {
        $categories = [];
        $tags       = [];
        $brands     = [];

        foreach ($rows as $row) {
            foreach (self::categories_for($row) as $pair) {
                if (!in_array($pair, $categories, true)) {
                    $categories[] = $pair;
                }
            }

            foreach (self::tags_for($row) as $tag) {
                if (!in_array($tag, $tags, true)) {
                    $tags[] = $tag;
                }
            }

            $brand = trim((string) ($row['Brand Name'] ?? ''));

            if ($brand !== '' && !in_array($brand, $brands, true)) {
                $brands[] = $brand;
            }
        }

        return ['categories' => $categories, 'tags' => $tags, 'brands' => $brands];
    }
}
