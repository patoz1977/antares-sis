<?php

declare(strict_types=1);

namespace App\Family\Domain;

use App\Family\Domain\ValueObject\Address;
use App\Family\Domain\ValueObject\AddressLabel;
use App\Family\Domain\ValueObject\FamilyAddressId;

final class FamilyAddress
{
    public function __construct(
        private readonly ?FamilyAddressId $id,
        private AddressLabel $label,
        private Address $address,
        private FamilyResourceStatus $status,
    ) {
    }

    public function id(): ?FamilyAddressId
    {
        return $this->id;
    }

    public function label(): AddressLabel
    {
        return $this->label;
    }

    public function address(): Address
    {
        return $this->address;
    }

    public function status(): FamilyResourceStatus
    {
        return $this->status;
    }

    public function update(AddressLabel $label, Address $address): void
    {
        $this->label = $label;
        $this->address = $address;
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
