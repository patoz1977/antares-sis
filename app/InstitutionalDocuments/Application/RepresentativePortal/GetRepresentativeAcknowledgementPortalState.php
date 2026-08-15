<?php

declare(strict_types=1);

namespace App\InstitutionalDocuments\Application\RepresentativePortal;

use App\InstitutionalDocuments\Application\Exception\InvalidPersistedAcknowledgementResult;
use App\InstitutionalDocuments\Application\GetRepresentativeAcknowledgementState;
use App\InstitutionalDocuments\Application\RepresentativePortal\Dto\RepresentativeAcknowledgementPortalState;

final readonly class GetRepresentativeAcknowledgementPortalState
{
    public function __construct(
        private ResolveRepresentativeAcknowledgementContext $resolveContext,
        private GetRepresentativeAcknowledgementState $getState,
    ) {
    }

    public function handle(): RepresentativeAcknowledgementPortalState
    {
        $context = $this->resolveContext->handle();
        $state = $this->getState->handle($context->representativeId, $context->academicPeriodId);
        if ($state->representativeId !== $context->representativeId
            || $state->academicPeriodId !== $context->academicPeriodId
        ) {
            throw new InvalidPersistedAcknowledgementResult(
                'Representative acknowledgement state does not match the resolved context.'
            );
        }

        $status = $state->completed
            ? 'completed'
            : ($state->satisfied ? 'not_required' : 'pending');

        return new RepresentativeAcknowledgementPortalState(
            $context,
            $status,
            $state->satisfied,
            $state->completed,
            $state->completionId,
            $state->completedAt,
            $state->activeRequirements,
        );
    }
}
