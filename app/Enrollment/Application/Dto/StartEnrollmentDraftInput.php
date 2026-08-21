<?php

declare(strict_types=1);

namespace App\Enrollment\Application\Dto;

final readonly class StartEnrollmentDraftInput
{
    public function __construct(
        public int $studentId,
        public int $familyId,
        public int $academicPeriodId,
        public ?int $gradeId = null,
        public ?int $sectionId = null,
    ) {
    }
}
