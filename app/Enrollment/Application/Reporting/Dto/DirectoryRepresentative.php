<?php

declare(strict_types=1);

namespace App\Enrollment\Application\Reporting\Dto;

final readonly class DirectoryRepresentative
{
    public function __construct(
        public string $firstName,
        public ?string $middleName,
        public string $firstSurname,
        public ?string $secondSurname,
        public string $relationship,
        public ?string $identificationType,
        public ?string $identificationNumber,
        public ?string $mobilePhone,
        public ?string $landlinePhone,
        public ?string $personalEmail,
    ) {
    }
}
