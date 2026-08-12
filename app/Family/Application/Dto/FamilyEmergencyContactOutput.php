<?php

declare(strict_types=1);

namespace App\Family\Application\Dto;

use App\Family\Application\Exception\InvalidPersistedFamilyResult;
use App\Family\Domain\FamilyEmergencyContact;

final readonly class FamilyEmergencyContactOutput
{
    public function __construct(
        public int $id,
        public string $names,
        public int $relationshipTypeId,
        public string $mobilePhone,
        public ?string $phone,
        public ?string $email,
        public ?string $observations,
        public string $status,
    ) {
    }

    public static function fromContact(FamilyEmergencyContact $resource): self
    {
        $id = $resource->id();
        if ($id === null) {
            throw new InvalidPersistedFamilyResult(
                'Family repository returned an Emergency Contact without persisted identity.'
            );
        }

        $information = $resource->contactInformation();

        return new self(
            $id->value(),
            $resource->names()->value(),
            $resource->relationshipTypeId()->value(),
            $information->mobilePhone(),
            $information->phone(),
            $information->email(),
            $information->observations(),
            $resource->status()->value,
        );
    }
}
