<?php

declare(strict_types=1);

namespace App\Enrollment\Application\Reporting\Dto;

use App\Enrollment\Application\Reporting\ReportingGradeSectionFilter;

final readonly class EnrollmentReportingContext
{
    /** @param list<ReportingGradeSectionOption> $gradeSectionOptions */
    public function __construct(
        public ReportingAcademicPeriod $academicPeriod,
        public array $gradeSectionOptions,
        public ReportingGradeSectionFilter $gradeSectionFilter,
    ) {
    }
}
