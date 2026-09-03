<?php

declare(strict_types=1);

namespace App\BulkImport\Application\Dto;

final readonly class ApplyBulkImportResult
{
    /**
     * @param list<ApplyFamilyResult> $families
     * @param list<ValidationIssue> $issues
     */
    public function __construct(
        public array $families,
        public array $issues,
    ) {
    }
}
