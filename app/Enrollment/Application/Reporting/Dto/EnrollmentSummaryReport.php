<?php

declare(strict_types=1);

namespace App\Enrollment\Application\Reporting\Dto;

final readonly class EnrollmentSummaryReport
{
    /** @param list<EnrollmentSummaryRow> $rows */
    public function __construct(
        public ReportingAcademicPeriod $academicPeriod,
        public array $rows,
        public int $total,
    ) {
    }
}
