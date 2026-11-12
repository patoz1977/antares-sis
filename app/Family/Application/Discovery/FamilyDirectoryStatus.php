<?php

declare(strict_types=1);

namespace App\Family\Application\Discovery;

use App\Family\Application\Discovery\Exception\InvalidFamilyDiscoveryCriteria;

enum FamilyDirectoryStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case All = 'all';

    public static function fromInput(string $value): self
    {
        $status = self::tryFrom(trim($value));
        if ($status === null) {
            throw new InvalidFamilyDiscoveryCriteria('Selecciona un estado de familia válido.');
        }

        return $status;
    }

    public function persistedCode(): ?string
    {
        return match ($this) {
            self::Active => 'ACTIVE',
            self::Inactive => 'INACTIVE',
            self::All => null,
        };
    }
}
