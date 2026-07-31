<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Contract;

interface SecurityEventLogger
{
    public function record(string $event): void;
}
