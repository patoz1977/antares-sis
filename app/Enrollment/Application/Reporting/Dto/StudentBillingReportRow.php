<?php

declare(strict_types=1);

namespace App\Enrollment\Application\Reporting\Dto;

use App\Enrollment\Application\Reporting\EnrollmentReportingStatus;

final readonly class StudentBillingReportRow
{
    public function __construct(
        public int $studentId,
        public ?int $gradeId,
        public ?string $gradeName,
        public ?int $sectionId,
        public ?string $sectionName,
        public string $studentSurnames,
        public string $studentNames,
        public EnrollmentReportingStatus $status,
        public ?string $identificationType,
        public ?string $identificationNumber,
        public ?string $legalName,
        public ?string $billingAddress,
        public ?string $billingEmail,
        public ?string $phone,
    ) {
    }
}
