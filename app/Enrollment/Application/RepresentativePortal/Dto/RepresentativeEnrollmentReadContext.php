<?php

declare(strict_types=1);

namespace App\Enrollment\Application\RepresentativePortal\Dto;

use App\AcademicCore\Application\Dto\AcademicPeriodOutput;

final readonly class RepresentativeEnrollmentReadContext
{
    /** @param list<RepresentativeEnrollmentStudentOption> $students */
    public function __construct(
        public int $userId,
        public int $representativePersonId,
        public int $representativeId,
        public int $familyId,
        public string $familyDisplayName,
        public bool $canChangeFamily,
        public array $students,
        public ?int $selectedStudentId,
        public ?AcademicPeriodOutput $academicPeriod,
        public bool $acknowledgementsSatisfied,
    ) {
    }
}
