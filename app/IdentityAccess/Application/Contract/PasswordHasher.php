<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Contract;

interface PasswordHasher
{
    public function hash(string $plainTextPassword): string;

    public function verify(string $plainTextPassword, string $passwordHash): bool;
}
