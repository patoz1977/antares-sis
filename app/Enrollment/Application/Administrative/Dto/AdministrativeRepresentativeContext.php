<?php

declare(strict_types=1);

namespace App\Enrollment\Application\Administrative\Dto;

use App\Person\Application\Dto\PersonOutput;
use App\Representative\Application\Dto\RepresentativeOutput;

final readonly class AdministrativeRepresentativeContext
{
    public function __construct(
        public RepresentativeOutput $representative,
        public PersonOutput $person,
        public bool $isPrimary,
    ) {
    }
}
