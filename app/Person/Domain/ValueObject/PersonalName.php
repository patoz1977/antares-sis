<?php

declare(strict_types=1);

namespace App\Person\Domain\ValueObject;

use App\Person\Domain\Exception\InvalidPersonState;

final readonly class PersonalName
{
    private string $firstName;

    private ?string $middleName;

    private string $firstSurname;

    private ?string $secondSurname;

    public function __construct(
        string $firstName,
        ?string $middleName,
        string $firstSurname,
        ?string $secondSurname,
    ) {
        $this->firstName = $this->requiredPart($firstName, 'First name');
        $this->middleName = $this->optionalPart($middleName, 'Middle name');
        $this->firstSurname = $this->requiredPart($firstSurname, 'First surname');
        $this->secondSurname = $this->optionalPart($secondSurname, 'Second surname');
    }

    public function firstName(): string
    {
        return $this->firstName;
    }

    public function middleName(): ?string
    {
        return $this->middleName;
    }

    public function firstSurname(): string
    {
        return $this->firstSurname;
    }

    public function secondSurname(): ?string
    {
        return $this->secondSurname;
    }

    public function equals(self $other): bool
    {
        return $this->firstName === $other->firstName
            && $this->middleName === $other->middleName
            && $this->firstSurname === $other->firstSurname
            && $this->secondSurname === $other->secondSurname;
    }

    private function requiredPart(string $value, string $label): string
    {
        $normalized = trim($value);
        if ($normalized === '' || mb_strlen($normalized, 'UTF-8') > 100) {
            throw new InvalidPersonState(sprintf('%s must contain between 1 and 100 characters.', $label));
        }

        return $normalized;
    }

    private function optionalPart(?string $value, string $label): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $normalized = trim($value);
        if (mb_strlen($normalized, 'UTF-8') > 100) {
            throw new InvalidPersonState(sprintf('%s cannot exceed 100 characters.', $label));
        }

        return $normalized;
    }
}
