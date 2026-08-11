<?php

declare(strict_types=1);

namespace App\Family\Application\Dto;

final readonly class CreateFamilyEmergencyContactInput
{
    public function __construct(
        public int $familyId,
        public string $names,
        public int $relationshipTypeId,
        public string $mobilePhone,
        public ?string $phone,
        public ?string $email,
        public ?string $observations,
    ) {
    }
}
