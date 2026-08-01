<?php

declare(strict_types=1);

namespace App\Person\Application\Dto;

use App\Person\Domain\PersonStatus;
use DateTimeImmutable;

final readonly class UpdatePersonInput
{
    public function __construct(
        public int $personId,
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
        public PersonStatus $status,
    ) {
    }
}
