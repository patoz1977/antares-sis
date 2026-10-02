<?php

declare(strict_types=1);

namespace App\Enrollment\Application\Reporting;

use App\Enrollment\Application\Reporting\Dto\ReportingGradeSectionOption;

interface GradeSectionReportingQuery
{
    /** @return list<ReportingGradeSectionOption> */
    public function findForAcademicPeriod(int $academicPeriodId): array;
}
