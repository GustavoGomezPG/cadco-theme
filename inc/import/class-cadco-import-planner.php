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
     * @param list<array{post_id:int,sku:string,upc:string,hash:string}> $current
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

                $plan->add_update($row, (int) $match['post_id'], ['hash' => [(string) $match['hash'], $hash]]);
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
     * A stable fingerprint of everything this row would write.
     *
     * Only importable columns take part, so re-ordering rows in the sheet is
     * not a content change. Keys are sorted so that a column moving left or
     * right does not alter the hash either.
     */
    public static function hash(array $row): string
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

        // Plain json_encode rather than wp_json_encode: this unit is tested
        // with no WordPress loaded, so it cannot depend on WordPress helpers.
        $json = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash('sha256', $json);
    }
}
