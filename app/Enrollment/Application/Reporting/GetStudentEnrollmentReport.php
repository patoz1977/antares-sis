<?php

declare(strict_types=1);

namespace App\Enrollment\Application\Reporting;

use App\Enrollment\Application\Reporting\Dto\StudentEnrollmentReportRow;

final readonly class GetStudentEnrollmentReport
{
    public function __construct(
        private ResolveEnrollmentReportingPeriod $resolvePeriod,
        private StudentEnrollmentListQuery $query,
    ) {
    }

    /** @return list<StudentEnrollmentReportRow> */
    public function handle(int $academicPeriodId): array
    {
        $period = $this->resolvePeriod->handle($academicPeriodId);

        return $this->query->fetch($period->id);
    }
}
