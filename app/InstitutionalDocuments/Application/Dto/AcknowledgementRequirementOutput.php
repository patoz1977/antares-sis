<?php

declare(strict_types=1);

namespace App\InstitutionalDocuments\Application\Dto;

use App\InstitutionalDocuments\Application\Exception\InvalidPersistedAcknowledgementResult;
use App\InstitutionalDocuments\Domain\AcknowledgementRequirement;
use App\InstitutionalDocuments\Domain\ValueObject\AcknowledgementOfficialReference;

final readonly class AcknowledgementRequirementOutput
{
    public function __construct(
        public int $id,
        public int $academicPeriodId,
        public string $title,
        public string $url,
        public ?string $officialReference,
        public string $status,
    ) {
    }

    public static function fromRequirement(
        AcknowledgementRequirement $requirement,
        ?AcknowledgementRequirement $expected = null,
    ): self {
        $id = $requirement->id();
        if ($id === null) {
            throw new InvalidPersistedAcknowledgementResult(
                'Acknowledgement Requirement does not have a persisted identity.'
            );
        }

        if ($expected !== null && !self::sameState($requirement, $expected)) {
            throw new InvalidPersistedAcknowledgementResult(
                'Acknowledgement Requirement persistence returned incoherent state.'
            );
        }

        return new self(
            $id->value(),
            $requirement->academicPeriodId()->value(),
            $requirement->title()->value(),
            $requirement->url()->value(),
            $requirement->officialReference()?->value(),
            $requirement->status()->value,
        );
    }

    private static function sameState(
        AcknowledgementRequirement $persisted,
        AcknowledgementRequirement $expected,
    ): bool {
        $persistedId = $persisted->id();
        $expectedId = $expected->id();

        return $persistedId !== null
            && ($expectedId === null || $persistedId->equals($expectedId))
            && $persisted->academicPeriodId()->equals($expected->academicPeriodId())
            && $persisted->title()->equals($expected->title())
            && $persisted->url()->equals($expected->url())
            && self::sameOfficialReference(
                $persisted->officialReference(),
                $expected->officialReference(),
            )
            && $persisted->status() === $expected->status();
    }

    private static function sameOfficialReference(
        ?AcknowledgementOfficialReference $left,
        ?AcknowledgementOfficialReference $right,
    ): bool {
        return $left === null ? $right === null : $right !== null && $left->equals($right);
    }
}
