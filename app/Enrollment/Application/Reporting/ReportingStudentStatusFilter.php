<?php

declare(strict_types=1);

namespace App\Enrollment\Application\Reporting;

enum ReportingStudentStatusFilter: string
{
    case Active = 'active';
    case All = 'all';

    public function includes(bool $studentIsActive): bool
    {
        return $studentIsActive || $this === self::All;
    }
}
