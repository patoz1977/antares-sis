<?php

declare(strict_types=1);

namespace App\InstitutionalDocuments\Application\RepresentativePortal;

use App\AcademicCore\Application\GetActiveAcademicPeriod;
use App\IdentityAccess\Application\GetAuthenticatedRepresentative;
use App\InstitutionalDocuments\Application\RepresentativePortal\Dto\RepresentativeAcknowledgementContext;
use App\InstitutionalDocuments\Application\RepresentativePortal\Exception\ActiveAcademicPeriodUnavailable;
use App\InstitutionalDocuments\Application\RepresentativePortal\Exception\RepresentativeAcknowledgementAccessUnavailable;

final readonly class ResolveRepresentativeAcknowledgementContext
{
    public function __construct(
        private GetAuthenticatedRepresentative $getAuthenticatedRepresentative,
        private GetActiveAcademicPeriod $getActiveAcademicPeriod,
    ) {
    }

    public function handle(): RepresentativeAcknowledgementContext
    {
        $representative = $this->getAuthenticatedRepresentative->handle();
        if ($representative === null) {
            throw new RepresentativeAcknowledgementAccessUnavailable(
                'Representative acknowledgement access is unavailable.'
            );
        }

        $period = $this->getActiveAcademicPeriod->handle();
        if ($period === null) {
            throw new ActiveAcademicPeriodUnavailable(
                'An active Academic Period is required.'
            );
        }

        return new RepresentativeAcknowledgementContext(
            $representative->representativeId,
            $period->id,
            $period->code,
            $period->name,
            $period->startsOn,
            $period->endsOn,
        );
    }
}
