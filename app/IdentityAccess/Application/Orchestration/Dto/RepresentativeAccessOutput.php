<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Orchestration\Dto;

use App\IdentityAccess\Application\Dto\RepresentativeUserOutput;
use App\Person\Application\Dto\PersonOutput;
use App\Representative\Application\Dto\RepresentativeOutput;

final readonly class RepresentativeAccessOutput
{
    public function __construct(
        public PersonOutput $person,
        public RepresentativeOutput $representative,
        public RepresentativeUserOutput $user,
    ) {
    }
}
