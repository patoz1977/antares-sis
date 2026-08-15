<?php

declare(strict_types=1);

namespace App\Enrollment\Domain;

use App\Enrollment\Domain\Support\EnrollmentText;
use App\Enrollment\Domain\ValueObject\Geolocation;
use App\Enrollment\Domain\ValueObject\SubmittedAddressSnapshotId;

final readonly class SubmittedAddressSnapshot
{
    private string $label;

    private string $mainStreet;

    private ?string $streetNumber;

    private ?string $secondaryStreet;

    private ?string $sector;

    private ?string $reference;

    private function __construct(
        private ?SubmittedAddressSnapshotId $id,
        string $label,
        string $mainStreet,
        ?string $streetNumber,
        ?string $secondaryStreet,
        ?string $sector,
        ?string $reference,
        private ?Geolocation $geolocation,
    ) {
        $this->label = EnrollmentText::required($label, 100, 'Address label');
        $this->mainStreet = EnrollmentText::required($mainStreet, 200, 'Main street');
        $this->streetNumber = EnrollmentText::optional($streetNumber, 50, 'Street number');
        $this->secondaryStreet = EnrollmentText::optional($secondaryStreet, 200, 'Secondary street');
        $this->sector = EnrollmentText::optional($sector, 150, 'Sector');
        $this->reference = EnrollmentText::optional($reference, 255, 'Address reference');
    }

    public static function create(
        string $label,
        string $mainStreet,
        ?string $streetNumber,
        ?string $secondaryStreet,
        ?string $sector,
        ?string $reference,
        ?Geolocation $geolocation,
    ): self {
        return new self(
            null,
            $label,
            $mainStreet,
            $streetNumber,
            $secondaryStreet,
            $sector,
            $reference,
            $geolocation,
        );
    }

    public static function reconstitute(
        SubmittedAddressSnapshotId $id,
        string $label,
        string $mainStreet,
        ?string $streetNumber,
        ?string $secondaryStreet,
        ?string $sector,
        ?string $reference,
        ?Geolocation $geolocation,
    ): self {
        return new self(
            $id,
            $label,
            $mainStreet,
            $streetNumber,
            $secondaryStreet,
            $sector,
            $reference,
            $geolocation,
        );
    }

    public function id(): ?SubmittedAddressSnapshotId
    {
        return $this->id;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function mainStreet(): string
    {
        return $this->mainStreet;
    }

    public function streetNumber(): ?string
    {
        return $this->streetNumber;
    }

    public function secondaryStreet(): ?string
    {
        return $this->secondaryStreet;
    }

    public function sector(): ?string
    {
        return $this->sector;
    }

    public function reference(): ?string
    {
        return $this->reference;
    }

    public function geolocation(): ?Geolocation
    {
        return $this->geolocation;
    }
}
