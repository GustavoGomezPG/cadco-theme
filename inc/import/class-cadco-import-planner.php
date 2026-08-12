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
     */
    public static function plan(array $rows, array $current): CADCO_Import_Plan
    {
        $plan     = new CADCO_Import_Plan();
        $by_sku   = [];
        $by_upc   = [];
        $unseen   = [];

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

                $plan->add_update(
                    $row,
                    (int) $match['post_id'],
                    self::diff($match['snapshot'] ?? [], self::comparable($row))
                );
                continue;
            }

            if ($upc !== '' && array_key_exists($upc, $by_upc) && $by_upc[$upc] !== null) {
                $match = $by_upc[$upc];
                unset($unseen[(int) $match['post_id']]);

                $plan->add_rename($row, (int) $match['post_id'], (string) $match['sku']);
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
     * side is reported with '' standing in for the missing side.
     *
     * An empty $before is a deliberate special case, not just a small one:
     * comparable() drops blank cells, and every column the validator requires
     * is guaranteed non-blank, so a real stored payload can never decode to
     * []. The only way $before arrives here empty is that no snapshot was
     * ever stored for this product — it predates diff tracking (see
     * CADCO_Import_Repository::current_products()). Walking the union in
     * that case would report every column of $after as newly "added", which
     * would tell the operator the whole record changed when in truth nothing
     * is known either way. An empty diff — rendered by the caller as "no
     * previous snapshot" — is the honest answer.
     *
     * @param array<string, string> $before
     * @param array<string, string> $after
     * @return array<string, array{0: string, 1: string}>
     */
    public static function diff(array $before, array $after): array
    {
        if ($before === []) {
            return [];
        }

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
}
