<?php

declare(strict_types=1);

namespace App\InstitutionalDocuments\Application\RepresentativePortal;

use App\InstitutionalDocuments\Application\Contract\InstitutionalAcknowledgementSatisfaction;
use App\InstitutionalDocuments\Application\RepresentativePortal\Exception\RepresentativeAcknowledgementAccessUnavailable;
use App\InstitutionalDocuments\Application\RepresentativePortal\Exception\RepresentativeAcknowledgementsRequired;

final readonly class RequireRepresentativeAcknowledgementSatisfaction
{
    public function __construct(
        private ResolveRepresentativeAcknowledgementContext $resolveContext,
        private InstitutionalAcknowledgementSatisfaction $satisfaction,
    ) {
    }

    public function handle(?int $expectedRepresentativeId = null): void
    {
        $context = $this->resolveContext->handle();
        if ($expectedRepresentativeId !== null
            && $context->representativeId !== $expectedRepresentativeId
        ) {
            throw new RepresentativeAcknowledgementAccessUnavailable(
                'Representative acknowledgement context is unavailable.'
            );
        }

        if (!$this->satisfaction->isSatisfied(
            $context->representativeId,
            $context->academicPeriodId,
        )) {
            throw new RepresentativeAcknowledgementsRequired(
                'Institutional Acknowledgements are required.'
            );
        }
    }
}
