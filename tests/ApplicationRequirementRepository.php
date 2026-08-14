<?php

declare(strict_types=1);

namespace Tests;

use App\InstitutionalDocuments\Domain\AcknowledgementRequirement;
use App\InstitutionalDocuments\Domain\AcknowledgementRequirementRepository;
use App\InstitutionalDocuments\Domain\ValueObject\AcademicPeriodId;
use App\InstitutionalDocuments\Domain\ValueObject\AcknowledgementRequirementId;

final class ApplicationRequirementRepository implements AcknowledgementRequirementRepository
{
    /** @var array<int, AcknowledgementRequirement> */
    private array $requirements = [];
    public int $saveCount = 0;
    public int $findByPeriodCount = 0;
    public int $hasAcknowledgementsCount = 0;
    /** @var array<int, bool> */
    public array $historicalRequirementIds = [];
    /** @var null|callable(AcknowledgementRequirement, AcknowledgementRequirement): AcknowledgementRequirement */
    public $saveResult = null;
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

    public function hasAcknowledgements(AcknowledgementRequirementId $id): bool
    {
        $this->hasAcknowledgementsCount++;

        return $this->historicalRequirementIds[$id->value()] ?? false;
    }

    public function save(AcknowledgementRequirement $requirement): AcknowledgementRequirement
    {
        $this->saveCount++;
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
}
