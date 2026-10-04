<?php

declare(strict_types=1);

namespace App\Enrollment\Application\Reporting;

use App\Enrollment\Application\Reporting\Dto\PhysicalDepartureReportRow;

interface PhysicalDepartureReportQuery
{
    /** @return list<PhysicalDepartureReportRow> */
    public function fetch(
        int $academicPeriodId,
        ?ReportingGradeSectionFilter $gradeSectionFilter = null,
    ): array;
}
