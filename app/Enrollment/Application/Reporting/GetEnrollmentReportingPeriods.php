<?php

declare(strict_types=1);

namespace App\Enrollment\Application\Reporting;

use App\AcademicCore\Domain\AcademicPeriodStatus;
use App\AcademicCore\Domain\Exception\AcademicPeriodOperationalStateConflict;
use App\Enrollment\Application\Reporting\Dto\EnrollmentReportingPeriods;

final readonly class GetEnrollmentReportingPeriods
{
    public function __construct(private AcademicPeriodReportingQuery $periods)
    {
    }

    public function handle(): EnrollmentReportingPeriods
    {
        $periods = $this->periods->findAll();
        $activeIds = [];
        foreach ($periods as $period) {
            if ($period->status === AcademicPeriodStatus::Active) {
                $activeIds[] = $period->id;
            }
        }

        if (count($activeIds) > 1) {
            throw new AcademicPeriodOperationalStateConflict(
                'More than one ACTIVE AcademicPeriod exists; reporting default is ambiguous.'
            );
        }

        return new EnrollmentReportingPeriods($periods, $activeIds[0] ?? null);
    }
}
