<?php

declare(strict_types=1);

namespace App\BulkImport\Application\Contract;

use App\BulkImport\Application\Dto\WorkbookValidationResult;

interface BulkImportWorkbookReader
{
    public function read(string $localPath): WorkbookValidationResult;
}
