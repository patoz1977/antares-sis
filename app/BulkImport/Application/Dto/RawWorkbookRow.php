<?php

declare(strict_types=1);

namespace App\BulkImport\Application\Dto;

final readonly class RawWorkbookRow
{
    /**
     * @param list<RawWorkbookCell> $cells
     */
    public function __construct(
        public int $sourceRow,
        public array $cells,
    ) {
    }

    public function isBlank(): bool
    {
        foreach ($this->cells as $cell) {
            if ($cell->type !== RawWorkbookCellType::Empty) {
                return false;
            }
        }

        return true;
    }
}
