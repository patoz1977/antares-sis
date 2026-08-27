<?php

declare(strict_types=1);

namespace App\Enrollment\Application\Reporting\Dto;

final readonly class EnrollmentReportingPeriods
{
    /** @param list<ReportingAcademicPeriod> $periods */
    public function __construct(
        public array $periods,
        public ?int $defaultAcademicPeriodId,
    ) {
    }
}
