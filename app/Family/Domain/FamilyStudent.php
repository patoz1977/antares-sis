<?php

declare(strict_types=1);

namespace App\Family\Domain;

use App\Family\Domain\Exception\InvalidFamilyState;
use App\Family\Domain\ValueObject\FamilyStudentId;
use App\Family\Domain\ValueObject\StudentId;
use DateTimeImmutable;

final class FamilyStudent
{
    private readonly DateTimeImmutable $startedAt;

    private ?DateTimeImmutable $endedAt;

    public function __construct(
        private readonly ?FamilyStudentId $id,
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

    public function id(): ?FamilyStudentId
    {
        return $this->id;
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
            throw new InvalidFamilyState('FamilyStudent membership is already ended.');
        }

        $normalizedEndedAt = self::toSecondPrecision($endedAt);
        self::assertDateOrder($this->startedAt, $normalizedEndedAt);
        $this->endedAt = $normalizedEndedAt;
    }

    private static function toSecondPrecision(DateTimeImmutable $value): DateTimeImmutable
    {
        return $value->setTime(
            (int) $value->format('H'),
            (int) $value->format('i'),
            (int) $value->format('s'),
        );
    }

    private static function assertDateOrder(
        DateTimeImmutable $startedAt,
        ?DateTimeImmutable $endedAt,
    ): void {
        if ($endedAt !== null && $endedAt < $startedAt) {
            throw new InvalidFamilyState('FamilyStudent end date cannot precede its start date.');
        }
    }
}
