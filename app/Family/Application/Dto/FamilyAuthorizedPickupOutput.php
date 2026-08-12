<?php

declare(strict_types=1);

namespace App\Family\Application\Dto;

use App\Family\Application\Exception\InvalidPersistedFamilyResult;
use App\Family\Domain\FamilyAuthorizedPickup;

final readonly class FamilyAuthorizedPickupOutput
{
    public function __construct(
        public int $id,
        public string $names,
        public int $relationshipTypeId,
        public string $mobilePhone,
        public ?string $phone,
        public ?int $documentTypeId,
        public ?string $documentNumber,
        public ?string $observations,
        public string $status,
    ) {
    }

    public static function fromPickup(FamilyAuthorizedPickup $resource): self
    {
        $id = $resource->id();
        if ($id === null) {
            throw new InvalidPersistedFamilyResult(
                'Family repository returned an Authorized Pickup without persisted identity.'
            );
        }

        $information = $resource->contactInformation();
        $identification = $resource->identification();

        return new self(
            $id->value(),
            $resource->names()->value(),
            $resource->relationshipTypeId()->value(),
            $information->mobilePhone(),
            $information->phone(),
            $identification?->documentTypeId()->value(),
            $identification?->documentNumber(),
            $information->observations(),
            $resource->status()->value,
        );
    }
}
