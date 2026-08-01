<?php

declare(strict_types=1);

namespace App\Person\Domain;

use App\Person\Domain\ValueObject\Identification;
use App\Person\Domain\ValueObject\PersonId;

interface PersonRepository
{
    public function findById(PersonId $id): ?Person;

    public function findByIdentification(Identification $identification): ?Person;

    public function save(Person $person): void;
}
