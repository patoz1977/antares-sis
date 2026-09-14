<?php

declare(strict_types=1);

namespace App\BulkImport\Application\Contract;

use App\BulkImport\Application\Dto\WorkbookValidationResult;
use DateTimeImmutable;

interface BulkImportWorkbookReader
{
    public function read(string $localPath, DateTimeImmutable $today): WorkbookValidationResult;
}
