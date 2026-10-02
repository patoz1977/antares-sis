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
