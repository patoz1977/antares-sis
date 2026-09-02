<?php

declare(strict_types=1);

namespace App\BulkImport\Application\Dto;

final readonly class ValidationIssue
{
    public function __construct(
        public BulkImportIssueCategory $category,
        public WorkbookSourceLocation $location,
        public string $message,
    ) {
    }

    /**
     * @return array{category: string, sheet: string, row: int, field: ?string, message: string}
     */
    public function safeOutput(): array
    {
        return [
            'category' => $this->category->value,
            'sheet' => $this->location->sheet,
            'row' => $this->location->row,
            'field' => $this->location->field,
            'message' => $this->message,
        ];
    }
}
