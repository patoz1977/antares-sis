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
    public function handle(int $academicPeriodId): array
    {
        $period = $this->resolvePeriod->handle($academicPeriodId);

        return $this->query->fetch($period->id);
    }
}
