<?php

declare(strict_types=1);

namespace App\Enrollment\Application\Reporting;

use App\Enrollment\Application\Reporting\Dto\StudentBillingReportRow;

interface StudentBillingReportQuery
{
    /** @return list<StudentBillingReportRow> */
    public function fetch(int $academicPeriodId): array;
}
