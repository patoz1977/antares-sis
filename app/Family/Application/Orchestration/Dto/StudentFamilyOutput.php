<?php

declare(strict_types=1);

namespace App\Family\Application\Orchestration\Dto;

use App\Family\Application\Dto\FamilyOutput;
use App\Person\Application\Dto\PersonOutput;
use App\Student\Application\Dto\StudentOutput;

final readonly class StudentFamilyOutput
{
    public function __construct(
        public PersonOutput $person,
        public StudentOutput $student,
        public FamilyOutput $family,
    ) {
    }
}
