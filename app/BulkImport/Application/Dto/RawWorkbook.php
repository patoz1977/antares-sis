<?php

declare(strict_types=1);

namespace App\BulkImport\Application\Dto;

final readonly class RawWorkbook
{
    /**
     * @param array<string, RawWorkbookSheet> $sheets
     */
    public function __construct(public array $sheets)
    {
    }
}
