<?php

declare(strict_types=1);

namespace App\BulkImport\Application\Planning;

use App\BulkImport\Application\Dto\ValidationIssue;

final readonly class BulkImportPlan
{
    /**
     * @param list<FamilyImportPlan> $families
     * @param list<ValidationIssue> $issues
     */
    public function __construct(
        public array $families,
        public array $issues,
    ) {
    }
}
