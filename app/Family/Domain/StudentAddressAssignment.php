<?php

declare(strict_types=1);

namespace App\Family\Domain;

use App\Family\Domain\Exception\InvalidFamilyState;
use App\Family\Domain\ValueObject\FamilyAddressId;
use App\Family\Domain\ValueObject\StudentAddressAssignmentId;
use App\Family\Domain\ValueObject\StudentId;
use DateTimeImmutable;

final class StudentAddressAssignment
{
    private readonly DateTimeImmutable $startedAt;

    private ?DateTimeImmutable $endedAt;

    public function __construct(
        private readonly ?StudentAddressAssignmentId $id,
        private readonly FamilyAddressId $familyAddressId,
        private readonly StudentId $studentId,
        DateTimeImmutable $startedAt,
        ?DateTimeImmutable $endedAt,
    ) {
        $normalizedStartedAt = self::toSecondPrecision($startedAt);
        $normalizedEndedAt = $endedAt === null ? null : self::toSecondPrecision($endedAt);
        self::assertDateOrder($normalizedStartedAt, $normalizedEndedAt);
        $this->startedAt = $normalizedStartedAt;
        $this->endedAt = $normalizedEndedAt;
    }

    public function id(): ?StudentAddressAssignmentId
    {
        return $this->id;
    }

    public function familyAddressId(): FamilyAddressId
    {
        return $this->familyAddressId;
    }

    public function studentId(): StudentId
    {
        return $this->studentId;
    }

    public function startedAt(): DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function endedAt(): ?DateTimeImmutable
    {
        return $this->endedAt;
    }

    public function isActive(): bool
    {
        return $this->endedAt === null;
    }

    public function end(DateTimeImmutable $endedAt): void
    {
        if (!$this->isActive()) {
            throw new InvalidFamilyState('Student address assignment is already ended.');
        }

        $normalizedEndedAt = self::toSecondPrecision($endedAt);
        self::assertDateOrder($this->startedAt, $normalizedEndedAt);
        $this->endedAt = $normalizedEndedAt;
    }

    private static function toSecondPrecision(DateTimeImmutable $value): DateTimeImmutable
    {
        return $value->setTime((int) $value->format('H'), (int) $value->format('i'), (int) $value->format('s'));
    }

    private static function assertDateOrder(DateTimeImmutable $startedAt, ?DateTimeImmutable $endedAt): void
    {
        if ($endedAt !== null && $endedAt < $startedAt) {
            throw new InvalidFamilyState('Student address assignment end cannot precede its start.');
        }
    }
}
