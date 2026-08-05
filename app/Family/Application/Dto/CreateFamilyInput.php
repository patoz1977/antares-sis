<?php

declare(strict_types=1);

namespace App\Family\Application\Dto;

use App\Family\Domain\FamilyStatus;
use DateTimeImmutable;

final readonly class CreateFamilyInput
{
    public function __construct(
        public string $displayName,
        public FamilyStatus $status,
        public int $initialRepresentativeId,
        public int $initialRelationshipTypeId,
        public DateTimeImmutable $startedAt,
    ) {
    }
}
