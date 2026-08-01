<?php

declare(strict_types=1);

namespace App\Person\Domain;

use App\Person\Domain\Exception\InvalidPersonState;
use App\Person\Domain\ValueObject\ContactInformation;
use App\Person\Domain\ValueObject\Identification;
use App\Person\Domain\ValueObject\PersonalName;
use App\Person\Domain\ValueObject\PersonId;
use DateTimeImmutable;

final class Person
{
    public function __construct(
        private readonly ?PersonId $id,
        private PersonalName $personalName,
        private ?Identification $identification,
        DateTimeImmutable $birthDate,
        private int $sexId,
        private ?int $maritalStatusId,
        private ?int $educationLevelId,
        private ?ContactInformation $contactInformation,
        private PersonStatus $status,
        DateTimeImmutable $today,
    ) {
        $this->assertCatalogIds($this->sexId, $this->maritalStatusId, $this->educationLevelId);
        $this->assertBirthDate($birthDate, $today);
        $this->birthDate = $birthDate->setTime(0, 0);
    }

    private DateTimeImmutable $birthDate;

    public function id(): ?PersonId
    {
        return $this->id;
    }

    public function personalName(): PersonalName
    {
        return $this->personalName;
    }

    public function identification(): ?Identification
    {
        return $this->identification;
    }

    public function birthDate(): DateTimeImmutable
    {
        return $this->birthDate;
    }

    public function sexId(): int
    {
        return $this->sexId;
    }

    public function maritalStatusId(): ?int
    {
        return $this->maritalStatusId;
    }

    public function educationLevelId(): ?int
    {
        return $this->educationLevelId;
    }

    public function contactInformation(): ?ContactInformation
    {
        return $this->contactInformation;
    }

    public function status(): PersonStatus
    {
        return $this->status;
    }

    public function updateIdentity(
        PersonalName $personalName,
        ?Identification $identification,
        DateTimeImmutable $birthDate,
        int $sexId,
        ?int $maritalStatusId,
        ?int $educationLevelId,
        DateTimeImmutable $today,
    ): void {
        $this->assertCatalogIds($sexId, $maritalStatusId, $educationLevelId);
        $this->assertBirthDate($birthDate, $today);

        $this->personalName = $personalName;
        $this->identification = $identification;
        $this->birthDate = $birthDate->setTime(0, 0);
        $this->sexId = $sexId;
        $this->maritalStatusId = $maritalStatusId;
        $this->educationLevelId = $educationLevelId;
    }

    public function updateContactInformation(?ContactInformation $contactInformation): void
    {
        $this->contactInformation = $contactInformation;
    }

    public function activate(): void
    {
        $this->status = PersonStatus::Active;
    }

    public function deactivate(): void
    {
        $this->status = PersonStatus::Inactive;
    }

    public function isActive(): bool
    {
        return $this->status === PersonStatus::Active;
    }

    private function assertBirthDate(DateTimeImmutable $birthDate, DateTimeImmutable $today): void
    {
        if ($birthDate->format('Y-m-d') > $today->format('Y-m-d')) {
            throw new InvalidPersonState('Birth date cannot be in the future.');
        }
    }

    private function assertCatalogIds(
        int $sexId,
        ?int $maritalStatusId,
        ?int $educationLevelId,
    ): void {
        if ($sexId <= 0) {
            throw new InvalidPersonState('SexId must be positive.');
        }

        if ($maritalStatusId !== null && $maritalStatusId <= 0) {
            throw new InvalidPersonState('MaritalStatusId must be positive when defined.');
        }

        if ($educationLevelId !== null && $educationLevelId <= 0) {
            throw new InvalidPersonState('EducationLevelId must be positive when defined.');
        }
    }
}
