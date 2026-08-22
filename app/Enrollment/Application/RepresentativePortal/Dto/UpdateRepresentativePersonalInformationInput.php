<?php

declare(strict_types=1);

namespace App\Enrollment\Application\RepresentativePortal\Dto;

use DateTimeImmutable;

final readonly class UpdateRepresentativePersonalInformationInput
{
    public function __construct(
        public int $expectedFamilyId,
        public int $expectedAcademicPeriodId,
        public string $firstName,
        public ?string $middleName,
        public string $firstSurname,
        public ?string $secondSurname,
        public DateTimeImmutable $birthDate,
        public ?int $maritalStatusId,
        public ?int $educationLevelId,
    ) {
    }
}
