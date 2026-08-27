<?php

declare(strict_types=1);

namespace App\Enrollment\Application\Reporting;

use App\AcademicCore\Domain\AcademicPeriodRepository;
use App\AcademicCore\Domain\ValueObject\AcademicPeriodId;
use App\Enrollment\Application\Reporting\Dto\ReportingAcademicPeriod;
use App\Enrollment\Application\Reporting\Exception\EnrollmentReportingPeriodNotFound;

final readonly class ResolveEnrollmentReportingPeriod
{
    public function __construct(private AcademicPeriodRepository $periods)
    {
    }

    public function handle(int $academicPeriodId): ReportingAcademicPeriod
    {
        $period = $this->periods->findById(new AcademicPeriodId($academicPeriodId));
        if ($period === null) {
            throw new EnrollmentReportingPeriodNotFound('AcademicPeriod is unavailable for reporting.');
        }

        return ReportingAcademicPeriod::fromAcademicPeriod($period);
    }
}
