<?php

declare(strict_types=1);

namespace App\Enrollment\Application\Reporting;

use App\Enrollment\Application\Reporting\Dto\EnrollmentSummaryReport;

final readonly class GetEnrollmentSummaryReport
{
    public function __construct(
        private ResolveEnrollmentReportingPeriod $resolvePeriod,
        private EnrollmentSummaryQuery $query,
    ) {
    }

    public function handle(
        int $academicPeriodId,
        ?ReportingGradeSectionFilter $gradeSectionFilter = null,
    ): EnrollmentSummaryReport
    {
        $period = $this->resolvePeriod->handle($academicPeriodId);
        $gradeSectionFilter ??= ReportingGradeSectionFilter::all($period->id);
        $gradeSectionFilter->assertAcademicPeriod($period->id);
        $rows = $this->query->fetch($period->id, $gradeSectionFilter);
        $total = array_sum(array_map(static fn ($row): int => $row->count, $rows));

        return new EnrollmentSummaryReport($period, $rows, $total);
    }
}
