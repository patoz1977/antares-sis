<?php

declare(strict_types=1);

namespace App\Family\Domain;

use App\Family\Domain\ValueObject\EmergencyContactInformation;
use App\Family\Domain\ValueObject\FamilyEmergencyContactId;
use App\Family\Domain\ValueObject\FamilyResourceName;
use App\Family\Domain\ValueObject\RelationshipTypeId;

final class FamilyEmergencyContact
{
    public function __construct(
        private readonly ?FamilyEmergencyContactId $id,
        private FamilyResourceName $names,
        private RelationshipTypeId $relationshipTypeId,
        private EmergencyContactInformation $contactInformation,
        private FamilyResourceStatus $status,
    ) {
    }

    public function id(): ?FamilyEmergencyContactId
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

    public function contactInformation(): EmergencyContactInformation
    {
        return $this->contactInformation;
    }

    public function status(): FamilyResourceStatus
    {
        return $this->status;
    }

    public function update(
        FamilyResourceName $names,
        RelationshipTypeId $relationshipTypeId,
        EmergencyContactInformation $contactInformation,
    ): void {
        $this->names = $names;
        $this->relationshipTypeId = $relationshipTypeId;
        $this->contactInformation = $contactInformation;
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
