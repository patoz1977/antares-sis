<?php

declare(strict_types=1);

namespace App\Person\Application\Discovery\Dto;

final readonly class PersonDiscoveryRow
{
    public function __construct(
        public int $personId,
        public string $firstName,
        public ?string $middleName,
        public string $firstSurname,
        public ?string $secondSurname,
        public ?string $identificationNumber,
        public ?string $personalEmail,
    ) {
    }

    public function fullName(): string
    {
        return implode(' ', array_values(array_filter([
            $this->firstName,
            $this->middleName,
            $this->firstSurname,
            $this->secondSurname,
        ], static fn (?string $part): bool => $part !== null && $part !== '')));
    }
}
