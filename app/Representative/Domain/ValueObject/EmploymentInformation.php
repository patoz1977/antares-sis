<?php

declare(strict_types=1);

namespace App\Representative\Domain\ValueObject;

use App\Representative\Domain\Exception\InvalidRepresentativeState;

final readonly class EmploymentInformation
{
    private ?string $occupation;

    private ?string $companyName;

    private ?string $position;

    private ?string $workPhone;

    private ?string $workEmail;

    public function __construct(
        ?string $occupation,
        ?string $companyName,
        ?string $position,
        ?string $workPhone,
        ?string $workEmail,
    ) {
        $this->occupation = $this->normalizeOptional($occupation, 'Occupation', 150);
        $this->companyName = $this->normalizeOptional($companyName, 'Company name', 150);
        $this->position = $this->normalizeOptional($position, 'Position', 150);
        $this->workPhone = $this->normalizeOptional($workPhone, 'Work phone', 30);
        $this->workEmail = $this->normalizeEmail($workEmail);
    }

    public function occupation(): ?string
    {
        return $this->occupation;
    }

    public function companyName(): ?string
    {
        return $this->companyName;
    }

    public function position(): ?string
    {
        return $this->position;
    }

    public function workPhone(): ?string
    {
        return $this->workPhone;
    }

    public function workEmail(): ?string
    {
        return $this->workEmail;
    }

    public function isEmpty(): bool
    {
        return $this->occupation === null
            && $this->companyName === null
            && $this->position === null
            && $this->workPhone === null
            && $this->workEmail === null;
    }

    public function equals(self $other): bool
    {
        return $this->occupation === $other->occupation
            && $this->companyName === $other->companyName
            && $this->position === $other->position
            && $this->workPhone === $other->workPhone
            && $this->workEmail === $other->workEmail;
    }

    private function normalizeEmail(?string $value): ?string
    {
        $normalized = $this->normalizeOptional($value, 'Work email', 254);
        if ($normalized !== null && filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidRepresentativeState('Work email format is invalid.');
        }

        return $normalized;
    }

    private function normalizeOptional(?string $value, string $label, int $maximumLength): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $normalized = trim($value);
        if (mb_strlen($normalized, 'UTF-8') > $maximumLength) {
            throw new InvalidRepresentativeState(
                sprintf('%s cannot exceed %d characters.', $label, $maximumLength)
            );
        }

        return $normalized;
    }
}
