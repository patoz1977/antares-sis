<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application;

use App\IdentityAccess\Domain\User;
use App\IdentityAccess\Domain\UserRepository;
use App\IdentityAccess\Domain\ValueObject\PersonId;

interface LockingUserRepository extends UserRepository
{
    public function findByPersonIdForUpdate(PersonId $personId): ?User;
}
