<?php

declare(strict_types=1);

namespace Tests;

use App\AcademicCore\Domain\AcademicPeriodStatus;
use App\AcademicCore\Domain\Exception\AcademicPeriodOperationalStateConflict;
use App\InstitutionalDocuments\Application\Exception\InvalidAcknowledgementConfirmation;
use App\InstitutionalDocuments\Application\RepresentativePortal\CompleteAuthenticatedRepresentativeAcknowledgements;
use App\InstitutionalDocuments\Application\RepresentativePortal\Exception\ActiveAcademicPeriodUnavailable;
use App\InstitutionalDocuments\Application\RepresentativePortal\Exception\RepresentativeAcknowledgementAccessUnavailable;
use App\InstitutionalDocuments\Application\RepresentativePortal\Exception\RepresentativeAcknowledgementsRequired;
use App\InstitutionalDocuments\Application\RepresentativePortal\RequireRepresentativeAcknowledgementSatisfaction;
use App\InstitutionalDocuments\Application\RepresentativePortal\ResolveRepresentativeAcknowledgementContext;
use App\InstitutionalDocuments\Domain\AcknowledgementRequirementStatus;
use ReflectionMethod;
use Tests\Support\TestRunner;

function registerRepresentativeAcknowledgementsApplicationTests(TestRunner $runner): void
{
    $runner->add('E009 Representative context resolves actor and ACTIVE period only server-side', function (): void {
        $identity = familyContextAuthorizationFixture();
        $services = representativeAcknowledgementTestServices($identity['getRepresentative']);
        $context = $services['resolve']->handle();

        assertSameValue(33, $context->representativeId);
        assertSameValue(5, $context->academicPeriodId);
        assertSameValue('2026-2027', $context->academicPeriodCode);
        assertSameValue('Academic Period 2026-2027', $context->academicPeriodName);
        assertSameValue([], (new ReflectionMethod(
            ResolveRepresentativeAcknowledgementContext::class,
            'handle',
        ))->getParameters());
        assertSameValue(['acknowledgedRequirementIds'], array_map(
            static fn (object $parameter): string => $parameter->getName(),
            (new ReflectionMethod(
                CompleteAuthenticatedRepresentativeAcknowledgements::class,
                'handle',
            ))->getParameters(),
        ));
    });

    $runner->add('E009 Representative context fails closed without actor or ACTIVE period', function (): void {
        $withoutRepresentative = familyContextAuthorizationFixture(withRepresentative: false);
        assertThrows(
            representativeAcknowledgementTestServices($withoutRepresentative['getRepresentative'])['resolve']->handle(...),
            RepresentativeAcknowledgementAccessUnavailable::class,
        );

        $identity = familyContextAuthorizationFixture();
        assertThrows(
            representativeAcknowledgementTestServices($identity['getRepresentative'], [], [])['resolve']->handle(...),
            ActiveAcademicPeriodUnavailable::class,
        );

        $multiple = representativeAcknowledgementTestServices($identity['getRepresentative'], [], [
            representativeAcknowledgementPeriod(5, AcademicPeriodStatus::Active),
            representativeAcknowledgementPeriod(6, AcademicPeriodStatus::Active, '2027-2028'),
        ]);
        assertThrows($multiple['resolve']->handle(...), AcademicPeriodOperationalStateConflict::class);
    });

    $runner->add('E009 Representative state distinguishes pending zero requirements and completion', function (): void {
        $identity = familyContextAuthorizationFixture();
        $pending = representativeAcknowledgementTestServices($identity['getRepresentative'], [
            representativeAcknowledgementRequirement(10),
        ]);
        $pendingState = $pending['state']->handle();
        assertSameValue('pending', $pendingState->status);
        assertSameValue(false, $pendingState->satisfied);
        assertSameValue([10], array_map(
            static fn (object $requirement): int => $requirement->id,
            $pendingState->activeRequirements,
        ));

        $empty = representativeAcknowledgementTestServices($identity['getRepresentative']);
        assertSameValue('not_required', $empty['state']->handle()->status);
        assertSameValue(0, $empty['completions']->saveCount);

        $inactiveOnly = representativeAcknowledgementTestServices($identity['getRepresentative'], [
            representativeAcknowledgementRequirement(
                12,
                5,
                status: AcknowledgementRequirementStatus::Inactive,
            ),
        ]);
        assertSameValue('not_required', $inactiveOnly['state']->handle()->status);
        assertSameValue(0, $inactiveOnly['completions']->saveCount);

        $output = $pending['complete']->handle([10]);
        assertSameValue(33, $output->representativeId);
        assertSameValue(5, $output->academicPeriodId);
        assertSameValue('2026-08-14 20:21:22', $output->completedAt?->format('Y-m-d H:i:s'));
        assertSameValue('completed', $pending['state']->handle()->status);
        assertSameValue(1, $pending['completions']->saveCount);
    });

    $runner->add('E009 satisfaction gate handles pending empty completed and actor mismatch', function (): void {
        $identity = familyContextAuthorizationFixture();
        $pending = representativeAcknowledgementTestServices($identity['getRepresentative'], [
            representativeAcknowledgementRequirement(10),
        ]);
        assertThrows(
            static fn () => $pending['gate']->handle(33),
            RepresentativeAcknowledgementsRequired::class,
        );
        assertThrows(
            static fn () => $pending['gate']->handle(99),
            RepresentativeAcknowledgementAccessUnavailable::class,
        );

        $empty = representativeAcknowledgementTestServices($identity['getRepresentative']);
        $empty['gate']->handle(33);
        assertSameValue(0, $empty['completions']->saveCount);

        $pending['complete']->handle([10]);
        $pending['gate']->handle(33);
        assertSameValue(1, $pending['completions']->saveCount);
    });

    $runner->add('E009 POST re-resolves ACTIVE period and rejects a stale period set', function (): void {
        $identity = familyContextAuthorizationFixture();
        $services = representativeAcknowledgementTestServices($identity['getRepresentative'], [
            representativeAcknowledgementRequirement(10, 5),
            representativeAcknowledgementRequirement(20, 6),
        ], [
            representativeAcknowledgementPeriod(5, AcademicPeriodStatus::Active),
            representativeAcknowledgementPeriod(6, AcademicPeriodStatus::Inactive, '2027-2028'),
        ]);
        assertSameValue([10], array_map(
            static fn (object $requirement): int => $requirement->id,
            $services['state']->handle()->activeRequirements,
        ));

        $periodA = $services['periods']->findById(new \App\AcademicCore\Domain\ValueObject\AcademicPeriodId(5));
        $periodB = $services['periods']->findById(new \App\AcademicCore\Domain\ValueObject\AcademicPeriodId(6));
        $periodA?->deactivate();
        $periodB?->activate();
        $services['periods']->save($periodA);
        $services['periods']->save($periodB);

        assertThrows(
            static fn () => $services['complete']->handle([10]),
            InvalidAcknowledgementConfirmation::class,
        );
        assertSameValue([], $services['completions']->snapshot());
        assertSameValue(6, $services['resolve']->handle()->academicPeriodId);
    });

    $runner->add('E009 POST rejects changed ACTIVE set while completed history remains stable', function (): void {
        $identity = familyContextAuthorizationFixture();
        $services = representativeAcknowledgementTestServices($identity['getRepresentative'], [
            representativeAcknowledgementRequirement(10),
        ]);
        $services['state']->handle();
        $services['requirements']->save(representativeAcknowledgementRequirement(11));
        assertThrows(
            static fn () => $services['complete']->handle([10]),
            InvalidAcknowledgementConfirmation::class,
        );
        assertSameValue([], $services['completions']->snapshot());

        $deactivated = representativeAcknowledgementTestServices($identity['getRepresentative'], [
            representativeAcknowledgementRequirement(12),
            representativeAcknowledgementRequirement(13),
        ]);
        $deactivated['state']->handle();
        $requirement = $deactivated['requirements']->findById(
            new \App\InstitutionalDocuments\Domain\ValueObject\AcknowledgementRequirementId(13),
        );
        $requirement?->deactivate();
        $deactivated['requirements']->save($requirement);
        assertThrows(
            static fn () => $deactivated['complete']->handle([12, 13]),
            InvalidAcknowledgementConfirmation::class,
        );
        assertSameValue([], $deactivated['completions']->snapshot());

        $stable = representativeAcknowledgementTestServices($identity['getRepresentative'], [
            representativeAcknowledgementRequirement(20),
        ]);
        $stable['complete']->handle([20]);
        $stable['requirements']->save(representativeAcknowledgementRequirement(21));
        $stable['requirements']->save(representativeAcknowledgementRequirement(
            20,
            5,
            'Institutional requirement',
            'https://example.test/changed',
            null,
            AcknowledgementRequirementStatus::Inactive,
        ));
        $stable['gate']->handle(33);
        assertSameValue('completed', $stable['state']->handle()->status);
        assertSameValue(1, $stable['completions']->saveCount);
    });

    $runner->add('E009 Representative Application integration stays free of HTTP persistence and session', function (): void {
        $path = dirname(__DIR__) . '/app/InstitutionalDocuments/Application/RepresentativePortal';
        $source = '';
        foreach (applicationPhpFiles($path) as $file) {
            $source .= (string) file_get_contents($file);
        }
        foreach (['PDO', 'SQL', 'Request', 'Response', 'SessionManager', 'Controller', 'htmlspecialchars'] as $forbidden) {
            assertSameValue(false, str_contains($source, $forbidden), $forbidden);
        }
        foreach (['GetAuthenticatedRepresentative', 'GetActiveAcademicPeriod',
            'GetRepresentativeAcknowledgementState', 'CompleteRepresentativeAcknowledgements',
            'InstitutionalAcknowledgementSatisfaction', 'Clock'] as $required) {
            assertSameValue(true, str_contains($source, $required), $required);
        }
        assertSameValue(1, count((new ReflectionMethod(
            RequireRepresentativeAcknowledgementSatisfaction::class,
            'handle',
        ))->getParameters()));
    });
}
