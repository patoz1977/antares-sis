<?php

declare(strict_types=1);

namespace App\BulkImport\Application;

use App\BulkImport\Application\Dto\BulkImportIssueCategory;
use App\BulkImport\Application\Dto\ValidationIssue;
use App\BulkImport\Application\Dto\WorkbookSourceLocation;

final class ValidationIssueCollector
{
    /** @var list<ValidationIssue> */
    private array $issues = [];

    private bool $truncated = false;

    public function add(
        BulkImportIssueCategory $category,
        string $sheet,
        int $row,
        ?string $field,
        string $message,
    ): void {
        if ($this->truncated) {
            return;
        }

        if (count($this->issues) >= BulkImportWorkbookContract::MAXIMUM_ISSUES - 1) {
            $this->issues[] = new ValidationIssue(
                BulkImportIssueCategory::StructureInvalid,
                new WorkbookSourceLocation($sheet, $row),
                'Se alcanzó el límite de errores; el archivo contiene errores adicionales.',
            );
            $this->truncated = true;

            return;
        }

        $this->issues[] = new ValidationIssue(
            $category,
            new WorkbookSourceLocation($sheet, $row, $field),
            $message,
        );
    }

    public function count(): int
    {
        return count($this->issues);
    }

    /**
     * @return list<ValidationIssue>
     */
    public function all(): array
    {
        return $this->issues;
    }
}
