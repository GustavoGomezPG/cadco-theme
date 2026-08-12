<?php

/**
 * Diffs the term tree the workbook implies against what the site already has.
 *
 * The one genuinely new piece of data the review screen needs — everything
 * else it shows already exists somewhere in CADCO_Import_Plan. Pure and
 * database-free: the site's existing terms are injected as $existing rather
 * than looked up here, which is what lets this be unit-tested directly.
 *
 * Matching is by name, scoped the same way CADCO_Import_Applier::ensure_term()
 * scopes its own lookup — within a taxonomy, and for categories also within a
 * parent, never by slug. ensure_term()'s own docblock explains why: the
 * workbook legitimately reuses a child category name under two different
 * parents, and a lookup that ignored parent would treat a genuinely new child
 * under one parent as already existing because a same-named term happens to
 * sit under a different one. Matching by slug would have the same problem in
 * the other direction — two differently-capitalised names can share a slug —
 * so name is compared as ensure_term() has it: trimmed, case-sensitive, never
 * slugified.
 */

declare(strict_types=1);

final class CADCO_Import_Term_Diff
{
    /**
     * @param list<array<string, mixed>> $rows
     * @param list<array{taxonomy:string,term_id:int,name:string,parent:int,count:int}> $existing
     * @return array<string, array{new: list<array<string, mixed>>, removed: list<array<string, mixed>>, in_use: list<array<string, mixed>>}>
     */
    public static function compare(array $rows, array $existing): array
    {
        $terms = CADCO_Import_Plan::all_terms($rows);

        return [
            'product_cat'   => self::compare_categories($rows, $existing),
            'product_tag'   => self::compare_flat($terms['tags'], 'product_tag', $existing),
            'product_brand' => self::compare_flat($terms['brands'], 'product_brand', $existing),
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<array{taxonomy:string,term_id:int,name:string,parent:int,count:int}> $existing
     * @return array{new: list<array<string, mixed>>, removed: list<array<string, mixed>>, in_use: list<array<string, mixed>>}
     */
    private static function compare_categories(array $rows, array $existing): array
    {
        // The implied tree: parent names in first-appearance order, each
        // parent's children in first-appearance order, and a per-child
        // product count — exactly what CADCO_Import_Plan::categories_for()
        // gives per row, which is also what the applier assigns from.
        $parents  = [];
        $children = [];
        $counts   = [];

        foreach ($rows as $row) {
            foreach (CADCO_Import_Plan::categories_for($row) as $pair) {
                $parent = (string) $pair['parent'];
                $child  = (string) $pair['child'];

                if (!in_array($parent, $parents, true)) {
                    $parents[] = $parent;
                }

                $children[$parent] ??= [];

                if (!in_array($child, $children[$parent], true)) {
                    $children[$parent][] = $child;
                }

                $counts[$parent] ??= [];
                $counts[$parent][$child] = ($counts[$parent][$child] ?? 0) + 1;
            }
        }

        $existing_cat = array_values(array_filter(
            $existing,
            static fn (array $t): bool => (string) $t['taxonomy'] === 'product_cat'
        ));

        // Name lookup for every existing product_cat term, used to resolve
        // an existing child term's parent name — $existing only carries the
        // parent's term_id, not its name.
        $name_by_id = [];

        // Existing top-level (parent === 0) term_ids by name, used to scope
        // the "does this child already exist" check to the correct parent,
        // the same way ensure_term() scopes its own lookup.
        $existing_top_id_by_name = [];

        foreach ($existing_cat as $t) {
            $name_by_id[(int) $t['term_id']] = (string) $t['name'];

            if ((int) $t['parent'] === 0) {
                $existing_top_id_by_name[(string) $t['name']] = (int) $t['term_id'];
            }
        }

        $new = [];

        foreach ($parents as $parent) {
            $parent_term_id = $existing_top_id_by_name[$parent] ?? null;
            $new_children    = [];

            foreach ($children[$parent] as $child) {
                $already_exists = false;

                if ($parent_term_id !== null) {
                    foreach ($existing_cat as $t) {
                        if ((int) $t['parent'] === $parent_term_id && (string) $t['name'] === $child) {
                            $already_exists = true;
                            break;
                        }
                    }
                }

                if (!$already_exists) {
                    $new_children[] = ['name' => $child, 'products' => $counts[$parent][$child]];
                }
            }

            if ($new_children !== []) {
                $new[] = [
                    'name'     => $parent,
                    'parent'   => '',
                    'products' => 0,
                    'children' => $new_children,
                ];
            }
        }

        $removed = [];
        $in_use  = [];

        foreach ($existing_cat as $t) {
            $name = (string) $t['name'];

            // WooCommerce recreates 'Uncategorized' as the default term, so
            // reporting it as a removal every single run would be noise the
            // operator learns to ignore.
            if ($name === 'Uncategorized') {
                continue;
            }

            $parent_id = (int) $t['parent'];

            if ($parent_id === 0) {
                $implied = in_array($name, $parents, true);
            } else {
                $parent_name = $name_by_id[$parent_id] ?? null;
                $implied     = $parent_name !== null
                    && isset($children[$parent_name])
                    && in_array($name, $children[$parent_name], true);
            }

            if ($implied) {
                continue;
            }

            $count = (int) $t['count'];

            if ($count > 0) {
                $in_use[] = ['name' => $name, 'term_id' => (int) $t['term_id'], 'products' => $count];
            } else {
                $removed[] = ['name' => $name, 'term_id' => (int) $t['term_id']];
            }
        }

        return ['new' => $new, 'removed' => $removed, 'in_use' => $in_use];
    }

    /**
     * Tags and brands are flat (always top-level), so the parent-scoping
     * categories need does not apply — a plain name-within-taxonomy match
     * is exactly what ensure_term() does for these two taxonomies, since it
     * is always called with $parent = 0 for them.
     *
     * @param list<string> $implied
     * @param list<array{taxonomy:string,term_id:int,name:string,parent:int,count:int}> $existing
     * @return array{new: list<array<string, mixed>>, removed: list<array<string, mixed>>, in_use: list<array<string, mixed>>}
     */
    private static function compare_flat(array $implied, string $taxonomy, array $existing): array
    {
        $implied_names = array_map(static fn ($name): string => (string) $name, $implied);

        $existing_of_taxonomy = array_values(array_filter(
            $existing,
            static fn (array $t): bool => (string) $t['taxonomy'] === $taxonomy
        ));

        $existing_names = array_map(
            static fn (array $t): string => (string) $t['name'],
            $existing_of_taxonomy
        );

        $new = [];

        foreach ($implied_names as $name) {
            if (!in_array($name, $existing_names, true)) {
                $new[] = ['name' => $name];
            }
        }

        $removed = [];
        $in_use  = [];

        foreach ($existing_of_taxonomy as $t) {
            $name = (string) $t['name'];

            if (in_array($name, $implied_names, true)) {
                continue;
            }

            $count = (int) $t['count'];

            if ($count > 0) {
                $in_use[] = ['name' => $name, 'term_id' => (int) $t['term_id'], 'products' => $count];
            } else {
                $removed[] = ['name' => $name, 'term_id' => (int) $t['term_id']];
            }
        }

        return ['new' => $new, 'removed' => $removed, 'in_use' => $in_use];
    }
}
