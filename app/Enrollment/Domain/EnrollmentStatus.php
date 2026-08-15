<?php

declare(strict_types=1);

namespace App\Enrollment\Domain;

enum EnrollmentStatus: string
{
    case Draft = 'DRAFT';
    case Submitted = 'SUBMITTED';
    case Completed = 'COMPLETED';
    case Cancelled = 'CANCELLED';
}
