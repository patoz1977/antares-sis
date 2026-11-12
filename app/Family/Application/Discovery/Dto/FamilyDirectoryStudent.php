<?php

declare(strict_types=1);

namespace App\Family\Application\Discovery\Dto;

final readonly class FamilyDirectoryStudent
{
    public function __construct(
        public int $studentId,
        public string $fullName,
        public ?string $grade,
        public ?string $section,
    ) {
    }

    public function academicPlacement(): string
    {
        if ($this->grade === null) {
            return 'Sin ubicación académica';
        }

        return $this->section === null
            ? $this->grade . ' — Sin paralelo'
            : $this->grade . ' — ' . $this->section;
    }
}
