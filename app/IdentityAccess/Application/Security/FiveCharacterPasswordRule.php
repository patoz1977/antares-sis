<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Security;

final class FiveCharacterPasswordRule
{
    public static function isSatisfiedBy(string $plainTextPassword): bool
    {
        return mb_strlen($plainTextPassword, 'UTF-8') >= 5;
    }
}
