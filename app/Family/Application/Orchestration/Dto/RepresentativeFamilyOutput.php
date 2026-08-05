<?php

declare(strict_types=1);

namespace App\Family\Application\Orchestration\Dto;

use App\Family\Application\Dto\FamilyOutput;
use App\Person\Application\Dto\PersonOutput;
use App\Representative\Application\Dto\RepresentativeOutput;

final readonly class RepresentativeFamilyOutput
{
    public function __construct(
        public PersonOutput $person,
        public RepresentativeOutput $representative,
        public FamilyOutput $family,
    ) {
    }
}
