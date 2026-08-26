<?php

declare(strict_types=1);

namespace App\Enrollment\Application\Reporting;

use RuntimeException;

enum EnrollmentReportingStatus: string
{
    case NotStarted = 'NOT STARTED';
    case Draft = 'DRAFT';
    case Submitted = 'SUBMITTED';
    case Completed = 'COMPLETED';
    case Cancelled = 'CANCELLED';

    public static function fromEnrollmentCode(?string $code): self
    {
        if ($code === null) {
            return self::NotStarted;
        }

        $status = self::tryFrom($code);
        if ($status === null || $status === self::NotStarted) {
            throw new RuntimeException('Reporting projection returned an unsupported Enrollment status.');
        }

        return $status;
    }
}
