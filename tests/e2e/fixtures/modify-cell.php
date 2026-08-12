<?php

/**
 * Copies a real workbook and changes exactly one cell in it, located by
 * header text and by the value in the 'Model #' column — never by row/column
 * position, matching how CADCO_Import_Reader itself locates columns (see its
 * class docblock).
 *
 * This exists for Task 15's per-field diff test: the diff table has to be
 * exercised against a real snapshot written by a real import of the real
 * 236-row corrected workbook, not a small synthetic fixture — buildFixture()'s
 * completeSheets() fixtures are deliberately tiny and would not prove
 * anything about the real snapshot every row of the real import actually
 * wrote.
 *
 * Run via `wp eval-file tests/e2e/fixtures/modify-cell.php <source> <out>
 * <sheet> <model> <column> <new value>`. $args is populated by WP-CLI from
 * the positional arguments.
 *
 * No `declare(strict_types=1)` here: WP-CLI's `eval-file` runs the file's
 * contents through eval() rather than include(), and strict_types is one of
 * the declares PHP refuses inside eval()'d code.
 */

$theme = dirname(__DIR__, 3);

require_once $theme . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;

[$source, $out, $sheet_name, $model, $column, $new_value] = $args;

$reader = IOFactory::createReaderForFile($source);
$book   = $reader->load($source);
$sheet  = $book->getSheetByName($sheet_name);

if ($sheet === null) {
    fwrite(STDERR, "Sheet not found: {$sheet_name}\n");
    exit(1);
}

$last    = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
$headers = [];

for ($c = 1; $c <= $last; $c++) {
    $value = trim((string) $sheet->getCell([$c, 1])->getValue());

    if ($value !== '') {
        $headers[$value] = $c;
    }
}

if (!isset($headers['Model #'])) {
    fwrite(STDERR, "Sheet '{$sheet_name}' has no 'Model #' column\n");
    exit(1);
}

if (!isset($headers[$column])) {
    fwrite(STDERR, "Sheet '{$sheet_name}' has no '{$column}' column\n");
    exit(1);
}

$model_col  = $headers['Model #'];
$target_col = $headers[$column];
$last_row   = $sheet->getHighestDataRow();
$found      = false;

for ($r = 2; $r <= $last_row; $r++) {
    $cell_model = trim((string) $sheet->getCell([$model_col, $r])->getValue());

    if ($cell_model === $model) {
        $sheet->setCellValue([$target_col, $r], $new_value);
        $found = true;
        break;
    }
}

if (!$found) {
    fwrite(STDERR, "Model '{$model}' not found on sheet '{$sheet_name}'\n");
    exit(1);
}

$writer = IOFactory::createWriter($book, 'Xlsx');
$writer->save($out);

echo "wrote {$out}\n";
