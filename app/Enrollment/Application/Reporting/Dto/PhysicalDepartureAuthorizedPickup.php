<?php

declare(strict_types=1);

namespace App\Enrollment\Application\Reporting\Dto;

final readonly class PhysicalDepartureAuthorizedPickup
{
    public function __construct(
        public string $name,
        public string $relationship,
        public ?string $identificationType,
        public ?string $identificationNumber,
        public string $mobilePhone,
    ) {
    }
}
