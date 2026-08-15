<?php

declare(strict_types=1);

namespace Tests;

use App\AcademicCore\Application\GetActiveAcademicPeriod;
use App\AcademicCore\Domain\AcademicPeriod;
use App\AcademicCore\Domain\AcademicPeriodStatus;
use App\AcademicCore\Domain\ValueObject\AcademicPeriodCode;
use App\AcademicCore\Domain\ValueObject\AcademicPeriodDateRange;
use App\AcademicCore\Domain\ValueObject\AcademicPeriodId;
use App\AcademicCore\Domain\ValueObject\AcademicPeriodName;
use App\IdentityAccess\Application\Contract\Clock;
use App\IdentityAccess\Application\GetAuthenticatedRepresentative;
use App\InstitutionalDocuments\Application\CheckInstitutionalAcknowledgementSatisfaction;
use App\InstitutionalDocuments\Application\CompleteRepresentativeAcknowledgements;
use App\InstitutionalDocuments\Application\GetRepresentativeAcknowledgementState;
use App\InstitutionalDocuments\Application\RepresentativePortal\CompleteAuthenticatedRepresentativeAcknowledgements;
use App\InstitutionalDocuments\Application\RepresentativePortal\GetRepresentativeAcknowledgementPortalState;
use App\InstitutionalDocuments\Application\RepresentativePortal\RequireRepresentativeAcknowledgementSatisfaction;
use App\InstitutionalDocuments\Application\RepresentativePortal\ResolveRepresentativeAcknowledgementContext;
use App\InstitutionalDocuments\Domain\AcknowledgementRequirement;
use App\InstitutionalDocuments\Domain\AcknowledgementRequirementStatus;
use App\InstitutionalDocuments\Domain\ValueObject\AcademicPeriodId as RequirementAcademicPeriodId;
use App\InstitutionalDocuments\Domain\ValueObject\AcknowledgementOfficialReference;
use App\InstitutionalDocuments\Domain\ValueObject\AcknowledgementRequirementId;
use App\InstitutionalDocuments\Domain\ValueObject\AcknowledgementRequirementTitle;
use App\InstitutionalDocuments\Domain\ValueObject\AcknowledgementRequirementUrl;
use DateTimeImmutable;

/** @param list<AcknowledgementRequirement> $requirements @param list<AcademicPeriod>|null $periods */
function representativeAcknowledgementTestServices(
    GetAuthenticatedRepresentative $representative,
    array $requirements = [],
    ?array $periods = null,
): array {
    $periodRepository = new InMemoryAcademicPeriodRepository(
        $periods ?? [representativeAcknowledgementPeriod(5, AcademicPeriodStatus::Active)],
    );
    $requirementRepository = new ApplicationRequirementRepository($requirements);
    $completionRepository = new ApplicationCompletionRepository();
    $resolve = new ResolveRepresentativeAcknowledgementContext(
        $representative,
        new GetActiveAcademicPeriod($periodRepository),
    );
    $getState = new GetRepresentativeAcknowledgementState(
        $requirementRepository,
        $completionRepository,
    );
    $transactions = new ApplicationAcknowledgementTransactionRunner($completionRepository);
    $clock = new class implements Clock {
        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable('2026-08-14 20:21:22+00:00');
        }
    };
    $satisfaction = new CheckInstitutionalAcknowledgementSatisfaction(
        $requirementRepository,
        $completionRepository,
    );

    return [
        'periods' => $periodRepository,
        'requirements' => $requirementRepository,
        'completions' => $completionRepository,
        'resolve' => $resolve,
        'state' => new GetRepresentativeAcknowledgementPortalState($resolve, $getState),
        'complete' => new CompleteAuthenticatedRepresentativeAcknowledgements(
            $resolve,
            new CompleteRepresentativeAcknowledgements(
                $requirementRepository,
                $completionRepository,
                $transactions,
            ),
            $clock,
        ),
        'gate' => new RequireRepresentativeAcknowledgementSatisfaction($resolve, $satisfaction),
        'clock' => $clock,
        'transactions' => $transactions,
    ];
}

function representativeAcknowledgementPeriod(
    int $id,
    AcademicPeriodStatus $status,
    string $code = '2026-2027',
): AcademicPeriod {
    return new AcademicPeriod(
        new AcademicPeriodId($id),
        new AcademicPeriodCode($code),
        new AcademicPeriodName('Academic Period ' . $code),
        new AcademicPeriodDateRange(
            new DateTimeImmutable('2026-09-01+00:00'),
            new DateTimeImmutable('2027-06-30+00:00'),
        ),
        $status,
    );
}

function representativeAcknowledgementRequirement(
    int $id,
    int $periodId = 5,
    string $title = 'Institutional requirement',
    string $url = 'https://example.test/requirement',
    ?string $officialReference = null,
    AcknowledgementRequirementStatus $status = AcknowledgementRequirementStatus::Active,
): AcknowledgementRequirement {
    return AcknowledgementRequirement::reconstitute(
        new AcknowledgementRequirementId($id),
        new RequirementAcademicPeriodId($periodId),
        new AcknowledgementRequirementTitle($title),
        new AcknowledgementRequirementUrl($url),
        $officialReference === null ? null : new AcknowledgementOfficialReference($officialReference),
        $status,
    );
}
