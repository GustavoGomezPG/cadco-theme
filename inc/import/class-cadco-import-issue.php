<?php

/**
 * One thing wrong with the workbook.
 *
 * Every issue names the sheet, row and column it came from, what was found,
 * and what to change it to. That is deliberate: under the all-or-nothing
 * policy the report is a worklist CADCO has to act on, so an issue that does
 * not say where it is or what to do about it is useless.
 */

declare(strict_types=1);

final class CADCO_Import_Issue
{
    public function __construct(
        public readonly string $tier,
        public readonly string $sheet,
        public readonly ?int $row,
        public readonly string $column,
        public readonly string $found,
        public readonly string $message,
        public readonly string $fix = ''
    ) {
    }

    public function to_array(): array
    {
        return [
            'tier'    => $this->tier,
            'sheet'   => $this->sheet,
            'row'     => $this->row,
            'column'  => $this->column,
            'found'   => $this->found,
            'message' => $this->message,
            'fix'     => $this->fix,
        ];
    }
}
