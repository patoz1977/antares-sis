<?php

declare(strict_types=1);

namespace App\InstitutionalDocuments\Application\RepresentativePortal;

use App\IdentityAccess\Application\Contract\Clock;
use App\InstitutionalDocuments\Application\CompleteRepresentativeAcknowledgements;
use App\InstitutionalDocuments\Application\Dto\CompleteRepresentativeAcknowledgementsInput;
use App\InstitutionalDocuments\Application\Dto\RepresentativeAcknowledgementCompletionOutput;
use App\InstitutionalDocuments\Application\Exception\InvalidPersistedAcknowledgementResult;

final readonly class CompleteAuthenticatedRepresentativeAcknowledgements
{
    public function __construct(
        private ResolveRepresentativeAcknowledgementContext $resolveContext,
        private CompleteRepresentativeAcknowledgements $complete,
        private Clock $clock,
    ) {
    }

    /** @param array<array-key, mixed> $acknowledgedRequirementIds */
    public function handle(array $acknowledgedRequirementIds): RepresentativeAcknowledgementCompletionOutput
    {
        $context = $this->resolveContext->handle();
        $output = $this->complete->handle(new CompleteRepresentativeAcknowledgementsInput(
            $context->representativeId,
            $context->academicPeriodId,
            $acknowledgedRequirementIds,
            $this->clock->now(),
        ));

        if (!$output->satisfied
            || $output->representativeId !== $context->representativeId
            || $output->academicPeriodId !== $context->academicPeriodId
        ) {
            throw new InvalidPersistedAcknowledgementResult(
                'Representative acknowledgement completion does not match the resolved context.'
            );
        }

        return $output;
    }
}
