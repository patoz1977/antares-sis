<?php

declare(strict_types=1);

namespace App\Enrollment\Application\Reporting;

use App\Enrollment\Application\Reporting\Dto\StudentBillingReportRow;

final readonly class GetStudentBillingReport
{
    public function __construct(
        private ResolveEnrollmentReportingPeriod $resolvePeriod,
        private StudentBillingReportQuery $query,
    ) {
    }

    /** @return list<StudentBillingReportRow> */
    public function handle(
        int $academicPeriodId,
        ?ReportingGradeSectionFilter $gradeSectionFilter = null,
    ): array
    {
        $period = $this->resolvePeriod->handle($academicPeriodId);
        $gradeSectionFilter ??= ReportingGradeSectionFilter::all($period->id);
        $gradeSectionFilter->assertAcademicPeriod($period->id);

        return $this->query->fetch($period->id, $gradeSectionFilter);
    }
}
