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
        public string $studentFirstName,
        public ?string $studentMiddleName,
        public string $studentFirstSurname,
        public ?string $studentSecondSurname,
        public ?string $studentIdentificationType,
        public ?string $studentIdentificationNumber,
        public ?DirectoryRepresentative $representative1,
        public ?DirectoryRepresentative $representative2,
        public ?string $studentAddress,
        public EnrollmentReportingStatus $status,
    ) {
    }
}
