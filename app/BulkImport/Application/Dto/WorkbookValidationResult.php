<?php

declare(strict_types=1);

namespace App\BulkImport\Application\Dto;

final readonly class WorkbookValidationResult
{
    /**
     * @param list<ValidationIssue> $issues
     */
    public function __construct(
        private ?BulkImportWorkbook $workbook,
        private array $issues,
    ) {
    }

    public function isValid(): bool
    {
        return $this->issues === [] && $this->workbook !== null;
    }

    public function workbook(): ?BulkImportWorkbook
    {
        return $this->workbook;
    }

    /**
     * @return list<ValidationIssue>
     */
    public function issues(): array
    {
        return $this->issues;
    }

    /**
     * @return array{valid: bool, counts: array{families: int, representatives: int, students: int, total: int}|null, issues: list<array{category: string, sheet: string, row: int, field: ?string, message: string}>}
     */
    public function safeOutput(): array
    {
        return [
            'valid' => $this->isValid(),
            'counts' => $this->workbook?->safeCounts(),
            'issues' => array_map(
                static fn (ValidationIssue $issue): array => $issue->safeOutput(),
                $this->issues,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return $this->safeOutput();
    }
}
