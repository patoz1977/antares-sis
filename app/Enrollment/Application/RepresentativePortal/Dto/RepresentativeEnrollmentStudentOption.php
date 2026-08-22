<?php

declare(strict_types=1);

namespace App\Enrollment\Application\RepresentativePortal\Dto;

use App\Person\Application\Dto\PersonOutput;
use App\Student\Application\Dto\StudentOutput;

final readonly class RepresentativeEnrollmentStudentOption
{
    public function __construct(
        public StudentOutput $student,
        public PersonOutput $person,
        public string $displayName,
    ) {
    }
}
