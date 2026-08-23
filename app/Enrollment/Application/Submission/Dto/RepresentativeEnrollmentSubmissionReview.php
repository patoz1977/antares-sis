<?php

declare(strict_types=1);

namespace App\Enrollment\Application\Submission\Dto;

use App\AcademicCore\Application\Dto\AcademicPeriodOutput;
use App\Enrollment\Application\Dto\EnrollmentOutput;
use App\Enrollment\Application\RepresentativePortal\Dto\RepresentativeEnrollmentStudentOption;
use App\Family\Application\Dto\FamilyResourcesOutput;
use App\Person\Application\Dto\PersonOutput;

final readonly class RepresentativeEnrollmentSubmissionReview
{
    public function __construct(
        public int $familyId,
        public string $familyDisplayName,
        public RepresentativeEnrollmentStudentOption $student,
        public AcademicPeriodOutput $academicPeriod,
        public EnrollmentOutput $enrollment,
        public PersonOutput $representativePerson,
        public FamilyResourcesOutput $familyResources,
        public bool $acknowledgementsSatisfied,
        public EnrollmentSubmissionValidationResult $validation,
    ) {
    }
}
