<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application;

use App\IdentityAccess\Domain\ValueObject\UserId;

interface LockingUserCapabilityLookup extends UserCapabilityLookup
{
    public function hasCapabilityForUpdate(UserId $userId, string $capabilityCode): bool;
}
