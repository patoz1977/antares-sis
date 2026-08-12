<?php

declare(strict_types=1);

namespace App\Family\Application\Dto;

use App\Family\Application\Exception\InvalidPersistedFamilyResult;
use App\Family\Domain\RepresentativeAddressAssignment;
use DateTimeImmutable;

final readonly class RepresentativeAddressAssignmentOutput
{
    public function __construct(
        public int $id,
        public int $familyAddressId,
        public int $representativeId,
        public DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $endedAt,
        public bool $isActive,
    ) {
    }

    public static function fromAssignment(RepresentativeAddressAssignment $assignment): self
    {
        $id = $assignment->id();
        if ($id === null) {
            throw new InvalidPersistedFamilyResult(
                'Family repository returned a Representative address assignment without persisted identity.'
            );
        }

        return new self(
            $id->value(),
            $assignment->familyAddressId()->value(),
            $assignment->representativeId()->value(),
            $assignment->startedAt(),
            $assignment->endedAt(),
            $assignment->isActive(),
        );
    }
}
