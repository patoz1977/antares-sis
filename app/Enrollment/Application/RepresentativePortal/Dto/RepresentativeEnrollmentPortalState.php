<?php

declare(strict_types=1);

namespace App\Enrollment\Application\RepresentativePortal\Dto;

use App\Enrollment\Application\Dto\EnrollmentOutput;
use App\Person\Application\Dto\PersonOutput;
use App\Representative\Application\Dto\RepresentativeOutput;

final readonly class RepresentativeEnrollmentPortalState
{
    public function __construct(
        public RepresentativeEnrollmentReadContext $context,
        public PersonOutput $representativePerson,
        public RepresentativeOutput $representative,
        public ?RepresentativeEnrollmentStudentOption $selectedStudent,
        public ?EnrollmentOutput $enrollment,
        public bool $enrollmentAvailable,
        public bool $maintenanceEnabled,
        public bool $readOnly,
        public RepresentativeEnrollmentProgress $progress,
    ) {
    }
}
