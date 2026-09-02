<?php

declare(strict_types=1);

namespace App\BulkImport\Application\Dto;

final readonly class RawWorkbookSheet
{
    /**
     * @param list<RawWorkbookRow> $rows
     */
    public function __construct(
        public string $name,
        public array $rows,
    ) {
    }
}
