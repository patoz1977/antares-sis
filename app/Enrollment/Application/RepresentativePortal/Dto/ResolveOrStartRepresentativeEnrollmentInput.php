<?php

declare(strict_types=1);

namespace App\Enrollment\Application\RepresentativePortal\Dto;

final readonly class ResolveOrStartRepresentativeEnrollmentInput
{
    public function __construct(
        public int $expectedFamilyId,
        public int $expectedAcademicPeriodId,
        public int $studentId,
    ) {
    }
}
