<?php

declare(strict_types=1);

namespace App\Enrollment\Application\RepresentativePortal\Dto;

use App\Enrollment\Application\RepresentativePortal\RepresentativeEnrollmentSectionStatus;

final readonly class RepresentativeEnrollmentProgress
{
    public function __construct(
        public RepresentativeEnrollmentSectionStatus $acknowledgements,
        public RepresentativeEnrollmentSectionStatus $representativePersonal,
        public RepresentativeEnrollmentSectionStatus $representativeContact,
        public RepresentativeEnrollmentSectionStatus $employment,
        public RepresentativeEnrollmentSectionStatus $studentPersonal,
        public RepresentativeEnrollmentSectionStatus $studentAddress,
        public RepresentativeEnrollmentSectionStatus $academicPlacement,
        public RepresentativeEnrollmentSectionStatus $billing,
        public RepresentativeEnrollmentSectionStatus $medical,
        public RepresentativeEnrollmentSectionStatus $transport,
        public RepresentativeEnrollmentSectionStatus $emergencyContacts,
        public RepresentativeEnrollmentSectionStatus $pickupOrLeaveAlone,
    ) {
    }
}
