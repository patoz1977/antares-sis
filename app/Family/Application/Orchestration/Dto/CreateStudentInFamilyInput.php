<?php

declare(strict_types=1);

namespace App\Family\Application\Orchestration\Dto;

use App\Person\Domain\PersonStatus;
use App\Student\Domain\StudentStatus;
use DateTimeImmutable;

final readonly class CreateStudentInFamilyInput
{
    public function __construct(
        public int $familyId,
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
        public string $institutionalCode,
        public DateTimeImmutable $admissionDate,
        public StudentStatus $studentStatus,
        public DateTimeImmutable $startedAt,
    ) {
    }
}
