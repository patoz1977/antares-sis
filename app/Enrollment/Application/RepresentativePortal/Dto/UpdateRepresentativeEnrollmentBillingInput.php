<?php

declare(strict_types=1);

namespace App\Enrollment\Application\RepresentativePortal\Dto;

final readonly class UpdateRepresentativeEnrollmentBillingInput
{
    public function __construct(
        public int $expectedFamilyId,
        public int $expectedAcademicPeriodId,
        public int $studentId,
        public int $identificationTypeId,
        public string $identificationNumber,
        public string $legalName,
        public string $billingAddress,
        public string $billingEmail,
        public string $phone,
    ) {
    }
}
