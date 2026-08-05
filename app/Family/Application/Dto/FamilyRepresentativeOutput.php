<?php

declare(strict_types=1);

namespace App\Family\Application\Dto;

use App\Family\Application\Exception\InvalidPersistedFamilyResult;
use App\Family\Domain\FamilyRepresentative;
use DateTimeImmutable;

final readonly class FamilyRepresentativeOutput
{
    public function __construct(
        public int $id,
        public int $representativeId,
        public int $relationshipTypeId,
        public bool $isPrimary,
        public DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $endedAt,
        public bool $isActive,
    ) {
    }

    public static function fromMembership(FamilyRepresentative $membership): self
    {
        $id = $membership->id();
        if ($id === null) {
            throw new InvalidPersistedFamilyResult(
                'Family repository returned a Representative membership without persisted identity.'
            );
        }

        return new self(
            $id->value(),
            $membership->representativeId()->value(),
            $membership->relationshipTypeId()->value(),
            $membership->isPrimary(),
            $membership->startedAt(),
            $membership->endedAt(),
            $membership->isActive(),
        );
    }
}
