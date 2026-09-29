<?php

declare(strict_types=1);

namespace App\Enrollment\Application\AcademicInitialization\Dto;

final readonly class AcademicInitializationManifestRow
{
    public function __construct(
        public int $sourceRow,
        public string $institutionalCode,
        public string $gradeCode,
        public string $sectionCode,
    ) {
    }
}
