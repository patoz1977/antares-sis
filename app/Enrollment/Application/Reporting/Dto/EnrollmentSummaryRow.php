<?php

declare(strict_types=1);

namespace App\Enrollment\Application\Reporting\Dto;

use App\Enrollment\Application\Reporting\EnrollmentReportingStatus;

final readonly class EnrollmentSummaryRow
{
    public function __construct(
        public EnrollmentReportingStatus $status,
        public ?int $gradeId,
        public ?string $gradeName,
        public ?int $sectionId,
        public ?string $sectionName,
        public int $count,
    ) {
    }
}
