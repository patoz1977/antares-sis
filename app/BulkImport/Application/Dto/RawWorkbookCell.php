<?php

declare(strict_types=1);

namespace App\BulkImport\Application\Dto;

use DateTimeInterface;

final readonly class RawWorkbookCell
{
    public function __construct(
        public RawWorkbookCellType $type,
        public null|bool|DateTimeInterface|float|int|string $value,
    ) {
    }
}
