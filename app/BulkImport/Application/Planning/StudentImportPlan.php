<?php

declare(strict_types=1);

namespace App\BulkImport\Application\Planning;

use App\BulkImport\Application\Dto\BulkImportClassification;
use App\BulkImport\Application\Dto\StudentWorkbookRow;
use App\BulkImport\Application\Dto\ValidationIssue;

final readonly class StudentImportPlan
{
    /** @param list<ValidationIssue> $issues */
    public function __construct(
        public StudentWorkbookRow $row,
        public int $sexId,
        public ?int $documentTypeId,
        public BulkImportClassification $classification,
        public ?int $personId,
        public ?int $studentId,
        public bool $createPerson,
        public bool $createStudent,
        public bool $createMembership,
        public array $issues,
    ) {
    }
}
