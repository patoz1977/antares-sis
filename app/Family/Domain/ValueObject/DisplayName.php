<?php

declare(strict_types=1);

namespace App\Family\Domain\ValueObject;

use App\Family\Domain\Exception\InvalidFamilyState;

final readonly class DisplayName
{
    private string $value;

    public function __construct(string $value)
    {
        $normalized = trim($value);
        if ($normalized === '') {
            throw new InvalidFamilyState('Family display name is required.');
        }

        if (mb_strlen($normalized, 'UTF-8') > 150) {
            throw new InvalidFamilyState('Family display name cannot exceed 150 characters.');
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
