<?php

declare(strict_types=1);

namespace App\Family\Application\Dto;

use App\Family\Application\Exception\InvalidPersistedFamilyResult;
use App\Family\Domain\FamilyAddress;

final readonly class FamilyAddressOutput
{
    public function __construct(
        public int $id,
        public string $label,
        public string $mainStreet,
        public ?string $streetNumber,
        public ?string $secondaryStreet,
        public ?string $sector,
        public ?string $reference,
        public ?string $latitude,
        public ?string $longitude,
        public string $status,
    ) {
    }

    public static function fromAddress(FamilyAddress $resource): self
    {
        $id = $resource->id();
        if ($id === null) {
            throw new InvalidPersistedFamilyResult(
                'Family repository returned an Address without persisted identity.'
            );
        }

        $address = $resource->address();
        $geolocation = $address->geolocation();

        return new self(
            $id->value(),
            $resource->label()->value(),
            $address->mainStreet(),
            $address->streetNumber(),
            $address->secondaryStreet(),
            $address->sector(),
            $address->reference(),
            $geolocation?->latitude(),
            $geolocation?->longitude(),
            $resource->status()->value,
        );
    }
}
