<?php

declare(strict_types=1);

namespace Tests;

use App\InstitutionalDocuments\Application\ActivateAcknowledgementRequirement;
use App\InstitutionalDocuments\Application\CheckInstitutionalAcknowledgementSatisfaction;
use App\InstitutionalDocuments\Application\CompleteRepresentativeAcknowledgements;
use App\InstitutionalDocuments\Application\Contract\InstitutionalAcknowledgementSatisfaction;
use App\InstitutionalDocuments\Application\CreateAcknowledgementRequirement;
use App\InstitutionalDocuments\Application\DeactivateAcknowledgementRequirement;
use App\InstitutionalDocuments\Application\Dto\CompleteRepresentativeAcknowledgementsInput;
use App\InstitutionalDocuments\Application\Dto\CreateAcknowledgementRequirementInput;
use App\InstitutionalDocuments\Application\Dto\RepresentativeAcknowledgementCompletionOutput;
use App\InstitutionalDocuments\Application\Dto\UpdateAcknowledgementRequirementInput;
use App\InstitutionalDocuments\Application\Exception\AcknowledgementRequirementNotFound;
use App\InstitutionalDocuments\Application\Exception\InstitutionalAcknowledgementsAlreadyCompleted;
use App\InstitutionalDocuments\Application\Exception\InvalidAcknowledgementConfirmation;
use App\InstitutionalDocuments\Application\Exception\InvalidPersistedAcknowledgementResult;
use App\InstitutionalDocuments\Application\GetAcknowledgementRequirements;
use App\InstitutionalDocuments\Application\GetRepresentativeAcknowledgementState;
use App\InstitutionalDocuments\Application\UpdateAcknowledgementRequirement;
use App\InstitutionalDocuments\Domain\AcknowledgementRequirement;
use App\InstitutionalDocuments\Domain\AcknowledgementRequirementStatus;
use App\InstitutionalDocuments\Domain\Exception\InvalidInstitutionalAcknowledgementState;
use App\InstitutionalDocuments\Domain\RepresentativeAcknowledgement;
use App\InstitutionalDocuments\Domain\RepresentativeAcknowledgementCompletion;
use App\InstitutionalDocuments\Domain\ValueObject\AcademicPeriodId;
use App\InstitutionalDocuments\Domain\ValueObject\AcknowledgementOfficialReference;
use App\InstitutionalDocuments\Domain\ValueObject\AcknowledgementRequirementId;
use App\InstitutionalDocuments\Domain\ValueObject\AcknowledgementRequirementTitle;
use App\InstitutionalDocuments\Domain\ValueObject\AcknowledgementRequirementUrl;
use App\InstitutionalDocuments\Domain\ValueObject\RepresentativeAcknowledgementCompletionId;
use App\InstitutionalDocuments\Domain\ValueObject\RepresentativeAcknowledgementId;
use App\InstitutionalDocuments\Domain\ValueObject\RepresentativeId;
use DateTimeImmutable;
use ReflectionClass;
use RuntimeException;
use Tests\Support\TestRunner;

function registerInstitutionalAcknowledgementsApplicationTests(TestRunner $runner): void
{
    $runner->add('E009 Application lists zero or complete mixed-state Requirements in Repository order', function (): void {
        $empty = new ApplicationRequirementRepository();
        assertSameValue([], (new GetAcknowledgementRequirements($empty))->handle(9));

        $second = applicationRequirement(20, 9, 'Segundo ñ', 'second/url', null, AcknowledgementRequirementStatus::Inactive);
        $first = applicationRequirement(10, 9, 'Primero', 'first/url', 'REF-1');
        $repository = new ApplicationRequirementRepository([$second, $first]);
        $outputs = (new GetAcknowledgementRequirements($repository))->handle(9);

        assertSameValue([20, 10], array_map(static fn ($output): int => $output->id, $outputs));
        assertSameValue(['INACTIVE', 'ACTIVE'], array_map(static fn ($output): string => $output->status, $outputs));
        assertSameValue([9, 9], array_map(static fn ($output): int => $output->academicPeriodId, $outputs));
        assertSameValue('Segundo ñ', $outputs[0]->title);
        assertSameValue('REF-1', $outputs[1]->officialReference);
    });

    $runner->add('E009 Application creates ACTIVE and INACTIVE Requirements with one verified save', function (): void {
        $repository = new ApplicationRequirementRepository();
        $create = new CreateAcknowledgementRequirement($repository);
        $active = $create->handle(new CreateAcknowledgementRequirementInput(
            4,
            ' Política institucional ñ ',
            ' external/policy ',
            ' REF-Ñ ',
            'ACTIVE',
        ));
        $inactive = $create->handle(new CreateAcknowledgementRequirementInput(
            4,
            'Inactive',
            'inactive/url',
            null,
            'INACTIVE',
        ));

        assertSameValue(2, $repository->saveCount);
        assertSameValue(true, $active->id > 0 && $inactive->id > 0);
        assertSameValue('Política institucional ñ', $active->title);
        assertSameValue('external/policy', $active->url);
        assertSameValue('REF-Ñ', $active->officialReference);
        assertSameValue('ACTIVE', $active->status);
        assertSameValue(null, $inactive->officialReference);
        assertSameValue('INACTIVE', $inactive->status);
    });

    $runner->add('E009 Application rejects invalid create input before save', function (): void {
        $repository = new ApplicationRequirementRepository();
        $create = new CreateAcknowledgementRequirement($repository);
        assertThrows(
            static fn () => $create->handle(new CreateAcknowledgementRequirementInput(
                1,
                'Title',
                'url',
                null,
                'active',
            )),
            InvalidInstitutionalAcknowledgementState::class,
        );
        assertSameValue(0, $repository->saveCount);
    });

    $runner->add('E009 Application rejects every incoherent persisted Requirement result', function (): void {
        $cases = [
            static fn (AcknowledgementRequirement $persisted, AcknowledgementRequirement $requested) => $requested,
            static fn () => applicationRequirement(99, 2, 'Title', 'url', null),
            static fn () => applicationRequirement(99, 1, 'Other title', 'url', null),
            static fn () => applicationRequirement(99, 1, 'Title', 'other/url', null),
            static fn () => applicationRequirement(99, 1, 'Title', 'url', 'OTHER'),
            static fn () => applicationRequirement(99, 1, 'Title', 'url', null, AcknowledgementRequirementStatus::Inactive),
        ];

        foreach ($cases as $transformer) {
            $repository = new ApplicationRequirementRepository();
            $repository->saveResult = $transformer;
            assertThrows(
                static fn () => (new CreateAcknowledgementRequirement($repository))->handle(
                    new CreateAcknowledgementRequirementInput(1, 'Title', 'url', null, 'ACTIVE')
                ),
                InvalidPersistedAcknowledgementResult::class,
            );
            assertSameValue(1, $repository->saveCount);
        }
    });

    $runner->add('E009 Application updates all approved fields before acknowledgement with one save', function (): void {
        $requirement = applicationRequirement(1, 8, 'Original', 'old/url', null);
        $repository = new ApplicationRequirementRepository([$requirement]);
        $transactions = new InMemoryCompositeTransactionRunner([$repository]);
        $output = (new UpdateAcknowledgementRequirement($repository, $transactions))->handle(
            new UpdateAcknowledgementRequirementInput(1, 8, 'Updated', 'new/url', 'NEW-REF')
        );

        assertSameValue(['lock:update:1', 'history:1', 'save:1'], $repository->operationLog);
        assertSameValue([1], $repository->lockedRequirementIds);
        assertSameValue(1, $repository->hasAcknowledgementsCount);
        assertSameValue(1, $repository->saveCount);
        assertSameValue(1, $transactions->beginCount());
        assertSameValue(1, $transactions->commitCount());
        assertSameValue(0, $transactions->rollbackCount());
        assertSameValue('Updated', $output->title);
        assertSameValue('new/url', $output->url);
        assertSameValue('NEW-REF', $output->officialReference);
        assertSameValue(8, $output->academicPeriodId);

        $output = applicationUpdateRequirement($repository)->handle(
            new UpdateAcknowledgementRequirementInput(1, 8, 'Updated again', 'other/url', null)
        );
        assertSameValue(null, $output->officialReference);
    });

    $runner->add('E009 Application update uses only the Requirement reloaded under lock', function (): void {
        $stale = applicationRequirement(1, 8, 'Stale', 'stale/url', null);
        $fresh = applicationRequirement(1, 8, 'Fresh', 'fresh/url', 'FRESH-REF');
        $repository = new ApplicationRequirementRepository([$stale]);
        $repository->lockedRequirementOverrides[1] = $fresh;
        $transactions = new InMemoryCompositeTransactionRunner([$repository]);

        $output = (new UpdateAcknowledgementRequirement($repository, $transactions))->handle(
            new UpdateAcknowledgementRequirementInput(1, 8, 'Fresh', 'new/url', 'FRESH-REF')
        );

        assertSameValue('Fresh', $output->title);
        assertSameValue('new/url', $output->url);
        assertSameValue('FRESH-REF', $output->officialReference);
        assertSameValue(['lock:update:1', 'history:1', 'save:1'], $repository->operationLog);
        assertSameValue(1, $repository->saveCount);
    });

    $runner->add('E009 Application permits normalized same protected values and URL change after acknowledgement', function (): void {
        $requirement = applicationRequirement(1, 8, 'Protected', 'old/url', 'REF');
        $repository = new ApplicationRequirementRepository([$requirement]);
        $repository->historicalRequirementIds[1] = true;

        $output = applicationUpdateRequirement($repository)->handle(
            new UpdateAcknowledgementRequirementInput(1, 8, ' Protected ', 'new/url', ' REF ')
        );

        assertSameValue('Protected', $output->title);
        assertSameValue('REF', $output->officialReference);
        assertSameValue('new/url', $output->url);
        assertSameValue(1, $repository->saveCount);
    });

    $runner->add('E009 Application leaves Requirement unchanged when protected post-use fields are rejected', function (): void {
        foreach ([
            new UpdateAcknowledgementRequirementInput(1, 8, 'Different', 'new/url', 'REF'),
            new UpdateAcknowledgementRequirementInput(1, 8, 'Protected', 'new/url', 'DIFFERENT'),
            new UpdateAcknowledgementRequirementInput(1, 8, 'Protected', 'new/url', null),
        ] as $input) {
            $requirement = applicationRequirement(1, 8, 'Protected', 'old/url', 'REF');
            $repository = new ApplicationRequirementRepository([$requirement]);
            $repository->historicalRequirementIds[1] = true;
            assertThrows(
                static fn () => applicationUpdateRequirement($repository)->handle($input),
                InvalidInstitutionalAcknowledgementState::class,
            );
            assertSameValue(0, $repository->saveCount);
            assertSameValue('Protected', $requirement->title()->value());
            assertSameValue('old/url', $requirement->url()->value());
            assertSameValue('REF', $requirement->officialReference()?->value());
        }

        $nullReference = applicationRequirement(2, 8, 'Protected', 'old/url', null);
        $repository = new ApplicationRequirementRepository([$nullReference]);
        $repository->historicalRequirementIds[2] = true;
        assertThrows(
            static fn () => applicationUpdateRequirement($repository)->handle(
                new UpdateAcknowledgementRequirementInput(2, 8, 'Protected', 'new/url', 'NEW')
            ),
            InvalidInstitutionalAcknowledgementState::class,
        );
        assertSameValue(0, $repository->saveCount);
    });

    $runner->add('E009 Application activates and deactivates explicitly without history lookup', function (): void {
        $active = applicationRequirement(1, 1, 'Active', 'url', null);
        $inactive = applicationRequirement(2, 1, 'Inactive', 'url', null, AcknowledgementRequirementStatus::Inactive);
        $repository = new ApplicationRequirementRepository([$active, $inactive]);

        $deactivated = (new DeactivateAcknowledgementRequirement($repository))->handle(1, 1);
        $activated = (new ActivateAcknowledgementRequirement($repository))->handle(2, 1);

        assertSameValue('INACTIVE', $deactivated->status);
        assertSameValue('ACTIVE', $activated->status);
        assertSameValue(2, $repository->saveCount);
        assertSameValue(0, $repository->hasAcknowledgementsCount);
        assertThrows(
            static fn () => (new ActivateAcknowledgementRequirement($repository))->handle(999, 1),
            AcknowledgementRequirementNotFound::class,
        );
    });

    $runner->add('E009 Application rejects cross-period Requirement mutations before history lookup or mutation', function (): void {
        $active = applicationRequirement(1, 10, 'Period B', 'original/url', 'REF');
        $inactive = applicationRequirement(2, 10, 'Inactive B', 'inactive/url', null, AcknowledgementRequirementStatus::Inactive);
        $repository = new ApplicationRequirementRepository([$active, $inactive]);

        assertThrows(
            static fn () => applicationUpdateRequirement($repository)->handle(
                new UpdateAcknowledgementRequirementInput(1, 9, 'Tampered', 'tampered/url', null)
            ),
            AcknowledgementRequirementNotFound::class,
        );
        assertThrows(
            static fn () => (new DeactivateAcknowledgementRequirement($repository))->handle(1, 9),
            AcknowledgementRequirementNotFound::class,
        );
        assertThrows(
            static fn () => (new ActivateAcknowledgementRequirement($repository))->handle(2, 9),
            AcknowledgementRequirementNotFound::class,
        );

        assertSameValue(0, $repository->hasAcknowledgementsCount);
        assertSameValue(0, $repository->saveCount);
        assertSameValue('Period B', $active->title()->value());
        assertSameValue('original/url', $active->url()->value());
        assertSameValue('ACTIVE', $active->status()->value);
        assertSameValue('INACTIVE', $inactive->status()->value);
    });

    $runner->add('E009 Representative state projects existing Completion and only current ACTIVE Requirements', function (): void {
        $active = applicationRequirement(1, 5, 'Active', 'url', null);
        $inactive = applicationRequirement(2, 5, 'Inactive', 'url', null, AcknowledgementRequirementStatus::Inactive);
        $requirements = new ApplicationRequirementRepository([$active, $inactive]);
        $completions = new ApplicationCompletionRepository();
        $persisted = $completions->save(applicationNewCompletion(7, 5, [$active]));

        $state = (new GetRepresentativeAcknowledgementState($requirements, $completions))->handle(7, 5);

        assertSameValue(true, $state->satisfied);
        assertSameValue(true, $state->completed);
        assertSameValue($persisted->id()?->value(), $state->completionId);
        assertSameValue('2026-08-14 10:11:12', $state->completedAt?->format('Y-m-d H:i:s'));
        assertSameValue('-05:00', $state->completedAt?->format('P'));
        assertSameValue([1], array_map(static fn ($output): int => $output->id, $state->activeRequirements));
    });

    $runner->add('E009 Representative state distinguishes empty satisfaction from pending ACTIVE set', function (): void {
        $emptyState = (new GetRepresentativeAcknowledgementState(
            new ApplicationRequirementRepository(),
            new ApplicationCompletionRepository(),
        ))->handle(7, 5);
        assertSameValue(true, $emptyState->satisfied);
        assertSameValue(false, $emptyState->completed);
        assertSameValue(null, $emptyState->completionId);

        $active = applicationRequirement(1, 5, 'Active', 'url', null);
        $inactive = applicationRequirement(2, 5, 'Inactive', 'url', null, AcknowledgementRequirementStatus::Inactive);
        $pending = (new GetRepresentativeAcknowledgementState(
            new ApplicationRequirementRepository([$inactive, $active]),
            new ApplicationCompletionRepository(),
        ))->handle(7, 5);
        assertSameValue(false, $pending->satisfied);
        assertSameValue(false, $pending->completed);
        assertSameValue([1], array_map(static fn ($output): int => $output->id, $pending->activeRequirements));
    });

    $runner->add('E009 satisfaction boundary implements the complete ADR-0022 matrix', function (): void {
        $active = applicationRequirement(1, 5, 'Active', 'url', null);
        $newActive = applicationRequirement(3, 5, 'New active', 'new/url', null);
        $inactive = applicationRequirement(2, 5, 'Inactive', 'url', null, AcknowledgementRequirementStatus::Inactive);

        foreach ([
            [[$active], true, true],
            [[], true, true],
            [[$active, $newActive], true, true],
            [[], false, true],
            [[$inactive], false, true],
            [[$active], false, false],
        ] as [$requirementSet, $hasCompletion, $expected]) {
            $requirements = new ApplicationRequirementRepository($requirementSet);
            $completions = new ApplicationCompletionRepository();
            if ($hasCompletion) {
                $completions->save(applicationNewCompletion(7, 5, [$active]));
            }
            $boundary = new CheckInstitutionalAcknowledgementSatisfaction($requirements, $completions);
            assertSameValue(true, $boundary instanceof InstitutionalAcknowledgementSatisfaction);
            assertSameValue($expected, $boundary->isSatisfied(7, 5));
            if ($hasCompletion) {
                assertSameValue(0, $requirements->findByPeriodCount);
            }
        }
    });

    $runner->add('E009 completion creates one atomic persisted set independent of submitted order', function (): void {
        $first = applicationRequirement(10, 5, 'First', 'first/url', null);
        $second = applicationRequirement(20, 5, 'Second', 'second/url', null);
        $requirements = new ApplicationRequirementRepository([$first, $second]);
        $completions = new ApplicationCompletionRepository();
        $transactions = new ApplicationAcknowledgementTransactionRunner($completions);
        $output = (new CompleteRepresentativeAcknowledgements(
            $requirements,
            $completions,
            $transactions,
        ))->handle(new CompleteRepresentativeAcknowledgementsInput(
            7,
            5,
            [20, 10],
            new DateTimeImmutable('2026-08-14 10:11:12.999999-05:00'),
        ));

        assertSameValue(true, $output->satisfied);
        assertSameValue(true, ($output->completionId ?? 0) > 0);
        assertSameValue([10, 20], $output->acknowledgedRequirementIds);
        assertSameValue([10, 20], $requirements->lockedCompletionRequirementIds);
        assertSameValue(['lock:completion:5'], $requirements->operationLog);
        assertSameValue(1, $completions->saveCount);
        assertSameValue(1, $transactions->runCount);
        assertSameValue(1, $transactions->commitCount);
        assertSameValue(0, $transactions->rollbackCount);
        assertSameValue('2026-08-14 10:11:12', $output->completedAt?->format('Y-m-d H:i:s'));
        assertSameValue('-05:00', $output->completedAt?->format('P'));
    });

    $runner->add('E009 completion revalidates the authoritative ACTIVE set under ordered locks', function (): void {
        $staleActive = applicationRequirement(10, 5, 'Stale active', 'stale/url', null);
        $freshInactive = applicationRequirement(
            10,
            5,
            'Stale active',
            'stale/url',
            null,
            AcknowledgementRequirementStatus::Inactive,
        );
        $freshActive = applicationRequirement(20, 5, 'Fresh active', 'fresh/url', null);
        $requirements = new ApplicationRequirementRepository([$staleActive]);
        $requirements->lockedPeriodOverrides[5] = [$freshActive, $freshInactive];
        $completions = new ApplicationCompletionRepository();
        $transactions = new ApplicationAcknowledgementTransactionRunner($completions);

        $output = (new CompleteRepresentativeAcknowledgements(
            $requirements,
            $completions,
            $transactions,
        ))->handle(new CompleteRepresentativeAcknowledgementsInput(7, 5, [20], applicationCompletedAt()));

        assertSameValue([10, 20], $requirements->lockedCompletionRequirementIds);
        assertSameValue([20], $output->acknowledgedRequirementIds);
        assertSameValue(1, $completions->saveCount);
    });

    $runner->add('E009 zero ACTIVE completion returns satisfaction without persistence', function (): void {
        foreach ([[], [applicationRequirement(2, 5, 'Inactive', 'url', null, AcknowledgementRequirementStatus::Inactive)]] as $set) {
            $requirements = new ApplicationRequirementRepository($set);
            $completions = new ApplicationCompletionRepository();
            $transactions = new ApplicationAcknowledgementTransactionRunner($completions);
            $output = (new CompleteRepresentativeAcknowledgements($requirements, $completions, $transactions))->handle(
                new CompleteRepresentativeAcknowledgementsInput(7, 5, [], applicationCompletedAt())
            );
            assertSameValue(true, $output->satisfied);
            assertSameValue(null, $output->completionId);
            assertSameValue(null, $output->completedAt);
            assertSameValue([], $output->acknowledgedRequirementIds);
            assertSameValue(0, $completions->saveCount);
            assertSameValue(1, $transactions->commitCount);
        }
    });

    $runner->add('E009 completion rejects every non-exact or invalid submitted Requirement set', function (): void {
        $active = applicationRequirement(10, 5, 'Active', 'url', null);
        $second = applicationRequirement(20, 5, 'Second', 'url', null);
        $inactive = applicationRequirement(30, 5, 'Inactive', 'url', null, AcknowledgementRequirementStatus::Inactive);
        foreach ([
            [[$active, $second], [10]],
            [[$active], [10, 99]],
            [[$active, $inactive], [10, 30]],
            [[$active], [10, 40]],
            [[$active], [10, 10]],
            [[$active], [0]],
            [[$active], [-1]],
            [[$active], ['10']],
            [[], [10]],
        ] as [$available, $submitted]) {
            $requirements = new ApplicationRequirementRepository($available);
            $completions = new ApplicationCompletionRepository();
            $transactions = new ApplicationAcknowledgementTransactionRunner($completions);
            assertThrows(
                static fn () => (new CompleteRepresentativeAcknowledgements(
                    $requirements,
                    $completions,
                    $transactions,
                ))->handle(new CompleteRepresentativeAcknowledgementsInput(7, 5, $submitted, applicationCompletedAt())),
                InvalidAcknowledgementConfirmation::class,
            );
            assertSameValue(0, $completions->saveCount);
            assertSameValue(1, $transactions->rollbackCount);
        }
    });

    $runner->add('E009 completion rejects existing history before resolving current Requirements', function (): void {
        $active = applicationRequirement(10, 5, 'Active', 'url', null);
        $requirements = new ApplicationRequirementRepository([$active]);
        $completions = new ApplicationCompletionRepository();
        $completions->save(applicationNewCompletion(7, 5, [$active]));
        $completions->saveCount = 0;
        $transactions = new ApplicationAcknowledgementTransactionRunner($completions);

        assertThrows(
            static fn () => (new CompleteRepresentativeAcknowledgements(
                $requirements,
                $completions,
                $transactions,
            ))->handle(new CompleteRepresentativeAcknowledgementsInput(7, 5, [10], applicationCompletedAt())),
            InstitutionalAcknowledgementsAlreadyCompleted::class,
        );
        assertSameValue(0, $requirements->findByPeriodCount);
        assertSameValue([], $requirements->operationLog);
        assertSameValue(0, $completions->saveCount);
        assertSameValue(1, $transactions->rollbackCount);
    });

    $runner->add('E009 completion propagates save failure and transaction restores persistence state', function (): void {
        $active = applicationRequirement(10, 5, 'Active', 'url', null);
        $requirements = new ApplicationRequirementRepository([$active]);
        $completions = new ApplicationCompletionRepository();
        $completions->saveFailure = new RuntimeException('Persistence failure');
        $transactions = new ApplicationAcknowledgementTransactionRunner($completions);

        assertThrows(
            static fn () => (new CompleteRepresentativeAcknowledgements(
                $requirements,
                $completions,
                $transactions,
            ))->handle(new CompleteRepresentativeAcknowledgementsInput(7, 5, [10], applicationCompletedAt())),
            RuntimeException::class,
        );
        assertSameValue(1, $completions->saveCount);
        assertSameValue([], $completions->snapshot());
        assertSameValue(1, $transactions->rollbackCount);
    });

    $runner->add('E009 completion fails closed and rolls back incoherent persisted root matrices', function (): void {
        $active = applicationRequirement(10, 5, 'Active', 'url', null);
        $extra = applicationRequirement(20, 5, 'Extra', 'url', null);
        $cases = [
            static fn (RepresentativeAcknowledgementCompletion $persisted, RepresentativeAcknowledgementCompletion $requested) => $requested,
            static fn (RepresentativeAcknowledgementCompletion $persisted) => applicationPersistedCompletion(8, 5, [$active]),
            static fn (RepresentativeAcknowledgementCompletion $persisted) => applicationPersistedCompletion(
                7,
                6,
                [applicationRequirement(10, 6, 'Active', 'url', null)],
            ),
            static fn (RepresentativeAcknowledgementCompletion $persisted) => applicationPersistedCompletion(
                7,
                5,
                [$active],
                new DateTimeImmutable('2026-08-14 15:11:13+00:00'),
            ),
            static fn (RepresentativeAcknowledgementCompletion $persisted) => applicationPersistedCompletion(7, 5, [$active, $extra]),
        ];

        foreach ($cases as $transformer) {
            $requirements = new ApplicationRequirementRepository([$active]);
            $completions = new ApplicationCompletionRepository();
            $completions->saveResult = $transformer;
            $transactions = new ApplicationAcknowledgementTransactionRunner($completions);
            assertThrows(
                static fn () => (new CompleteRepresentativeAcknowledgements(
                    $requirements,
                    $completions,
                    $transactions,
                ))->handle(new CompleteRepresentativeAcknowledgementsInput(7, 5, [10], applicationCompletedAt())),
                InvalidPersistedAcknowledgementResult::class,
            );
            assertSameValue([], $completions->snapshot());
            assertSameValue(1, $transactions->rollbackCount);
        }
    });

    $runner->add('E009 completion output rejects missing, unpersisted and duplicate logical children', function (): void {
        $active = applicationRequirement(10, 5, 'Active', 'url', null);
        $missing = applicationPersistedCompletion(7, 5, []);
        assertThrows(
            static fn () => RepresentativeAcknowledgementCompletionOutput::fromCompletion(
                $missing,
                new RepresentativeId(7),
                new AcademicPeriodId(5),
                applicationCompletedAt(),
                [10],
            ),
            InvalidPersistedAcknowledgementResult::class,
        );

        foreach ([
            applicationRawCompletion(7, 5, [applicationRawAcknowledgement(null, 10)]),
            applicationRawCompletion(7, 5, [
                applicationRawAcknowledgement(1, 10),
                applicationRawAcknowledgement(2, 10),
            ]),
        ] as $corrupt) {
            assertThrows(
                static fn () => RepresentativeAcknowledgementCompletionOutput::fromCompletion(
                    $corrupt,
                    new RepresentativeId(7),
                    new AcademicPeriodId(5),
                ),
                InvalidPersistedAcknowledgementResult::class,
            );
        }
    });

    $runner->add('E009 Application remains isolated from forbidden layers and future phases', function (): void {
        $files = applicationPhpFiles(__DIR__ . '/../app/InstitutionalDocuments/Application');
        assertSameValue(29, count($files));
        $source = implode("\n", array_map(static fn (string $file): string => (string) file_get_contents($file), $files));
        foreach ([
            'PDO', 'ConnectionManager', 'Request', 'Response', 'SessionManager', 'Controller',
            'Router', 'Middleware', 'htmlspecialchars', 'CSRF', 'Infrastructure\\',
            'FileStorage', 'PDF', 'App\\Enrollment', 'App\\Student', 'App\\Family',
        ] as $forbidden) {
            assertSameValue(false, str_contains($source, $forbidden));
        }
        assertSameValue(false, file_exists(__DIR__ . '/../app/InstitutionalDocuments/Delivery'));
        assertSameValue(true, str_contains((string) file_get_contents(__DIR__ . '/../bootstrap/app.php'), 'InstitutionalAcknowledgementController'));
    });
}

function applicationRequirement(
    int $id,
    int $academicPeriodId,
    string $title,
    string $url,
    ?string $officialReference,
    AcknowledgementRequirementStatus $status = AcknowledgementRequirementStatus::Active,
): AcknowledgementRequirement {
    return AcknowledgementRequirement::reconstitute(
        new AcknowledgementRequirementId($id),
        new AcademicPeriodId($academicPeriodId),
        new AcknowledgementRequirementTitle($title),
        new AcknowledgementRequirementUrl($url),
        $officialReference === null ? null : new AcknowledgementOfficialReference($officialReference),
        $status,
    );
}

function applicationUpdateRequirement(
    ApplicationRequirementRepository $repository,
): UpdateAcknowledgementRequirement {
    return new UpdateAcknowledgementRequirement(
        $repository,
        new InMemoryCompositeTransactionRunner([$repository]),
    );
}

/** @param list<AcknowledgementRequirement> $requirements */
function applicationNewCompletion(
    int $representativeId,
    int $academicPeriodId,
    array $requirements,
): RepresentativeAcknowledgementCompletion {
    return RepresentativeAcknowledgementCompletion::complete(
        new RepresentativeId($representativeId),
        new AcademicPeriodId($academicPeriodId),
        applicationCompletedAt(),
        $requirements,
    );
}

function applicationCompletedAt(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-08-14 10:11:12.999999-05:00');
}

/** @param list<AcknowledgementRequirement> $requirements */
function applicationPersistedCompletion(
    int $representativeId,
    int $academicPeriodId,
    array $requirements,
    ?DateTimeImmutable $completedAt = null,
): RepresentativeAcknowledgementCompletion {
    if ($requirements === []) {
        return applicationRawCompletion($representativeId, $academicPeriodId, []);
    }

    return applicationPersistedFromNew(
        RepresentativeAcknowledgementCompletion::complete(
            new RepresentativeId($representativeId),
            new AcademicPeriodId($academicPeriodId),
            $completedAt ?? applicationCompletedAt(),
            $requirements,
        ),
        100,
        500,
    );
}

function applicationPersistedFromNew(
    RepresentativeAcknowledgementCompletion $completion,
    int $completionId,
    int $firstAcknowledgementId,
): RepresentativeAcknowledgementCompletion {
    $children = [];
    foreach ($completion->acknowledgements() as $index => $acknowledgement) {
        $children[] = RepresentativeAcknowledgement::reconstitute(
            new RepresentativeAcknowledgementId($firstAcknowledgementId + $index),
            $acknowledgement->acknowledgementRequirementId(),
        );
    }

    return RepresentativeAcknowledgementCompletion::reconstitute(
        new RepresentativeAcknowledgementCompletionId($completionId),
        $completion->representativeId(),
        $completion->academicPeriodId(),
        $completion->completedAt(),
        $children,
    );
}

function applicationRawAcknowledgement(?int $id, int $requirementId): RepresentativeAcknowledgement
{
    $reflection = new ReflectionClass(RepresentativeAcknowledgement::class);
    $acknowledgement = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('id')->setValue(
        $acknowledgement,
        $id === null ? null : new RepresentativeAcknowledgementId($id),
    );
    $reflection->getProperty('acknowledgementRequirementId')->setValue(
        $acknowledgement,
        new AcknowledgementRequirementId($requirementId),
    );

    return $acknowledgement;
}

/** @param list<RepresentativeAcknowledgement> $children */
function applicationRawCompletion(
    int $representativeId,
    int $academicPeriodId,
    array $children,
): RepresentativeAcknowledgementCompletion {
    $reflection = new ReflectionClass(RepresentativeAcknowledgementCompletion::class);
    $completion = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('id')->setValue($completion, new RepresentativeAcknowledgementCompletionId(100));
    $reflection->getProperty('representativeId')->setValue($completion, new RepresentativeId($representativeId));
    $reflection->getProperty('academicPeriodId')->setValue($completion, new AcademicPeriodId($academicPeriodId));
    $reflection->getProperty('completedAt')->setValue($completion, applicationCompletedAt());
    $reflection->getProperty('acknowledgements')->setValue($completion, $children);

    return $completion;
}

/** @return list<string> */
function applicationPhpFiles(string $path): array
{
    $files = [];
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
    sort($files, SORT_STRING);

    return $files;
}
