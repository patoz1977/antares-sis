<?php

declare(strict_types=1);

namespace App\Enrollment\Application\RepresentativePortal\Dto;

use App\Enrollment\Application\Dto\EnrollmentOutput;
use App\Family\Application\Dto\FamilyAuthorizedPickupOutput;
use App\Person\Application\Dto\PersonOutput;
use App\Representative\Application\Dto\RepresentativeOutput;

final readonly class RepresentativeEnrollmentPortalState
{
    /** @param list<FamilyAuthorizedPickupOutput> $authorizedPickups */
    public function __construct(
        public RepresentativeEnrollmentReadContext $context,
        public PersonOutput $representativePerson,
        public RepresentativeOutput $representative,
        public ?RepresentativeEnrollmentStudentOption $selectedStudent,
        public ?EnrollmentOutput $enrollment,
        public bool $enrollmentAvailable,
        public bool $liveDataMaintenanceEnabled,
        public bool $enrollmentDraftMaintenanceEnabled,
        public array $authorizedPickups,
        public RepresentativeEnrollmentProgress $progress,
    ) {
    }
}
