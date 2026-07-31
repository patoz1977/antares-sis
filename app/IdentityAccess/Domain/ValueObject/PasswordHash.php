<?php

declare(strict_types=1);

namespace App\IdentityAccess\Domain\ValueObject;

use App\IdentityAccess\Domain\Exception\InvalidUserState;

final readonly class PasswordHash
{
    public function __construct(private string $value)
    {
        if ($value === '' || strlen($value) > 255) {
            throw new InvalidUserState('Password hash is invalid.');
        }
    }

    public function value(): string
    {
        return $this->value;
    }
}
