<?php

declare(strict_types=1);

namespace App\Enrollment\Application\Reporting;

use App\Enrollment\Application\Reporting\Dto\StudentEnrollmentReportRow;

interface StudentEnrollmentListQuery
{
    /** @return list<StudentEnrollmentReportRow> */
    public function fetch(int $academicPeriodId): array;
}
