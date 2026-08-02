<?php

declare(strict_types=1);

namespace App\Family\Domain;

use App\Family\Domain\Exception\InvalidFamilyState;
use App\Family\Domain\ValueObject\FamilyRepresentativeId;
use App\Family\Domain\ValueObject\RelationshipTypeId;
use App\Family\Domain\ValueObject\RepresentativeId;
use DateTimeImmutable;

final class FamilyRepresentative
{
    private readonly DateTimeImmutable $startedAt;

    private ?DateTimeImmutable $endedAt;

    public function __construct(
        private readonly ?FamilyRepresentativeId $id,
        private readonly RepresentativeId $representativeId,
        private readonly RelationshipTypeId $relationshipTypeId,
        private readonly bool $isPrimary,
        DateTimeImmutable $startedAt,
        ?DateTimeImmutable $endedAt,
    ) {
        $normalizedStartedAt = self::toSecondPrecision($startedAt);
        $normalizedEndedAt = $endedAt === null ? null : self::toSecondPrecision($endedAt);
        self::assertDateOrder($normalizedStartedAt, $normalizedEndedAt);

        $this->startedAt = $normalizedStartedAt;
        $this->endedAt = $normalizedEndedAt;
    }

    public function id(): ?FamilyRepresentativeId
    {
        return $this->id;
    }

    public function representativeId(): RepresentativeId
    {
        return $this->representativeId;
    }

    public function relationshipTypeId(): RelationshipTypeId
    {
        return $this->relationshipTypeId;
    }

    public function isPrimary(): bool
    {
        return $this->isPrimary;
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
            throw new InvalidFamilyState('FamilyRepresentative membership is already ended.');
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
            throw new InvalidFamilyState(
                'FamilyRepresentative end date cannot precede its start date.'
            );
        }
    }
}
