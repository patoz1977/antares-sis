<?php

declare(strict_types=1);

namespace App\Family\Application\Dto;

use App\Family\Application\Exception\InvalidPersistedFamilyResult;
use App\Family\Domain\EmergencyContactAssignment;
use DateTimeImmutable;

final readonly class EmergencyContactAssignmentOutput
{
    public function __construct(
        public int $id,
        public int $familyEmergencyContactId,
        public int $studentId,
        public ?int $priority,
        public DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $endedAt,
        public bool $isActive,
    ) {
    }

    public static function fromAssignment(EmergencyContactAssignment $assignment): self
    {
        $id = $assignment->id();
        if ($id === null) {
            throw new InvalidPersistedFamilyResult(
                'Family repository returned an Emergency Contact assignment without persisted identity.'
            );
        }

        return new self(
            $id->value(),
            $assignment->familyEmergencyContactId()->value(),
            $assignment->studentId()->value(),
            $assignment->priority()?->value(),
            $assignment->startedAt(),
            $assignment->endedAt(),
            $assignment->isActive(),
        );
    }
}
