<?php

declare(strict_types=1);

namespace App\Family\Application\Orchestration\Dto;

use App\Family\Domain\FamilyStatus;
use App\Person\Domain\PersonStatus;
use App\Representative\Domain\RepresentativeStatus;
use DateTimeImmutable;

final readonly class CreateRepresentativeFamilyInput
{
    public function __construct(
        public string $firstName,
        public ?string $middleName,
        public string $firstSurname,
        public ?string $secondSurname,
        public ?int $documentTypeId,
        public ?string $documentNumber,
        public DateTimeImmutable $birthDate,
        public int $sexId,
        public ?int $maritalStatusId,
        public ?int $educationLevelId,
        public ?string $email,
        public ?string $mobilePhone,
        public ?string $landlinePhone,
        public PersonStatus $personStatus,
        public ?string $occupation,
        public ?string $companyName,
        public ?string $position,
        public ?string $workPhone,
        public ?string $workEmail,
        public RepresentativeStatus $representativeStatus,
        public string $displayName,
        public FamilyStatus $familyStatus,
        public int $relationshipTypeId,
        public DateTimeImmutable $startedAt,
    ) {
    }
}
