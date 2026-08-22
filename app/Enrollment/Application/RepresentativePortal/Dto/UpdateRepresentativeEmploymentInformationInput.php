<?php

declare(strict_types=1);

namespace App\Enrollment\Application\RepresentativePortal\Dto;

final readonly class UpdateRepresentativeEmploymentInformationInput
{
    public function __construct(
        public int $expectedFamilyId,
        public int $expectedAcademicPeriodId,
        public ?string $occupation,
        public ?string $companyName,
        public ?string $position,
        public ?string $workPhone,
        public ?string $workEmail,
    ) {
    }
}
