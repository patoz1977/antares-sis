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

    public function handle(int $academicPeriodId): EnrollmentSummaryReport
    {
        $period = $this->resolvePeriod->handle($academicPeriodId);
        $rows = $this->query->fetch($period->id);
        $total = array_sum(array_map(static fn ($row): int => $row->count, $rows));

        return new EnrollmentSummaryReport($period, $rows, $total);
    }
}
