<?php

declare(strict_types=1);

namespace App\BulkImport\Application\Dto;

final readonly class BulkImportWorkbook
{
    /**
     * @param list<FamilyWorkbookRow> $families
     * @param list<RepresentativeWorkbookRow> $representatives
     * @param list<StudentWorkbookRow> $students
     */
    public function __construct(
        public array $families,
        public array $representatives,
        public array $students,
    ) {
    }

    public function totalRows(): int
    {
        return count($this->families) + count($this->representatives) + count($this->students);
    }

    /**
     * @return array{families: int, representatives: int, students: int, total: int}
     */
    public function safeCounts(): array
    {
        return [
            'families' => count($this->families),
            'representatives' => count($this->representatives),
            'students' => count($this->students),
            'total' => $this->totalRows(),
        ];
    }
}
