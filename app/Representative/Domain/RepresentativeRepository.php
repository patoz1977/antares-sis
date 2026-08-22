<?php

declare(strict_types=1);

namespace App\Representative\Domain;

use App\Representative\Domain\ValueObject\PersonId;
use App\Representative\Domain\ValueObject\RepresentativeId;

interface RepresentativeRepository
{
    public function findById(RepresentativeId $id): ?Representative;

    public function findByIdForUpdate(RepresentativeId $id): ?Representative;

    public function findByPersonId(PersonId $personId): ?Representative;

    public function save(Representative $representative): Representative;
}
