<?php

declare(strict_types=1);

namespace App\IdentityAccess\Domain;

use App\IdentityAccess\Domain\ValueObject\LoginIdentifier;
use App\IdentityAccess\Domain\ValueObject\PersonId;
use App\IdentityAccess\Domain\ValueObject\UserId;

interface UserRepository
{
    public function findByLoginIdentifier(LoginIdentifier $identifier): ?User;

    public function findByLoginIdentifierForUpdate(LoginIdentifier $identifier): ?User;

    public function findById(UserId $id): ?User;

    public function findByPersonId(PersonId $personId): ?User;

    public function save(User $user): User;
}
