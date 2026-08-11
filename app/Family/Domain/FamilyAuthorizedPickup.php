<?php

declare(strict_types=1);

namespace App\Family\Domain;

use App\Family\Domain\ValueObject\AuthorizedPickupInformation;
use App\Family\Domain\ValueObject\FamilyAuthorizedPickupId;
use App\Family\Domain\ValueObject\FamilyResourceName;
use App\Family\Domain\ValueObject\PickupIdentification;
use App\Family\Domain\ValueObject\RelationshipTypeId;

final class FamilyAuthorizedPickup
{
    public function __construct(
        private readonly ?FamilyAuthorizedPickupId $id,
        private FamilyResourceName $names,
        private RelationshipTypeId $relationshipTypeId,
        private AuthorizedPickupInformation $contactInformation,
        private ?PickupIdentification $identification,
        private FamilyResourceStatus $status,
    ) {
    }

    public function id(): ?FamilyAuthorizedPickupId
    {
        return $this->id;
    }

    public function names(): FamilyResourceName
    {
        return $this->names;
    }

    public function relationshipTypeId(): RelationshipTypeId
    {
        return $this->relationshipTypeId;
    }

    public function contactInformation(): AuthorizedPickupInformation
    {
        return $this->contactInformation;
    }

    public function identification(): ?PickupIdentification
    {
        return $this->identification;
    }

    public function status(): FamilyResourceStatus
    {
        return $this->status;
    }

    public function update(
        FamilyResourceName $names,
        RelationshipTypeId $relationshipTypeId,
        AuthorizedPickupInformation $contactInformation,
        ?PickupIdentification $identification,
    ): void {
        $this->names = $names;
        $this->relationshipTypeId = $relationshipTypeId;
        $this->contactInformation = $contactInformation;
        $this->identification = $identification;
    }

    public function activate(): void
    {
        $this->status = FamilyResourceStatus::Active;
    }

    public function deactivate(): void
    {
        $this->status = FamilyResourceStatus::Inactive;
    }

    public function isActive(): bool
    {
        return $this->status === FamilyResourceStatus::Active;
    }
}
