<?php

declare(strict_types=1);

namespace App\BulkImport\Application\Planning;

use App\BulkImport\Application\Dto\BulkImportClassification;
use App\BulkImport\Application\Dto\RepresentativeWorkbookRow;
use App\BulkImport\Application\Dto\ValidationIssue;

final readonly class RepresentativeImportPlan
{
    /** @param list<ValidationIssue> $issues */
    public function __construct(
        public RepresentativeWorkbookRow $row,
        public int $documentTypeId,
        public int $sexId,
        public int $relationshipTypeId,
        public BulkImportClassification $classification,
        public ?int $personId,
        public ?int $representativeId,
        public ?int $userId,
        public bool $createPerson,
        public bool $createRepresentative,
        public bool $createUser,
        public bool $createMembership,
        public array $issues,
    ) {
    }
}
