<?php

declare(strict_types=1);

namespace CADCO\Tests\Unit;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\TestCase;

final class HarnessTest extends TestCase
{
    public function test_phpspreadsheet_is_available(): void
    {
        $book = new Spreadsheet();
        $book->getActiveSheet()->setCellValue('A1', 'Model #');

        self::assertSame('Model #', $book->getActiveSheet()->getCell('A1')->getValue());
    }
}
