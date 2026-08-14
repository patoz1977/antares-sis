<?php

declare(strict_types=1);

namespace App\InstitutionalDocuments\Application;

use App\InstitutionalDocuments\Application\Dto\AcknowledgementRequirementOutput;
use App\InstitutionalDocuments\Application\Dto\RepresentativeAcknowledgementCompletionOutput;
use App\InstitutionalDocuments\Application\Dto\RepresentativeAcknowledgementStateOutput;
use App\InstitutionalDocuments\Domain\AcknowledgementRequirement;
use App\InstitutionalDocuments\Domain\AcknowledgementRequirementRepository;
use App\InstitutionalDocuments\Domain\RepresentativeAcknowledgementCompletionRepository;
use App\InstitutionalDocuments\Domain\ValueObject\AcademicPeriodId;
use App\InstitutionalDocuments\Domain\ValueObject\RepresentativeId;

final readonly class GetRepresentativeAcknowledgementState
{
    public function __construct(
        private AcknowledgementRequirementRepository $requirements,
        private RepresentativeAcknowledgementCompletionRepository $completions,
    ) {
    }

    public function handle(int $representativeId, int $academicPeriodId): RepresentativeAcknowledgementStateOutput
    {
        $representative = new RepresentativeId($representativeId);
        $period = new AcademicPeriodId($academicPeriodId);
        $completion = $this->completions->findByRepresentativeAndAcademicPeriod($representative, $period);
        $completionOutput = $completion === null
            ? null
            : RepresentativeAcknowledgementCompletionOutput::fromCompletion(
                $completion,
                $representative,
                $period,
            );
        $activeRequirements = AcknowledgementRequirementApplicationSupport::activeForPeriod(
            $this->requirements,
            $period,
        );
        $activeOutputs = array_map(
            static fn (AcknowledgementRequirement $requirement): AcknowledgementRequirementOutput =>
                AcknowledgementRequirementOutput::fromRequirement($requirement),
            $activeRequirements,
        );

        return new RepresentativeAcknowledgementStateOutput(
            $representative->value(),
            $period->value(),
            $completionOutput !== null || $activeOutputs === [],
            $completionOutput !== null,
            $completionOutput?->completionId,
            $completionOutput?->completedAt,
            $activeOutputs,
        );
    }
}
