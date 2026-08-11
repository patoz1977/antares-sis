<?php

declare(strict_types=1);

namespace App\Family\Application\Dto;

final readonly class UpdateFamilyAuthorizedPickupInput
{
    public function __construct(
        public int $familyId,
        public int $familyAuthorizedPickupId,
        public string $names,
        public int $relationshipTypeId,
        public string $mobilePhone,
        public ?string $phone,
        public ?int $documentTypeId,
        public ?string $documentNumber,
        public ?string $observations,
    ) {
    }
}
