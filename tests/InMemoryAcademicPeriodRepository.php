<?php

declare(strict_types=1);

namespace Tests;

use App\AcademicCore\Domain\AcademicPeriod;
use App\AcademicCore\Domain\AcademicPeriodRepository;
use App\AcademicCore\Domain\Exception\AcademicPeriodOperationalStateConflict;
use App\AcademicCore\Domain\ValueObject\AcademicPeriodId;
use RuntimeException;

final class InMemoryAcademicPeriodRepository implements AcademicPeriodRepository
{
    /** @var array<int, AcademicPeriod> */
    private array $periods = [];

    public int $saveCount = 0;

    public int $lockCount = 0;

    public ?int $failOnSaveNumber = null;

    /** @param list<AcademicPeriod> $periods */
    public function __construct(array $periods)
    {
        foreach ($periods as $period) {
            $id = $period->id();
            if ($id === null) {
                throw new RuntimeException('In-memory AcademicPeriod requires identity.');
            }
            $this->periods[$id->value()] = self::copy($period);
        }
    }

    public function findById(AcademicPeriodId $id): ?AcademicPeriod
    {
        $period = $this->periods[$id->value()] ?? null;

        return $period === null ? null : self::copy($period);
    }

    public function findActive(): ?AcademicPeriod
    {
        $active = array_values(array_filter(
            $this->periods,
            static fn (AcademicPeriod $period): bool => $period->isActive(),
        ));
        if (count($active) > 1) {
            throw new AcademicPeriodOperationalStateConflict('Multiple ACTIVE AcademicPeriods.');
        }

        return $active === [] ? null : self::copy($active[0]);
    }

    public function save(AcademicPeriod $period): AcademicPeriod
    {
        $id = $period->id();
        if ($id === null || !isset($this->periods[$id->value()])) {
            throw new RuntimeException('AcademicPeriod cannot be persisted.');
        }

        $this->saveCount++;
        if ($this->failOnSaveNumber === $this->saveCount) {
            throw new RuntimeException('Forced AcademicPeriod persistence failure.');
        }
        $this->periods[$id->value()] = self::copy($period);

        return self::copy($period);
    }

    public function lockOperationalTransition(): void
    {
        $this->lockCount++;
    }

    private static function copy(AcademicPeriod $period): AcademicPeriod
    {
        $copy = unserialize(serialize($period), ['allowed_classes' => true]);
        if (!$copy instanceof AcademicPeriod) {
            throw new RuntimeException('AcademicPeriod could not be copied.');
        }

        return $copy;
    }
}
