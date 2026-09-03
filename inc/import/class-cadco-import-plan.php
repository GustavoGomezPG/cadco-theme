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

    /** @var list<array<string, mixed>> */
    private array $untrashes = [];

    /** @var list<string> */
    private array $skips = [];

    public function add_create(array $row): void
    {
        $this->creates[] = ['row' => $row];
    }

    /**
     * @param array<string, array{0: string, 1: string}> $diff column => [before, after]
     * @param bool $snapshot_unreadable true when a snapshot postmeta row exists for
     *        this product but could not be decoded — as opposed to $diff simply
     *        being empty because no snapshot was ever stored. The two look
     *        identical to $diff (both empty) but call for different messages on
     *        the admin screen; see CADCO_Import_Admin::render_update_table().
     */
    public function add_update(array $row, int $post_id, array $diff, bool $snapshot_unreadable = false): void
    {
        $this->updates[] = [
            'row'                 => $row,
            'post_id'             => $post_id,
            'diff'                => $diff,
            'snapshot_unreadable' => $snapshot_unreadable,
        ];
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

    /**
     * A restore reusing a trashed product in place, rather than recreating
     * it under a new post ID. See CADCO_Import_Planner::plan() for why this
     * only ever happens when the caller explicitly supplies trashed
     * candidates.
     *
     * $old_sku and $matched_by record how the trashed product was
     * identified — 'sku' or 'upc' — so the operator can be shown, the same
     * way a rename is shown, that a UPC match is reusing post $post_id
     * under a different model number than it was trashed with. Unlike a
     * rename, this never needs per-item approval: a rename happens inside
     * a routine import the operator did not specifically request, so it
     * needs consent before an identity is asserted; a restore is already an
     * explicit, deliberate action aimed at one named archived workbook, so
     * requiring approval on top of that would be friction on an intent
     * already given. The operator still needs to *see* the match, which is
     * what these two fields are for.
     */
    public function add_untrash(array $row, int $post_id, string $old_sku, string $matched_by): void
    {
        $this->untrashes[] = [
            'row'        => $row,
            'post_id'    => $post_id,
            'old_sku'    => $old_sku,
            'matched_by' => $matched_by,
        ];
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

    public function untrashes(): array
    {
        return $this->untrashes;
    }

    public function skips(): array
    {
        return $this->skips;
    }

    /**
     * @return array{create:int,update:int,rename:int,trash:int,untrash:int,skip:int}
     */
    public function counts(): array
    {
        return [
            'create'  => count($this->creates),
            'update'  => count($this->updates),
            'rename'  => count($this->renames),
            'trash'   => count($this->trashes),
            'untrash' => count($this->untrashes),
            'skip'    => count($this->skips),
        ];
    }

    /**
     * Skips are excluded: an unchanged row costs no writes at all. An
     * untrash is a write — a restore that untrashes 10 products is doing 10
     * writes, same as a create.
     */
    public function total_writes(): int
    {
        return count($this->creates) + count($this->updates)
            + count($this->renames) + count($this->trashes)
            + count($this->untrashes);
    }

    public function is_empty(): bool
    {
        return $this->total_writes() === 0;
    }

    /**
     * The parent/child category pairs a row belongs to.
     *
     * Top level is the category the row's sheet is mapped to, sub-level is
     * Type. A comma-separated Type places the product in several
     * sub-categories, all beneath the same parent.
     *
     * The parent comes from cadco_import_sheet_categories() rather than from
     * the sheet name itself: a category name is public — it shows on the shop
     * and sits in the URL — and must not change because CADCO retitled a tab.
     * A sheet with no mapping yields no categories at all, which is
     * unreachable in practice because the Reader rejects an unrecognised
     * sheet before a row from it exists.
     *
     * @return list<array{parent: string, child: string}>
     */
    public static function categories_for(array $row): array
    {
        $parent = cadco_import_sheet_category((string) ($row['__sheet'] ?? ''));
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
