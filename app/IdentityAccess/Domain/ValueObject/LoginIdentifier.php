<?php

declare(strict_types=1);

namespace App\IdentityAccess\Domain\ValueObject;

use App\IdentityAccess\Domain\Exception\InvalidUserState;

final readonly class LoginIdentifier
{
    private string $value;

    public function __construct(string $value)
    {
        $normalized = mb_strtolower(trim($value), 'UTF-8');

        if ($normalized === '' || mb_strlen($normalized, 'UTF-8') > 254) {
            throw new InvalidUserState('Login identifier is invalid.');
        }

        $this->value = $normalized;
    }

    public function value(): string
    {
        return $this->value;
    }
}
