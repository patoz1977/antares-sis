<?php

declare(strict_types=1);

namespace Tests;

use App\BulkImport\Application\Contract\BulkImportWorkbookReader;
use App\BulkImport\Application\Dto\BulkImportWorkbook;
use App\BulkImport\Application\Dto\WorkbookValidationResult;

final class MutableBulkImportWorkbookReader implements BulkImportWorkbookReader
{
    public function __construct(private BulkImportWorkbook $workbook)
    {
    }

    public function replace(BulkImportWorkbook $workbook): void
    {
        $this->workbook = $workbook;
    }

    public function read(string $localPath): WorkbookValidationResult
    {
        return new WorkbookValidationResult($this->workbook, []);
    }
}
