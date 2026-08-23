<?php

declare(strict_types=1);

namespace App\Enrollment\Application\Submission\Dto;

final readonly class SubmitRepresentativeEnrollmentInput
{
    public function __construct(
        public int $expectedFamilyId,
        public int $expectedAcademicPeriodId,
        public int $studentId,
    ) {
    }
}
