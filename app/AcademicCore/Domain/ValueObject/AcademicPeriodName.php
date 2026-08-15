<?php

declare(strict_types=1);

namespace App\AcademicCore\Domain\ValueObject;

use App\AcademicCore\Domain\Exception\InvalidAcademicPeriodState;

final readonly class AcademicPeriodName
{
    private string $value;

    public function __construct(string $value)
    {
        $normalized = trim($value);
        if ($normalized === '') {
            throw new InvalidAcademicPeriodState('AcademicPeriod name is required.');
        }
        if (mb_strlen($normalized, 'UTF-8') > 150) {
            throw new InvalidAcademicPeriodState('AcademicPeriod name cannot exceed 150 characters.');
        }

        $this->value = $normalized;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
