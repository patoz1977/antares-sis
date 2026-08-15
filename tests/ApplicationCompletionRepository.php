<?php

declare(strict_types=1);

namespace Tests;

use App\InstitutionalDocuments\Domain\RepresentativeAcknowledgementCompletion;
use App\InstitutionalDocuments\Domain\RepresentativeAcknowledgementCompletionRepository;
use App\InstitutionalDocuments\Domain\ValueObject\AcademicPeriodId;
use App\InstitutionalDocuments\Domain\ValueObject\RepresentativeId;
use Throwable;

final class ApplicationCompletionRepository implements RepresentativeAcknowledgementCompletionRepository
{
    /** @var array<string, RepresentativeAcknowledgementCompletion> */
    private array $completions = [];
    public int $saveCount = 0;
    public ?Throwable $saveFailure = null;
    /** @var null|callable(RepresentativeAcknowledgementCompletion, RepresentativeAcknowledgementCompletion): RepresentativeAcknowledgementCompletion */
    public $saveResult = null;
    private int $nextCompletionId = 100;
    private int $nextAcknowledgementId = 500;

    public function findByRepresentativeAndAcademicPeriod(
        RepresentativeId $representativeId,
        AcademicPeriodId $academicPeriodId,
    ): ?RepresentativeAcknowledgementCompletion {
        return $this->completions[$this->key($representativeId, $academicPeriodId)] ?? null;
    }

    public function save(RepresentativeAcknowledgementCompletion $completion): RepresentativeAcknowledgementCompletion
    {
        $this->saveCount++;
        if ($this->saveFailure !== null) {
            throw $this->saveFailure;
        }

        $persisted = applicationPersistedFromNew(
            $completion,
            $this->nextCompletionId++,
            $this->nextAcknowledgementId,
        );
        $this->nextAcknowledgementId += count($persisted->acknowledgements());
        $this->completions[$this->key($persisted->representativeId(), $persisted->academicPeriodId())] = $persisted;

        return $this->saveResult === null
            ? $persisted
            : ($this->saveResult)($persisted, $completion);
    }

    /** @return array<string, RepresentativeAcknowledgementCompletion> */
    public function snapshot(): array
    {
        return $this->completions;
    }

    /** @param array<string, RepresentativeAcknowledgementCompletion> $snapshot */
    public function restore(array $snapshot): void
    {
        $this->completions = $snapshot;
    }

    private function key(RepresentativeId $representativeId, AcademicPeriodId $academicPeriodId): string
    {
        return $representativeId->value() . ':' . $academicPeriodId->value();
    }
}
