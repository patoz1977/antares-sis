<?php

declare(strict_types=1);

namespace App\Family\Application\Dto;

final readonly class UpdateFamilyAddressInput
{
    public function __construct(
        public int $familyId,
        public int $familyAddressId,
        public string $label,
        public string $mainStreet,
        public ?string $streetNumber,
        public ?string $secondaryStreet,
        public ?string $sector,
        public ?string $reference,
        public ?string $latitude,
        public ?string $longitude,
    ) {
    }
}
