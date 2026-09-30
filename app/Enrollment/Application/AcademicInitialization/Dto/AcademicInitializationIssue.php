<?php

declare(strict_types=1);

namespace App\Enrollment\Application\AcademicInitialization\Dto;

final readonly class AcademicInitializationIssue
{
    public function __construct(
        public string $category,
        public int $row,
        public ?string $field,
        public string $message,
    ) {
    }

    /** @return array{category: string, row: int, field: ?string, message: string} */
    public function safeOutput(): array
    {
        return [
            'category' => $this->category,
            'row' => $this->row,
            'field' => $this->field,
            'message' => $this->message,
        ];
    }
}
