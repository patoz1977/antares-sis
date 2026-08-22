<?php

declare(strict_types=1);

namespace App\Enrollment\Application\RepresentativePortal;

enum RepresentativeEnrollmentSectionStatus: string
{
    case Pending = 'PENDING';
    case Complete = 'COMPLETE';
}
