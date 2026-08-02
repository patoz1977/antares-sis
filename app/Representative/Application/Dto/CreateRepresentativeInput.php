<?php

declare(strict_types=1);

namespace App\Representative\Application\Dto;

use App\Representative\Domain\RepresentativeStatus;

final readonly class CreateRepresentativeInput
{
    public function __construct(
        public int $personId,
        public ?string $occupation,
        public ?string $companyName,
        public ?string $position,
        public ?string $workPhone,
        public ?string $workEmail,
        public RepresentativeStatus $status,
    ) {
    }
}
