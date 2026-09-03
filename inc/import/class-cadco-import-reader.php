<?php

/**
 * Turns the product workbook into plain rows.
 *
 * The only unit that knows PhpSpreadsheet exists. Everything downstream works
 * on arrays, so the parsing library can be swapped without touching any
 * business logic.
 *
 * Columns are located by their header text rather than by position. That is
 * not a stylistic preference: two sheets in the same workbook disagree about
 * column order (CONVECTION OVENS carries a leading 'Notes' column that
 * COUNTERTOP EQUIPMENT does not), and the previous revision of the workbook
 * had different sheets and a column that no longer exists.
 */

declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\IOFactory;

final class CADCO_Import_Reader
{
    /**
     * @return array{rows: list<array<string, mixed>>, errors: list<array<string, mixed>>}
     */
    public static function read(string $path): array
    {
        $rows   = [];
        $errors = [];

        if (!is_readable($path)) {
            return [
                'rows'   => [],
                'errors' => [self::error('', null, '', sprintf('The file could not be read: %s', $path))],
            ];
        }

        try {
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $book = $reader->load($path);
        } catch (\Throwable $e) {
            return [
                'rows'   => [],
                'errors' => [self::error('', null, '', 'The file could not be opened as a spreadsheet: ' . $e->getMessage())],
            ];
        }

        $known = array_map('strtoupper', cadco_import_sheets());
        $seen  = [];

        foreach ($book->getWorksheetIterator() as $sheet) {
            $title = trim($sheet->getTitle());

            // Sheets beginning with '_' are the workbook's own working notes.
            if (str_starts_with($title, '_')) {
                continue;
            }

            // A tab CADCO has since retitled still names the same sheet.
            $resolved  = self::resolve_sheet($title);
            $canonical = array_search(strtoupper($resolved), $known, true);

            if ($canonical === false) {
                $errors[] = self::error(
                    $title,
                    null,
                    '',
                    sprintf('Unrecognised sheet "%s". Rename it to one of: %s, or prefix it with "_" to have it ignored.', $title, implode(', ', cadco_import_sheets()))
                );
                continue;
            }

            $name         = cadco_import_sheets()[$canonical];
            $seen[$name]  = true;
            $headers      = self::headers($sheet);
            $missing      = array_diff(cadco_import_required_fields(), $headers);

            if ($missing !== []) {
                $errors[] = self::error(
                    $name,
                    1,
                    '',
                    sprintf('Sheet "%s" is missing required column(s): %s', $name, implode(', ', $missing))
                );
                continue;
            }

            foreach (self::body($sheet, $headers, $name) as $row) {
                $rows[] = $row;
            }
        }

        $book->disconnectWorksheets();

        foreach (cadco_import_sheets() as $expected) {
            if (!isset($seen[$expected])) {
                $errors[] = self::error('', null, '', sprintf('Expected sheet "%s" is not present in the workbook.', $expected));
            }
        }

        return ['rows' => $rows, 'errors' => $errors];
    }

    /**
     * The canonical name for a sheet title, resolving a former title through
     * cadco_import_sheet_aliases(). An unknown title is returned unchanged so
     * the caller still reports it as unrecognised.
     */
    private static function resolve_sheet(string $title): string
    {
        foreach (cadco_import_sheet_aliases() as $alias => $canonical) {
            if (strcasecmp($title, $alias) === 0) {
                return $canonical;
            }
        }

        return $title;
    }

    /**
     * Header text from row 1, indexed by column number.
     *
     * Headings are canonicalised through cadco_import_column_aliases() so
     * that a column one sheet spells differently reaches the rest of the
     * pipeline under one name. An alias is only applied when the canonical
     * heading is absent from the sheet: were both present, renaming would
     * make one silently overwrite the other, which is a worse failure than
     * the one the alias exists to fix.
     *
     * @return array<int, string>
     */
    private static function headers(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet): array
    {
        $headers = [];
        $last    = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

        for ($c = 1; $c <= $last; $c++) {
            $value = trim((string) $sheet->getCell([$c, 1])->getValue());

            if ($value !== '') {
                $headers[$c] = $value;
            }
        }

        $aliases = cadco_import_column_aliases();

        foreach ($headers as $c => $heading) {
            $canonical = $aliases[$heading] ?? null;

            if ($canonical !== null && !in_array($canonical, $headers, true)) {
                $headers[$c] = $canonical;
            }
        }

        return $headers;
    }

    /**
     * @param array<int, string> $headers
     * @return list<array<string, mixed>>
     */
    private static function body(
        \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
        array $headers,
        string $name
    ): array {
        $rows = [];
        $last = $sheet->getHighestDataRow();

        for ($r = 2; $r <= $last; $r++) {
            $row   = [];
            $empty = true;

            foreach ($headers as $c => $header) {
                $value = $sheet->getCell([$c, $r])->getValue();
                $value = $value === null ? '' : trim((string) $value);

                if ($value !== '') {
                    $empty = false;
                }

                $row[$header] = $value;
            }

            // A wholly empty row is spreadsheet padding, not a product.
            if ($empty) {
                continue;
            }

            $row['__sheet'] = $name;
            $row['__row']   = $r;
            $rows[]         = $row;
        }

        return $rows;
    }

    private static function error(string $sheet, ?int $row, string $column, string $message): array
    {
        return [
            'tier'    => 'A',
            'sheet'   => $sheet,
            'row'     => $row,
            'column'  => $column,
            'message' => $message,
        ];
    }
}
