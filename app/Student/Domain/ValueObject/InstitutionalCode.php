<?php

declare(strict_types=1);

namespace App\Student\Domain\ValueObject;

use App\Student\Domain\Exception\InvalidStudentState;

final readonly class InstitutionalCode
{
    private string $value;

    public function __construct(string $value)
    {
        $normalized = trim($value);
        if ($normalized === '') {
            throw new InvalidStudentState('Institutional code is required.');
        }

        if (mb_strlen($normalized, 'UTF-8') > 100) {
            throw new InvalidStudentState('Institutional code cannot exceed 100 characters.');
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
