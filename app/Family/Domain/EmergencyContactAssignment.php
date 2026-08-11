<?php

declare(strict_types=1);

namespace App\Family\Domain;

use App\Family\Domain\Exception\InvalidFamilyState;
use App\Family\Domain\ValueObject\EmergencyContactAssignmentId;
use App\Family\Domain\ValueObject\EmergencyContactPriority;
use App\Family\Domain\ValueObject\FamilyEmergencyContactId;
use App\Family\Domain\ValueObject\StudentId;
use DateTimeImmutable;

final class EmergencyContactAssignment
{
    private readonly DateTimeImmutable $startedAt;

    private ?DateTimeImmutable $endedAt;

    public function __construct(
        private readonly ?EmergencyContactAssignmentId $id,
        private readonly FamilyEmergencyContactId $familyEmergencyContactId,
        private readonly StudentId $studentId,
        private readonly ?EmergencyContactPriority $priority,
        DateTimeImmutable $startedAt,
        ?DateTimeImmutable $endedAt,
    ) {
        $normalizedStartedAt = self::toSecondPrecision($startedAt);
        $normalizedEndedAt = $endedAt === null ? null : self::toSecondPrecision($endedAt);
        self::assertDateOrder($normalizedStartedAt, $normalizedEndedAt);
        $this->startedAt = $normalizedStartedAt;
        $this->endedAt = $normalizedEndedAt;
    }

    public function id(): ?EmergencyContactAssignmentId
    {
        return $this->id;
    }

    public function familyEmergencyContactId(): FamilyEmergencyContactId
    {
        return $this->familyEmergencyContactId;
    }

    public function studentId(): StudentId
    {
        return $this->studentId;
    }

    public function priority(): ?EmergencyContactPriority
    {
        return $this->priority;
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
            throw new InvalidFamilyState('Emergency contact assignment is already ended.');
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
            throw new InvalidFamilyState('Emergency contact assignment end cannot precede its start.');
        }
    }
}
