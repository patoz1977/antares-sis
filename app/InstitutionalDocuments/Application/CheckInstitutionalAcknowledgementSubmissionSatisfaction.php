<?php

declare(strict_types=1);

namespace App\InstitutionalDocuments\Application;

use App\InstitutionalDocuments\Application\Contract\InstitutionalAcknowledgementSubmissionSatisfaction;
use App\InstitutionalDocuments\Application\Dto\RepresentativeAcknowledgementCompletionOutput;
use App\InstitutionalDocuments\Domain\AcknowledgementRequirementRepository;
use App\InstitutionalDocuments\Domain\RepresentativeAcknowledgementCompletionRepository;
use App\InstitutionalDocuments\Domain\ValueObject\AcademicPeriodId;
use App\InstitutionalDocuments\Domain\ValueObject\RepresentativeId;

final readonly class CheckInstitutionalAcknowledgementSubmissionSatisfaction implements
    InstitutionalAcknowledgementSubmissionSatisfaction
{
    public function __construct(
        private AcknowledgementRequirementRepository $requirements,
        private RepresentativeAcknowledgementCompletionRepository $completions,
    ) {
    }

    public function isSatisfiedInStableContext(
        int $representativeId,
        int $academicPeriodId,
    ): bool {
        $representative = new RepresentativeId($representativeId);
        $period = new AcademicPeriodId($academicPeriodId);
        $this->requirements->lockConfigurationScopeForRead($period);

        $completion = $this->completions->findByRepresentativeAndAcademicPeriod(
            $representative,
            $period,
        );
        if ($completion !== null) {
            RepresentativeAcknowledgementCompletionOutput::fromCompletion(
                $completion,
                $representative,
                $period,
            );

            return true;
        }

        return AcknowledgementRequirementApplicationSupport::activeForPeriod(
            $this->requirements,
            $period,
        ) === [];
    }
}
