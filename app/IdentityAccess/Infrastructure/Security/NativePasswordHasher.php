<?php

declare(strict_types=1);

namespace App\IdentityAccess\Infrastructure\Security;

use App\IdentityAccess\Application\Contract\PasswordHasher;

final class NativePasswordHasher implements PasswordHasher
{
    public function verify(string $plainTextPassword, string $passwordHash): bool
    {
        return $passwordHash !== '' && password_verify($plainTextPassword, $passwordHash);
    }
}
