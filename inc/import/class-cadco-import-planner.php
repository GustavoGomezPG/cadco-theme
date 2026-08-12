<?php

/**
 * Works out what changed between the workbook and the site.
 *
 * Current site state is passed in rather than queried, so this whole unit is
 * pure and the awkward cases — a rename, a re-run that should write nothing —
 * can be tested without a database.
 *
 * Matching order matters. SKU is tried first because it is the primary key;
 * UPC is consulted only when the SKU is unknown, and then solely to recognise
 * a rename. Reversing that order would let a mistyped UPC hijack a product
 * that was matching perfectly well by its model number.
 */

declare(strict_types=1);

final class CADCO_Import_Planner
{
    /**
     * @param list<array<string, mixed>> $rows    normalised, validated rows
     * @param list<array{post_id:int,sku:string,upc:string,hash:string,snapshot?:array<string,string>}> $current
     * @param list<array{post_id:int,sku:string,upc:string,hash:string,snapshot?:array<string,string>}> $trashed
     *        Trashed candidates a restore may reuse. Empty for a normal
     *        import — see the trashed-lookup block below for why that must
     *        stay opt-in.
     */
    public static function plan(array $rows, array $current, array $trashed = []): CADCO_Import_Plan
    {
        $plan     = new CADCO_Import_Plan();
        $by_sku   = [];
        $by_upc   = [];
        $unseen   = [];

        $trashed_by_sku = [];
        $trashed_by_upc = [];

        foreach ($trashed as $product) {
            $sku = (string) $product['sku'];
            $upc = (string) $product['upc'];

            if ($sku !== '') {
                $trashed_by_sku[$sku] = $product;
            }

            // Same ambiguity sentinel as $by_upc below: array_key_exists,
            // not isset, so a third trashed product sharing a UPC cannot
            // overwrite the ambiguity marker and resurrect a false match.
            if ($upc !== '') {
                $trashed_by_upc[$upc] = array_key_exists($upc, $trashed_by_upc) ? null : $product;
            }
        }

        foreach ($current as $product) {
            $sku = (string) $product['sku'];
            $upc = (string) $product['upc'];

            if ($sku !== '') {
                $by_sku[$sku] = $product;
            }

            // A UPC shared by several products is ambiguous, so it is not
            // usable for rename detection. Validation blocks that case anyway;
            // this keeps the planner safe if it is ever called directly.
            //
            // array_key_exists, not isset: isset() is false for a key whose
            // value is null, so a third product sharing a UPC would see
            // isset() === false and overwrite the ambiguity sentinel with
            // itself, resurrecting a false unique match. Ambiguity must be
            // permanent once set, however many products collide.
            if ($upc !== '') {
                $by_upc[$upc] = array_key_exists($upc, $by_upc) ? null : $product;
            }

            $unseen[(int) $product['post_id']] = $product;
        }

        foreach ($rows as $row) {
            $sku  = trim((string) ($row['Model #'] ?? ''));
            $upc  = trim((string) ($row['UPC#'] ?? ''));
            $hash = self::hash($row);

            if (isset($by_sku[$sku])) {
                $match = $by_sku[$sku];
                unset($unseen[(int) $match['post_id']]);

                if ((string) $match['hash'] === $hash) {
                    $plan->add_skip($sku);
                    continue;
                }

                // A missing snapshot (products imported before Task 15, or a
                // snapshot the repository could not decode) is a statement
                // about what we know, not about what changed — diff()'s own
                // contract is to report every key on either side, which for
                // an empty $before would claim every column was "added".
                // That decision belongs here, at the one call site that
                // knows *why* $before might be empty, not inside diff()
                // itself; see diff()'s docblock for the contract this
                // deliberately stays out of.
                $snapshot = $match['snapshot'] ?? [];
                $diff     = $snapshot === [] ? [] : self::diff($snapshot, self::comparable($row));

                $plan->add_update(
                    $row,
                    (int) $match['post_id'],
                    $diff,
                    (bool) ($match['snapshot_unreadable'] ?? false)
                );
                continue;
            }

            if ($upc !== '' && array_key_exists($upc, $by_upc) && $by_upc[$upc] !== null) {
                $match = $by_upc[$upc];
                unset($unseen[(int) $match['post_id']]);

                $plan->add_rename($row, (int) $match['post_id'], (string) $match['sku']);
                continue;
            }

            // Opt-in only: CADCO_Import_Repository::current_products() never
            // returns trashed products, so $trashed is [] for every normal
            // import and this block never runs. A product a human trashed
            // on purpose must stay trashed on the next ordinary import — it
            // is only eligible for reuse when the caller is explicitly
            // performing a restore and has supplied trashed candidates.
            // Reusing the trashed post here (rather than falling through to
            // add_create()) is what makes a restore a *restore*: the post
            // ID, and with it every media attachment and manual edit,
            // survives.
            if ($sku !== '' && isset($trashed_by_sku[$sku])) {
                $match = $trashed_by_sku[$sku];
                $plan->add_untrash($row, (int) $match['post_id']);
                continue;
            }

            if ($upc !== '' && array_key_exists($upc, $trashed_by_upc) && $trashed_by_upc[$upc] !== null) {
                $match = $trashed_by_upc[$upc];
                $plan->add_untrash($row, (int) $match['post_id']);
                continue;
            }

            $plan->add_create($row);
        }

        foreach ($unseen as $product) {
            $plan->add_trash((int) $product['post_id'], (string) $product['sku']);
        }

        return $plan;
    }

    /**
     * Everything this row would write, as the payload hash() fingerprints
     * and diff() compares against a stored one.
     *
     * Only importable columns take part, so re-ordering rows in the sheet is
     * not a content change. Keys are sorted so that a column moving left or
     * right does not alter the payload either. hash() is defined purely in
     * terms of this method's return value so the two can never disagree:
     * identical hashes always mean identical comparable() payloads, and vice
     * versa. That is what lets diff() be skipped entirely on a matching hash,
     * and guarantees a non-matching hash always has something real to show.
     *
     * @return array<string, string>
     */
    public static function comparable(array $row): array
    {
        $relevant = array_merge(
            array_keys(cadco_import_native_columns()),
            array_keys(cadco_import_attribute_columns()),
            array_keys(cadco_import_meta_columns()),
            ['__sheet']
        );

        $payload = [];

        foreach (array_unique($relevant) as $column) {
            $value = trim((string) ($row[$column] ?? ''));

            if ($value !== '') {
                $payload[$column] = $value;
            }
        }

        ksort($payload);

        return $payload;
    }

    /**
     * A stable fingerprint of comparable()'s payload.
     */
    public static function hash(array $row): string
    {
        // Plain json_encode rather than wp_json_encode: this unit is tested
        // with no WordPress loaded, so it cannot depend on WordPress helpers.
        $json = (string) json_encode(self::comparable($row), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash('sha256', $json);
    }

    /**
     * column => [before, after] for every key that differs between two
     * comparable() payloads, in either direction. A key present on only one
     * side is reported with '' standing in for the missing side — including
     * when $before itself is entirely empty, in which case every key of
     * $after is reported as added. This method has no opinion on *why*
     * $before might be empty; it diffs exactly the two arrays it is given.
     *
     * A genuinely missing snapshot is a real, common case for this pipeline
     * (see CADCO_Import_Repository::current_products()) and deliberately
     * not handled here — reporting every column as "added" would be
     * misleading in that situation, but recognising "no snapshot exists"
     * is a judgement about provenance, not a property of two arrays, so it
     * belongs at the call site that knows the snapshot's origin
     * (CADCO_Import_Planner::plan()), not inside a pure diff.
     *
     * @param array<string, string> $before
     * @param array<string, string> $after
     * @return array<string, array{0: string, 1: string}>
     */
    public static function diff(array $before, array $after): array
    {
        $diff = [];

        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $column) {
            // array_keys() on a string-keyed array cannot actually produce an
            // int here (comparable() never emits a numeric-looking column
            // name), but the cast is cheap insurance against the same
            // canonical-integer-string coercion that bites array keys
            // elsewhere in this pipeline, and it keeps the key's type
            // explicit for the array_key_exists() calls below.
            $column = (string) $column;

            // array_key_exists(), not isset(): a value of '' is not null, so
            // isset() would already be fine here in practice, but
            // array_key_exists() is what actually says "this side has the
            // key" rather than "this side has a truthy-ish value for it".
            $before_value = array_key_exists($column, $before) ? $before[$column] : '';
            $after_value  = array_key_exists($column, $after) ? $after[$column] : '';

            if ($before_value !== $after_value) {
                $diff[$column] = [$before_value, $after_value];
            }
        }

        ksort($diff);

        return $diff;
    }

    /**
     * The path segment of a legacy product URL, or '' for anything that is
     * not one.
     *
     * The workbook's 'Website URL' column is untrusted spreadsheet data, not
     * a value this system produced — two rows in the real workbook put a
     * spec-sheet PDF there instead of a product page. Redirecting a PDF path
     * to a new product URL would be wrong, so the shape check
     * (`#^/product/[^/]+$#`) is deliberately strict: only exactly
     * `/product/<something>` passes. Trailing slash is trimmed first so
     * 'http://.../product/cap-f/' and '.../product/cap-f' extract the same
     * path.
     *
     * Uses parse_url() rather than WordPress's wp_parse_url() — this class is
     * exercised by the unit suite with no WordPress loaded (see
     * PlannerTest::setUpBeforeClass()), and plain PHP is all that pure
     * parsing needs.
     */
    public static function legacy_path(string $url): string
    {
        $path = self::path_of($url);

        return preg_match('#^/product/[^/]+$#', $path) === 1 ? $path : '';
    }

    /**
     * The path segment of any URL, trailing slash trimmed, with no shape
     * guard.
     *
     * Separate from legacy_path() on purpose. A renamed product's *old*
     * permalink is on this site and already shaped
     * '/products/<category>/<child>/<slug>/' — legacy_path()'s product-page
     * guard exists specifically to reject the workbook's two PDF rows, and
     * loosening it to also accept that shape would let a spec-sheet URL slip
     * through some other way later. The rename case does not need a guard at
     * all: its input is get_permalink() on a product this system already
     * knows exists, never free-text from an uploaded workbook, so there is
     * nothing to reject.
     */
    public static function path_of(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path) ? rtrim($path, '/') : '';
    }
}
