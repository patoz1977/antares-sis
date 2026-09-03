<?php

declare(strict_types=1);

namespace App\Representative\Application;

use App\Representative\Domain\Representative;
use App\Representative\Domain\RepresentativeRepository;
use App\Representative\Domain\ValueObject\PersonId;

interface LockingRepresentativeRepository extends RepresentativeRepository
{
    public function findByPersonIdForUpdate(PersonId $personId): ?Representative;
}
