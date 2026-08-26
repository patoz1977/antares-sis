<?php

declare(strict_types=1);

namespace App\Enrollment\Application\Reporting;

use App\Enrollment\Application\Reporting\Dto\StudentRepresentativeDirectoryRow;

interface StudentRepresentativeDirectoryQuery
{
    /** @return list<StudentRepresentativeDirectoryRow> */
    public function fetch(int $academicPeriodId): array;
}
