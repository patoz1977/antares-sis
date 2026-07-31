<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Contract;

use DateTimeImmutable;

interface Clock
{
    public function now(): DateTimeImmutable;
}
