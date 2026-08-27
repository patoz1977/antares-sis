<?php

declare(strict_types=1);

namespace App\Enrollment\Application\Reporting;

use App\Enrollment\Application\Reporting\Dto\StudentMedicalReportRow;

interface StudentMedicalReportQuery
{
    /** @return list<StudentMedicalReportRow> */
    public function fetch(int $academicPeriodId): array;
}
