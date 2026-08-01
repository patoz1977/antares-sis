<?php

declare(strict_types=1);

namespace App\Person\Domain\ValueObject;

use App\Person\Domain\Exception\InvalidPersonState;

final readonly class ContactInformation
{
    private ?string $email;

    private ?string $mobilePhone;

    private ?string $landlinePhone;

    public function __construct(
        ?string $email,
        ?string $mobilePhone,
        ?string $landlinePhone,
    ) {
        $this->email = $this->normalizeEmail($email);
        $this->mobilePhone = $this->normalizePhone($mobilePhone, 'Mobile phone');
        $this->landlinePhone = $this->normalizePhone($landlinePhone, 'Landline phone');
    }

    public function email(): ?string
    {
        return $this->email;
    }

    public function mobilePhone(): ?string
    {
        return $this->mobilePhone;
    }

    public function landlinePhone(): ?string
    {
        return $this->landlinePhone;
    }

    public function equals(self $other): bool
    {
        return $this->email === $other->email
            && $this->mobilePhone === $other->mobilePhone
            && $this->landlinePhone === $other->landlinePhone;
    }

    private function normalizeEmail(?string $value): ?string
    {
        $normalized = $this->normalizeOptional($value);
        if ($normalized === null) {
            return null;
        }

        if (mb_strlen($normalized, 'UTF-8') > 254 || filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidPersonState('Email format is invalid.');
        }

        return $normalized;
    }

    private function normalizePhone(?string $value, string $label): ?string
    {
        $normalized = $this->normalizeOptional($value);
        if ($normalized === null) {
            return null;
        }

        $hasValidLength = mb_strlen($normalized, 'UTF-8') <= 30;
        $usesPhoneCharacters = preg_match('/^[0-9+().\s-]+$/u', $normalized) === 1;
        $containsDigit = preg_match('/\d/u', $normalized) === 1;

        if (!$hasValidLength || !$usesPhoneCharacters || !$containsDigit) {
            throw new InvalidPersonState(sprintf('%s format is invalid.', $label));
        }

        return $normalized;
    }

    private function normalizeOptional(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}
