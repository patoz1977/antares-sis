<?php

declare(strict_types=1);

namespace Tests;

use App\InstitutionalDocuments\Domain\AcknowledgementRequirement;
use App\InstitutionalDocuments\Domain\AcknowledgementRequirementRepository;
use App\InstitutionalDocuments\Domain\ValueObject\AcademicPeriodId;
use App\InstitutionalDocuments\Domain\ValueObject\AcknowledgementRequirementId;
use Throwable;

final class ApplicationRequirementRepository implements AcknowledgementRequirementRepository
{
    /** @var array<int, AcknowledgementRequirement> */
    private array $requirements = [];
    public int $saveCount = 0;
    public int $findByPeriodCount = 0;
    public int $hasAcknowledgementsCount = 0;
    /** @var list<string> */
    public array $operationLog = [];
    /** @var list<int> */
    public array $lockedRequirementIds = [];
    /** @var list<int> */
    public array $lockedConfigurationPeriodIds = [];
    public ?Throwable $scopeLockFailure = null;
    /** @var list<int> */
    public array $lockedCompletionRequirementIds = [];
    /** @var array<int, AcknowledgementRequirement|null> */
    public array $lockedRequirementOverrides = [];
    /** @var array<int, list<AcknowledgementRequirement>> */
    public array $lockedPeriodOverrides = [];
    /** @var array<int, bool> */
    public array $historicalRequirementIds = [];
    /** @var null|callable(AcknowledgementRequirement, AcknowledgementRequirement): AcknowledgementRequirement */
    public $saveResult = null;
    public ?Throwable $saveFailure = null;
    private int $nextId = 100;

    /** @param list<AcknowledgementRequirement> $requirements */
    public function __construct(array $requirements = [])
    {
        foreach ($requirements as $requirement) {
            $id = $requirement->id();
            if ($id !== null) {
                $this->requirements[$id->value()] = $requirement;
                $this->nextId = max($this->nextId, $id->value() + 1);
            }
        }
    }

    public function findById(AcknowledgementRequirementId $id): ?AcknowledgementRequirement
    {
        return $this->requirements[$id->value()] ?? null;
    }

    public function findByAcademicPeriodId(AcademicPeriodId $academicPeriodId): array
    {
        $this->findByPeriodCount++;

        return array_values(array_filter(
            $this->requirements,
            static fn (AcknowledgementRequirement $requirement): bool =>
                $requirement->academicPeriodId()->equals($academicPeriodId),
        ));
    }

    public function lockConfigurationScope(AcademicPeriodId $academicPeriodId): void
    {
        $this->operationLog[] = 'lock:scope:' . $academicPeriodId->value();
        $this->lockedConfigurationPeriodIds[] = $academicPeriodId->value();
        if ($this->scopeLockFailure !== null) {
            throw $this->scopeLockFailure;
        }
    }

    public function lockForPostUseUpdate(
        AcknowledgementRequirementId $id,
    ): ?AcknowledgementRequirement {
        $this->operationLog[] = 'lock:update:' . $id->value();
        $this->lockedRequirementIds[] = $id->value();

        return array_key_exists($id->value(), $this->lockedRequirementOverrides)
            ? $this->lockedRequirementOverrides[$id->value()]
            : ($this->requirements[$id->value()] ?? null);
    }

    public function lockForCompletion(AcademicPeriodId $academicPeriodId): array
    {
        $this->operationLog[] = 'lock:completion:' . $academicPeriodId->value();
        $requirements = $this->lockedPeriodOverrides[$academicPeriodId->value()] ?? array_values(array_filter(
            $this->requirements,
            static fn (AcknowledgementRequirement $requirement): bool =>
                $requirement->academicPeriodId()->equals($academicPeriodId),
        ));
        usort(
            $requirements,
            static fn (AcknowledgementRequirement $left, AcknowledgementRequirement $right): int =>
                ($left->id()?->value() ?? 0) <=> ($right->id()?->value() ?? 0),
        );
        $this->lockedCompletionRequirementIds = array_map(
            static fn (AcknowledgementRequirement $requirement): int => $requirement->id()?->value() ?? 0,
            $requirements,
        );

        return $requirements;
    }

    public function hasAcknowledgements(AcknowledgementRequirementId $id): bool
    {
        $this->hasAcknowledgementsCount++;
        $this->operationLog[] = 'history:' . $id->value();

        return $this->historicalRequirementIds[$id->value()] ?? false;
    }

    public function save(AcknowledgementRequirement $requirement): AcknowledgementRequirement
    {
        $this->saveCount++;
        $this->operationLog[] = 'save:' . ($requirement->id()?->value() ?? 0);
        if ($this->saveFailure !== null) {
            throw $this->saveFailure;
        }
        $persisted = $requirement;
        if ($requirement->id() === null) {
            $persisted = AcknowledgementRequirement::reconstitute(
                new AcknowledgementRequirementId($this->nextId++),
                $requirement->academicPeriodId(),
                $requirement->title(),
                $requirement->url(),
                $requirement->officialReference(),
                $requirement->status(),
            );
        }
        $this->requirements[$persisted->id()?->value() ?? 0] = $persisted;

        return $this->saveResult === null
            ? $persisted
            : ($this->saveResult)($persisted, $requirement);
    }

    /** @return array<int, AcknowledgementRequirement> */
    public function snapshot(): array
    {
        return $this->requirements;
    }

    /** @param array<int, AcknowledgementRequirement> $snapshot */
    public function restore(array $snapshot): void
    {
        $this->requirements = $snapshot;
    }
}
