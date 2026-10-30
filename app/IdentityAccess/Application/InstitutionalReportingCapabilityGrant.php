<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application;

use App\IdentityAccess\Domain\ValueObject\UserId;

interface InstitutionalReportingCapabilityGrant
{
    public function grant(UserId $userId): void;
}
