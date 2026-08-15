<?php

declare(strict_types=1);

namespace App\AcademicCore\Domain\ValueObject;

use App\AcademicCore\Domain\Exception\InvalidAcademicPeriodState;

final readonly class AcademicPeriodId
{
    public function __construct(private int $value)
    {
        if ($value <= 0) {
            throw new InvalidAcademicPeriodState('AcademicPeriodId must be positive.');
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
