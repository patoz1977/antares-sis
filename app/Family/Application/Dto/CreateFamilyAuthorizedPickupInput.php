<?php

declare(strict_types=1);

namespace App\Family\Application\Dto;

final readonly class CreateFamilyAuthorizedPickupInput
{
    public function __construct(
        public int $familyId,
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
