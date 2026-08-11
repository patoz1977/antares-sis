<?php

declare(strict_types=1);

namespace App\Family\Domain\ValueObject;

use App\Family\Domain\Exception\InvalidFamilyState;

final readonly class Address
{
    private string $mainStreet;

    private ?string $streetNumber;

    private ?string $secondaryStreet;

    private ?string $sector;

    private ?string $reference;

    public function __construct(
        string $mainStreet,
        ?string $streetNumber,
        ?string $secondaryStreet,
        ?string $sector,
        ?string $reference,
        private ?Geolocation $geolocation,
    ) {
        $this->mainStreet = self::required($mainStreet, 200, 'Main street');
        $this->streetNumber = self::optional($streetNumber, 50, 'Street number');
        $this->secondaryStreet = self::optional($secondaryStreet, 200, 'Secondary street');
        $this->sector = self::optional($sector, 150, 'Sector');
        $this->reference = self::optional($reference, 255, 'Reference');
    }

    public function mainStreet(): string
    {
        return $this->mainStreet;
    }

    public function streetNumber(): ?string
    {
        return $this->streetNumber;
    }

    public function secondaryStreet(): ?string
    {
        return $this->secondaryStreet;
    }

    public function sector(): ?string
    {
        return $this->sector;
    }

    public function reference(): ?string
    {
        return $this->reference;
    }

    public function geolocation(): ?Geolocation
    {
        return $this->geolocation;
    }

    public function equals(self $other): bool
    {
        return $this->mainStreet === $other->mainStreet
            && $this->streetNumber === $other->streetNumber
            && $this->secondaryStreet === $other->secondaryStreet
            && $this->sector === $other->sector
            && $this->reference === $other->reference
            && (($this->geolocation === null && $other->geolocation === null)
                || ($this->geolocation !== null
                    && $other->geolocation !== null
                    && $this->geolocation->equals($other->geolocation)));
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
