<?php

declare(strict_types=1);

namespace App\BulkImport\Application\Dto;

use App\Family\Domain\ValueObject\FamilyCode;

final readonly class FamilyWorkbookRow
{
    public function __construct(
        public int $sourceRow,
        public FamilyCode $familyCode,
        public string $displayName,
    ) {
    }
}
