<?php

declare(strict_types=1);

namespace App\Enrollment\Application\Dto;

abstract readonly class EnrollmentMutationInput
{
    public function __construct(
        public int $enrollmentId,
        public int $expectedStudentId,
        public int $expectedFamilyId,
        public int $expectedAcademicPeriodId,
    ) {
    }
}
