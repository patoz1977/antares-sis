<?php

declare(strict_types=1);

namespace App\Enrollment\Domain\ValueObject;

use App\Enrollment\Domain\Exception\InvalidEnrollmentState;

abstract readonly class PositiveIdentity
{
    final public function __construct(private int $value)
    {
        if ($value <= 0) {
            throw new InvalidEnrollmentState(sprintf('%s must be positive.', static::class));
        }
    }

    final public function value(): int
    {
        return $this->value;
    }

    final public function equals(self $other): bool
    {
        return $other::class === static::class && $this->value === $other->value;
    }
}
