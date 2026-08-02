<?php

declare(strict_types=1);

namespace App\Representative\Application\Dto;

use App\Representative\Domain\Representative;
use App\Representative\Domain\RepresentativeStatus;
use App\Representative\Domain\ValueObject\RepresentativeId;

final readonly class RepresentativeOutput
{
    public function __construct(
        public int $id,
        public int $personId,
        public ?string $occupation,
        public ?string $companyName,
        public ?string $position,
        public ?string $workPhone,
        public ?string $workEmail,
        public RepresentativeStatus $status,
    ) {
    }

    public static function fromRepresentative(
        Representative $representative,
        RepresentativeId $id,
    ): self {
        $employment = $representative->employmentInformation();

        return new self(
            $id->value(),
            $representative->personId()->value(),
            $employment?->occupation(),
            $employment?->companyName(),
            $employment?->position(),
            $employment?->workPhone(),
            $employment?->workEmail(),
            $representative->status(),
        );
    }
}
