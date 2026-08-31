<?php

declare(strict_types=1);

namespace App\Family\Domain\ValueObject;

use App\Family\Domain\Exception\InvalidFamilyState;

final readonly class FamilyCode
{
    private string $value;

    public function __construct(string $value)
    {
        $normalized = trim($value);
        if (preg_match('/\AF[0-9]{8}\z/D', $normalized) !== 1) {
            throw new InvalidFamilyState('FamilyCode must be uppercase F followed by exactly eight digits.');
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
