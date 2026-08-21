<?php

declare(strict_types=1);

namespace App\Enrollment\Application\Dto;

final readonly class UpdateEnrollmentLeaveAloneAuthorizationInput extends EnrollmentMutationInput
{
    public function __construct(
        int $enrollmentId,
        int $expectedStudentId,
        int $expectedFamilyId,
        int $expectedAcademicPeriodId,
        public bool $isAuthorizedToLeaveAlone,
    ) {
        parent::__construct(
            $enrollmentId,
            $expectedStudentId,
            $expectedFamilyId,
            $expectedAcademicPeriodId,
        );
    }
}
