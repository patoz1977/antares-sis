<?php

declare(strict_types=1);

namespace App\InstitutionalDocuments\Application\Dto;

use App\InstitutionalDocuments\Application\Exception\InvalidPersistedAcknowledgementResult;
use App\InstitutionalDocuments\Domain\RepresentativeAcknowledgement;
use App\InstitutionalDocuments\Domain\RepresentativeAcknowledgementCompletion;
use App\InstitutionalDocuments\Domain\ValueObject\AcademicPeriodId;
use App\InstitutionalDocuments\Domain\ValueObject\RepresentativeId;
use DateTimeImmutable;
use DateTimeZone;

final readonly class RepresentativeAcknowledgementCompletionOutput
{
    /** @param list<int> $acknowledgedRequirementIds */
    public function __construct(
        public int $representativeId,
        public int $academicPeriodId,
        public bool $satisfied,
        public ?int $completionId,
        public ?DateTimeImmutable $completedAt,
        public array $acknowledgedRequirementIds,
    ) {
    }

    /** @param list<int>|null $expectedRequirementIds */
    public static function fromCompletion(
        RepresentativeAcknowledgementCompletion $completion,
        RepresentativeId $expectedRepresentativeId,
        AcademicPeriodId $expectedAcademicPeriodId,
        ?DateTimeImmutable $expectedCompletedAt = null,
        ?array $expectedRequirementIds = null,
    ): self {
        $id = $completion->id();
        if ($id === null
            || !$completion->representativeId()->equals($expectedRepresentativeId)
            || !$completion->academicPeriodId()->equals($expectedAcademicPeriodId)
            || ($expectedCompletedAt !== null
                && self::secondTimestamp($completion->completedAt())
                    !== self::secondTimestamp($expectedCompletedAt))
        ) {
            throw new InvalidPersistedAcknowledgementResult(
                'Acknowledgement Completion persistence returned incoherent root state.'
            );
        }

        $requirementIds = [];
        foreach ($completion->acknowledgements() as $acknowledgement) {
            if (!$acknowledgement instanceof RepresentativeAcknowledgement
                || $acknowledgement->id() === null
            ) {
                throw new InvalidPersistedAcknowledgementResult(
                    'Acknowledgement Completion returned an unpersisted child.'
                );
            }

            $requirementId = $acknowledgement->acknowledgementRequirementId()->value();
            if (isset($requirementIds[$requirementId])) {
                throw new InvalidPersistedAcknowledgementResult(
                    'Acknowledgement Completion returned duplicate Requirement identity.'
                );
            }
            $requirementIds[$requirementId] = true;
        }

        $actualIds = array_keys($requirementIds);
        sort($actualIds, SORT_NUMERIC);
        if ($expectedRequirementIds !== null) {
            $expectedIds = $expectedRequirementIds;
            sort($expectedIds, SORT_NUMERIC);
            if ($actualIds !== $expectedIds) {
                throw new InvalidPersistedAcknowledgementResult(
                    'Acknowledgement Completion persistence returned an incoherent Requirement set.'
                );
            }
        }

        return new self(
            $expectedRepresentativeId->value(),
            $expectedAcademicPeriodId->value(),
            true,
            $id->value(),
            $completion->completedAt(),
            $actualIds,
        );
    }

    public static function satisfiedWithoutCompletion(
        RepresentativeId $representativeId,
        AcademicPeriodId $academicPeriodId,
    ): self {
        return new self(
            $representativeId->value(),
            $academicPeriodId->value(),
            true,
            null,
            null,
            [],
        );
    }

    private static function secondTimestamp(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
