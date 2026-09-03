<?php

declare(strict_types=1);

namespace App\Person\Application;

use App\Person\Domain\Person;
use App\Person\Domain\PersonRepository;
use App\Person\Domain\ValueObject\Identification;

interface LockingPersonRepository extends PersonRepository
{
    public function findByIdentificationForUpdate(Identification $identification): ?Person;
}
