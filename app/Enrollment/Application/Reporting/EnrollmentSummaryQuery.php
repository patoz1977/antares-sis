<?php

declare(strict_types=1);

namespace App\Enrollment\Application\Reporting;

use App\Enrollment\Application\Reporting\Dto\EnrollmentSummaryRow;

interface EnrollmentSummaryQuery
{
    /** @return list<EnrollmentSummaryRow> */
    public function fetch(int $academicPeriodId): array;
}
