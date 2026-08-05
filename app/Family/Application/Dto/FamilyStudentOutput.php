<?php

declare(strict_types=1);

namespace App\Family\Application\Dto;

use App\Family\Application\Exception\InvalidPersistedFamilyResult;
use App\Family\Domain\FamilyStudent;
use DateTimeImmutable;

final readonly class FamilyStudentOutput
{
    public function __construct(
        public int $id,
        public int $studentId,
        public DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $endedAt,
        public bool $isActive,
    ) {
    }

    public static function fromMembership(FamilyStudent $membership): self
    {
        $id = $membership->id();
        if ($id === null) {
            throw new InvalidPersistedFamilyResult(
                'Family repository returned a Student membership without persisted identity.'
            );
        }

        return new self(
            $id->value(),
            $membership->studentId()->value(),
            $membership->startedAt(),
            $membership->endedAt(),
            $membership->isActive(),
        );
    }
}
