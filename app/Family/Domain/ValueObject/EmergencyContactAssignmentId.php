<?php

declare(strict_types=1);

namespace App\Family\Domain\ValueObject;

use App\Family\Domain\Exception\InvalidFamilyState;

final readonly class EmergencyContactAssignmentId
{
    public function __construct(private int $value)
    {
        if ($value <= 0) {
            throw new InvalidFamilyState('EmergencyContactAssignmentId must be positive.');
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
