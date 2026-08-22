<?php

declare(strict_types=1);

namespace App\Enrollment\Application\RepresentativePortal\Dto;

final readonly class UpdateRepresentativeContactInformationInput
{
    public function __construct(
        public int $expectedFamilyId,
        public int $expectedAcademicPeriodId,
        public string $email,
        public ?string $mobilePhone,
        public ?string $landlinePhone,
    ) {
    }
}
