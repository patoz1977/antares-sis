<?php

declare(strict_types=1);

namespace App\Enrollment\Application\Administrative\Dto;

use App\AcademicCore\Application\Dto\AcademicGradeReference;
use App\AcademicCore\Application\Dto\AcademicPeriodOutput;
use App\AcademicCore\Application\Dto\AcademicSectionReference;
use App\Enrollment\Application\Dto\EnrollmentOutput;
use App\Person\Application\Dto\PersonOutput;
use App\Student\Application\Dto\StudentOutput;

final readonly class AdministrativeEnrollmentReviewContext
{
    /** @param list<AdministrativeRepresentativeContext> $currentRepresentatives */
    public function __construct(
        public EnrollmentOutput $enrollment,
        public StudentOutput $student,
        public PersonOutput $studentPerson,
        public int $familyId,
        public string $familyDisplayName,
        public string $familyStatus,
        public array $currentRepresentatives,
        public AdministrativeFamilyResourcesContext $currentFamilyResources,
        public AcademicPeriodOutput $academicPeriod,
        public ?AcademicGradeReference $grade,
        public ?AcademicSectionReference $section,
    ) {
    }
}
