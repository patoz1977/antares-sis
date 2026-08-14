<?php

declare(strict_types=1);

namespace App\InstitutionalDocuments\Domain;

use App\InstitutionalDocuments\Domain\Exception\InvalidInstitutionalAcknowledgementState;
use App\InstitutionalDocuments\Domain\ValueObject\AcademicPeriodId;
use App\InstitutionalDocuments\Domain\ValueObject\RepresentativeAcknowledgementCompletionId;
use App\InstitutionalDocuments\Domain\ValueObject\RepresentativeId;
use DateTimeImmutable;

final class RepresentativeAcknowledgementCompletion
{
    /** @param list<RepresentativeAcknowledgement> $acknowledgements */
    private function __construct(
        private readonly ?RepresentativeAcknowledgementCompletionId $id,
        private readonly RepresentativeId $representativeId,
        private readonly AcademicPeriodId $academicPeriodId,
        private readonly DateTimeImmutable $completedAt,
        private readonly array $acknowledgements,
    ) {
    }

    /** @param array<array-key, mixed> $requirements */
    public static function complete(
        RepresentativeId $representativeId,
        AcademicPeriodId $academicPeriodId,
        DateTimeImmutable $completedAt,
        array $requirements,
    ): self {
        if ($requirements === []) {
            throw new InvalidInstitutionalAcknowledgementState(
                'A completion requires at least one acknowledgement requirement.'
            );
        }

        $acknowledgements = [];
        $requirementIds = [];

        foreach ($requirements as $requirement) {
            if (!$requirement instanceof AcknowledgementRequirement) {
                throw new InvalidInstitutionalAcknowledgementState(
                    'Every completion requirement must be an AcknowledgementRequirement.'
                );
            }

            $requirementId = $requirement->id();
            if ($requirementId === null) {
                throw new InvalidInstitutionalAcknowledgementState(
                    'Every completion requirement must have a persisted identity.'
                );
            }

            if (!$requirement->academicPeriodId()->equals($academicPeriodId)) {
                throw new InvalidInstitutionalAcknowledgementState(
                    'Every completion requirement must belong to its AcademicPeriod.'
                );
            }

            if (!$requirement->isActive()) {
                throw new InvalidInstitutionalAcknowledgementState(
                    'Every completion requirement must be active.'
                );
            }

            if (isset($requirementIds[$requirementId->value()])) {
                throw new InvalidInstitutionalAcknowledgementState(
                    'A completion cannot contain a duplicate acknowledgement requirement.'
                );
            }

            $requirementIds[$requirementId->value()] = true;
            $acknowledgements[] = RepresentativeAcknowledgement::create($requirementId);
        }

        return new self(null, $representativeId, $academicPeriodId, $completedAt, $acknowledgements);
    }

    /** @param array<array-key, mixed> $acknowledgements */
    public static function reconstitute(
        RepresentativeAcknowledgementCompletionId $id,
        RepresentativeId $representativeId,
        AcademicPeriodId $academicPeriodId,
        DateTimeImmutable $completedAt,
        array $acknowledgements,
    ): self {
        if ($acknowledgements === []) {
            throw new InvalidInstitutionalAcknowledgementState(
                'A reconstituted completion requires at least one acknowledgement.'
            );
        }

        $validated = [];
        $acknowledgementIds = [];
        $requirementIds = [];

        foreach ($acknowledgements as $acknowledgement) {
            if (!$acknowledgement instanceof RepresentativeAcknowledgement) {
                throw new InvalidInstitutionalAcknowledgementState(
                    'Every reconstituted child must be a RepresentativeAcknowledgement.'
                );
            }

            $acknowledgementId = $acknowledgement->id();
            if ($acknowledgementId === null) {
                throw new InvalidInstitutionalAcknowledgementState(
                    'Every reconstituted acknowledgement must have a persisted identity.'
                );
            }

            $requirementId = $acknowledgement->acknowledgementRequirementId();
            if (isset($acknowledgementIds[$acknowledgementId->value()])) {
                throw new InvalidInstitutionalAcknowledgementState(
                    'A completion cannot contain a duplicate acknowledgement identity.'
                );
            }

            if (isset($requirementIds[$requirementId->value()])) {
                throw new InvalidInstitutionalAcknowledgementState(
                    'A completion cannot contain a duplicate acknowledgement requirement.'
                );
            }

            $acknowledgementIds[$acknowledgementId->value()] = true;
            $requirementIds[$requirementId->value()] = true;
            $validated[] = $acknowledgement;
        }

        return new self($id, $representativeId, $academicPeriodId, $completedAt, $validated);
    }

    public function id(): ?RepresentativeAcknowledgementCompletionId
    {
        return $this->id;
    }

    public function representativeId(): RepresentativeId
    {
        return $this->representativeId;
    }

    public function academicPeriodId(): AcademicPeriodId
    {
        return $this->academicPeriodId;
    }

    public function completedAt(): DateTimeImmutable
    {
        return $this->completedAt;
    }

    /** @return list<RepresentativeAcknowledgement> */
    public function acknowledgements(): array
    {
        return $this->acknowledgements;
    }
}
