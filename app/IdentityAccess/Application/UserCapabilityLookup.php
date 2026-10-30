<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application;

use App\IdentityAccess\Domain\ValueObject\UserId;

interface UserCapabilityLookup
{
    public function hasCapability(UserId $userId, string $capabilityCode): bool;
}
