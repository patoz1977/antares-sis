<?php

declare(strict_types=1);

namespace App\Family\Domain\ValueObject;

use App\Family\Domain\Exception\InvalidFamilyState;

final readonly class EmergencyContactInformation
{
    private string $mobilePhone;

    private ?string $phone;

    private ?string $email;

    private ?string $observations;

    public function __construct(
        string $mobilePhone,
        ?string $phone,
        ?string $email,
        ?string $observations,
    ) {
        $this->mobilePhone = self::required($mobilePhone, 30, 'Mobile phone');
        $this->phone = self::optional($phone, 30, 'Phone');
        $this->email = self::normalizeEmail($email);
        $this->observations = self::optional($observations, 500, 'Observations');
    }

    public function mobilePhone(): string
    {
        return $this->mobilePhone;
    }

    public function phone(): ?string
    {
        return $this->phone;
    }

    public function email(): ?string
    {
        return $this->email;
    }

    public function observations(): ?string
    {
        return $this->observations;
    }

    public function equals(self $other): bool
    {
        return $this->mobilePhone === $other->mobilePhone
            && $this->phone === $other->phone
            && $this->email === $other->email
            && $this->observations === $other->observations;
    }

    private static function normalizeEmail(?string $value): ?string
    {
        $normalized = self::optional($value, 254, 'Email');
        if ($normalized !== null && filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidFamilyState('Email format is invalid.');
        }

        return $normalized;
    }

    private static function required(string $value, int $maximum, string $label): string
    {
        $normalized = trim($value);
        if ($normalized === '' || mb_strlen($normalized, 'UTF-8') > $maximum) {
            throw new InvalidFamilyState(sprintf(
                '%s must contain between 1 and %d characters.',
                $label,
                $maximum,
            ));
        }

        return $normalized;
    }

    private static function optional(?string $value, int $maximum, string $label): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $normalized = trim($value);
        if (mb_strlen($normalized, 'UTF-8') > $maximum) {
            throw new InvalidFamilyState(sprintf('%s cannot exceed %d characters.', $label, $maximum));
        }

        return $normalized;
    }
}
