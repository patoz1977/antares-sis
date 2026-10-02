<?php

declare(strict_types=1);

namespace App\Enrollment\Application\Reporting\Dto;

use RuntimeException;

final readonly class ReportingGradeSectionOption
{
    public function __construct(
        public int $gradeId,
        public string $gradeCode,
        public string $gradeName,
        public int $gradeSortOrder,
        public int $sectionId,
        public string $sectionCode,
        public string $sectionName,
    ) {
        if ($gradeId <= 0 || $sectionId <= 0 || $gradeSortOrder <= 0) {
            throw new RuntimeException('Reporting Grade/Section option requires positive persisted values.');
        }
        foreach ([$gradeCode, $gradeName, $sectionCode, $sectionName] as $value) {
            if ($value === '' || trim($value) !== $value) {
                throw new RuntimeException('Reporting Grade/Section option requires normalized labels and codes.');
            }
        }
    }

    public function key(): string
    {
        return $this->gradeCode . ':' . $this->sectionCode;
    }

    public function label(): string
    {
        return $this->gradeName . ' — ' . $this->sectionName;
    }
}
