<?php

declare(strict_types=1);

namespace App\Enrollment\Application\Dto;

final readonly class UpdateEnrollmentBillingInformationInput extends EnrollmentMutationInput
{
    public function __construct(
        int $enrollmentId,
        int $expectedStudentId,
        int $expectedFamilyId,
        int $expectedAcademicPeriodId,
        public int $identificationTypeId,
        public string $identificationNumber,
        public string $legalName,
        public string $billingAddress,
        public string $billingEmail,
        public string $phone,
    ) {
        parent::__construct(
            $enrollmentId,
            $expectedStudentId,
            $expectedFamilyId,
            $expectedAcademicPeriodId,
        );
    }
}
