<?php

declare(strict_types=1);

namespace App\IdentityAccess\Infrastructure\Security;

use App\IdentityAccess\Application\Contract\PasswordHasher;
use RuntimeException;

final class NativePasswordHasher implements PasswordHasher
{
    public function hash(string $plainTextPassword): string
    {
        $hash = password_hash($plainTextPassword, PASSWORD_DEFAULT);
        if (!is_string($hash) || $hash === '') {
            throw new RuntimeException('Unable to hash password securely.');
        }

        return $hash;
    }

    public function verify(string $plainTextPassword, string $passwordHash): bool
    {
        return $passwordHash !== '' && password_verify($plainTextPassword, $passwordHash);
    }
}
