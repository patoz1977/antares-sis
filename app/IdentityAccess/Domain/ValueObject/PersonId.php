<?php

declare(strict_types=1);

namespace App\IdentityAccess\Domain\ValueObject;

use App\IdentityAccess\Domain\Exception\InvalidUserState;

final readonly class PersonId
{
    public function __construct(private int $value)
    {
        if ($value <= 0) {
            throw new InvalidUserState('PersonId must be positive.');
        }
    }

    public function value(): int
    {
        return $this->value;
    }
}
