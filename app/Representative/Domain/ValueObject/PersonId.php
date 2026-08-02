<?php

declare(strict_types=1);

namespace App\Representative\Domain\ValueObject;

use App\Representative\Domain\Exception\InvalidRepresentativeState;

final readonly class PersonId
{
    public function __construct(private int $value)
    {
        if ($value <= 0) {
            throw new InvalidRepresentativeState('PersonId must be positive.');
        }
    }

    public function value(): int
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
