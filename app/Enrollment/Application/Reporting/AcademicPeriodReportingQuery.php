<?php

declare(strict_types=1);

namespace App\Enrollment\Application\Reporting;

use App\Enrollment\Application\Reporting\Dto\ReportingAcademicPeriod;

interface AcademicPeriodReportingQuery
{
    /** @return list<ReportingAcademicPeriod> */
    public function findAll(): array;
}
