<?php

declare(strict_types=1);

namespace App\Enrollment\Application\Reporting\Dto;

use App\Enrollment\Application\Reporting\EnrollmentReportingStatus;

final readonly class StudentEnrollmentReportRow
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
    ) {
    }
}
