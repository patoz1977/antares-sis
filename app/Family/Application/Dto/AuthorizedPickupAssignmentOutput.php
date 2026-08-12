<?php

declare(strict_types=1);

namespace App\Family\Application\Dto;

use App\Family\Application\Exception\InvalidPersistedFamilyResult;
use App\Family\Domain\AuthorizedPickupAssignment;
use DateTimeImmutable;

final readonly class AuthorizedPickupAssignmentOutput
{
    public function __construct(
        public int $id,
        public int $familyAuthorizedPickupId,
        public int $studentId,
        public DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $endedAt,
        public bool $isActive,
    ) {
    }

    public static function fromAssignment(AuthorizedPickupAssignment $assignment): self
    {
        $id = $assignment->id();
        if ($id === null) {
            throw new InvalidPersistedFamilyResult(
                'Family repository returned an Authorized Pickup assignment without persisted identity.'
            );
        }

        return new self(
            $id->value(),
            $assignment->familyAuthorizedPickupId()->value(),
            $assignment->studentId()->value(),
            $assignment->startedAt(),
            $assignment->endedAt(),
            $assignment->isActive(),
        );
    }
}
