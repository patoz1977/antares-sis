<?php

declare(strict_types=1);

namespace App\Enrollment\Domain\ValueObject;

use App\Enrollment\Domain\Exception\InvalidEnrollmentState;

final readonly class Geolocation
{
    private string $latitude;

    private string $longitude;

    public function __construct(string $latitude, string $longitude)
    {
        $this->latitude = self::coordinate($latitude, -90, 90, 'Latitude');
        $this->longitude = self::coordinate($longitude, -180, 180, 'Longitude');
    }

    public function latitude(): string
    {
        return $this->latitude;
    }

    public function longitude(): string
    {
        return $this->longitude;
    }

    public function equals(self $other): bool
    {
        return $this->latitude === $other->latitude && $this->longitude === $other->longitude;
    }

    private static function coordinate(string $value, int $minimum, int $maximum, string $label): string
    {
        $normalized = trim($value);
        if (preg_match('/^-?\d{1,3}(?:\.\d{1,7})?$/D', $normalized) !== 1) {
            throw new InvalidEnrollmentState(sprintf(
                '%s must be a decimal with at most 7 decimal places.',
                $label,
            ));
        }

        $numeric = (float) $normalized;
        if ($numeric < $minimum || $numeric > $maximum) {
            throw new InvalidEnrollmentState(sprintf('%s is outside its valid range.', $label));
        }

        return number_format($numeric, 7, '.', '');
    }
}
