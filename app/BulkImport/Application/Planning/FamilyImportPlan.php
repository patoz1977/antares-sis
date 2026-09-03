<?php

declare(strict_types=1);

namespace App\BulkImport\Application\Planning;

use App\BulkImport\Application\Dto\BulkImportClassification;
use App\BulkImport\Application\Dto\FamilyWorkbookRow;
use App\BulkImport\Application\Dto\ValidationIssue;

final readonly class FamilyImportPlan
{
    /**
     * @param list<RepresentativeImportPlan> $representatives
     * @param list<StudentImportPlan> $students
     * @param list<ValidationIssue> $issues
     */
    public function __construct(
        public FamilyWorkbookRow $row,
        public BulkImportClassification $classification,
        public ?int $familyId,
        public bool $createFamily,
        public array $representatives,
        public array $students,
        public array $issues,
    ) {
    }

    public function hasConflict(): bool
    {
        return $this->classification === BulkImportClassification::Conflict
            || $this->issues !== [];
    }
}
