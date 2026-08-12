<?php

declare(strict_types=1);

namespace App\Family\Application\Dto;

use DateTimeImmutable;

final readonly class AssignRepresentativeAddressInput
{
    public function __construct(
        public int $familyId,
        public int $representativeId,
        public int $familyAddressId,
        public DateTimeImmutable $startedAt,
    ) {
    }
}
