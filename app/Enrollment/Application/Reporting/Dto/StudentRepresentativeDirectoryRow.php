<?php

declare(strict_types=1);

namespace App\Enrollment\Application\Reporting\Dto;

use App\Enrollment\Application\Reporting\EnrollmentReportingStatus;

final readonly class StudentRepresentativeDirectoryRow
{
    public function __construct(
        public int $studentId,
        public ?int $gradeId,
        public ?string $gradeName,
        public ?int $sectionId,
        public ?string $sectionName,
        public string $studentSurnames,
        public string $studentNames,
        public ?string $studentIdentificationType,
        public ?string $studentIdentificationNumber,
        public ?string $representativeSurnames,
        public ?string $representativeNames,
        public ?string $representativeIdentificationType,
        public ?string $representativeIdentificationNumber,
        public ?string $representativeMobilePhone,
        public ?string $representativeLandlinePhone,
        public ?string $representativePersonalEmail,
        public ?string $representativeWorkPhone,
        public ?string $representativeWorkEmail,
        public ?string $studentAddress,
        public EnrollmentReportingStatus $status,
    ) {
    }
}
