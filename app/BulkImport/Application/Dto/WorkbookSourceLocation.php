<?php

declare(strict_types=1);

namespace App\BulkImport\Application\Dto;

final readonly class WorkbookSourceLocation
{
    public function __construct(
        public string $sheet,
        public int $row,
        public ?string $field = null,
    ) {
    }
}
