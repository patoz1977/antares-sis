<?php

declare(strict_types=1);

namespace App\BulkImport\Application\Dto;

final readonly class ApplyFamilyResult
{
    /**
     * @param list<ValidationIssue> $issues
     */
    public function __construct(
        public string $familyCode,
        public BulkImportClassification $classification,
        public string $message,
        public array $issues = [],
    ) {
    }
}
