<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Contract;

interface CsrfTokenManager
{
    public function token(): string;

    public function isValid(string $token): bool;
}
