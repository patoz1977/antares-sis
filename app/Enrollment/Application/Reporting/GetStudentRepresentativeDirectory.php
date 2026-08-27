<?php

declare(strict_types=1);

namespace App\Enrollment\Application\Reporting;

use App\Enrollment\Application\Reporting\Dto\StudentRepresentativeDirectoryRow;

final readonly class GetStudentRepresentativeDirectory
{
    public function __construct(
        private ResolveEnrollmentReportingPeriod $resolvePeriod,
        private StudentRepresentativeDirectoryQuery $query,
    ) {
    }

    /** @return list<StudentRepresentativeDirectoryRow> */
    public function handle(int $academicPeriodId): array
    {
        $period = $this->resolvePeriod->handle($academicPeriodId);

        return $this->query->fetch($period->id);
    }
}
