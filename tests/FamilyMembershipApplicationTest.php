<?php

declare(strict_types=1);

namespace Tests;

use App\Family\Application\AddRepresentativeToFamily;
use App\Family\Application\AddStudentToFamily;
use App\Family\Application\CreateFamily;
use App\Family\Application\Dto\AddRepresentativeToFamilyInput;
use App\Family\Application\Dto\AddStudentToFamilyInput;
use App\Family\Application\Dto\CreateFamilyInput;
use App\Family\Application\Dto\EndRepresentativeMembershipInput;
use App\Family\Application\Dto\EndStudentMembershipInput;
use App\Family\Application\Dto\FamilyOutput;
use App\Family\Application\Dto\FamilyRepresentativeOutput;
use App\Family\Application\Dto\FamilyStudentOutput;
use App\Family\Application\EndRepresentativeMembership;
use App\Family\Application\EndStudentMembership;
use App\Family\Application\Exception\FamilyNotFound;
use App\Family\Application\Exception\InvalidPersistedFamilyResult;
use App\Family\Application\Exception\RelationshipTypeNotFound;
use App\Family\Application\Exception\RepresentativeNotFoundForFamily;
use App\Family\Application\Exception\StudentAlreadyHasActiveFamily;
use App\Family\Application\Exception\StudentNotFoundForFamily;
use App\Family\Application\GetFamily;
use App\Family\Application\GetFamilyMembership;
use App\Family\Application\RelationshipTypeLookup;
use App\Family\Domain\Exception\InvalidFamilyState;
use App\Family\Domain\Family;
use App\Family\Domain\FamilyRepresentative;
use App\Family\Domain\FamilyRepository;
use App\Family\Domain\FamilyStatus;
use App\Family\Domain\FamilyStudent;
use App\Family\Domain\ValueObject\DisplayName;
use App\Family\Domain\ValueObject\FamilyId;
use App\Family\Domain\ValueObject\FamilyRepresentativeId;
use App\Family\Domain\ValueObject\FamilyStudentId;
use App\Family\Domain\ValueObject\RelationshipTypeId;
use App\Family\Domain\ValueObject\RepresentativeId as FamilyRepresentativeReference;
use App\Family\Domain\ValueObject\StudentId as FamilyStudentReference;
use App\Representative\Domain\Representative;
use App\Representative\Domain\RepresentativeRepository;
use App\Representative\Domain\RepresentativeStatus;
use App\Representative\Domain\ValueObject\PersonId as RepresentativePersonId;
use App\Representative\Domain\ValueObject\RepresentativeId;
use App\Student\Domain\Student;
use App\Student\Domain\StudentRepository;
use App\Student\Domain\StudentStatus;
use App\Student\Domain\ValueObject\AdmissionDate;
use App\Student\Domain\ValueObject\InstitutionalCode;
use App\Student\Domain\ValueObject\PersonId as StudentPersonId;
use App\Student\Domain\ValueObject\StudentId;
use DateTimeImmutable;
use DateTimeZone;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use Tests\Support\TestRunner;

function registerFamilyMembershipApplicationTests(TestRunner $runner): void
{
    $runner->add('CreateFamily persists an ACTIVE Family with normalized complete output', function (): void {
        $families = new InMemoryFamilyApplicationRepository();
        $representatives = familyRepresentativeRepository(10);
        $startedAt = new DateTimeImmutable(
            '2026-08-01 10:11:12.987654',
            new DateTimeZone('America/Guayaquil'),
        );

        $output = (new CreateFamily(
            $families,
            $representatives,
            familyRelationshipTypes(),
        ))->handle(new CreateFamilyInput(
            '  Example Family  ',
            FamilyStatus::Active,
            10,
            1,
            $startedAt,
        ));

        assertSameValue(true, $output->id > 0);
        assertSameValue('Example Family', $output->displayName);
        assertSameValue(FamilyStatus::Active, $output->status);
        assertSameValue(1, count($output->representatives));
        assertSameValue(0, count($output->students));
        $primary = $output->representatives[0];
        assertSameValue(true, $primary->id > 0);
        assertSameValue(10, $primary->representativeId);
        assertSameValue(1, $primary->relationshipTypeId);
        assertSameValue(true, $primary->isPrimary);
        assertSameValue(true, $primary->isActive);
        assertSameValue(null, $primary->endedAt);
        assertSameValue('2026-08-01 10:11:12.000000', $primary->startedAt->format('Y-m-d H:i:s.u'));
        assertSameValue('America/Guayaquil', $primary->startedAt->getTimezone()->getName());
        assertSameValue(1, $families->saveCalls());
        assertSameValue(true, (new ReflectionClass($output))->isReadOnly());
        assertSameValue(true, (new ReflectionClass($primary))->isReadOnly());
    });

    $runner->add('CreateFamily preserves the explicitly requested INACTIVE status', function (): void {
        $families = new InMemoryFamilyApplicationRepository();
        $output = (new CreateFamily(
            $families,
            familyRepresentativeRepository(11),
            familyRelationshipTypes(),
        ))->handle(familyCreateInput(11, FamilyStatus::Inactive));

        assertSameValue(FamilyStatus::Inactive, $output->status);
        assertSameValue(true, $output->representatives[0]->isPrimary);
        assertSameValue(true, $output->representatives[0]->isActive);
    });

    $runner->add('CreateFamily rejects a missing initial Representative without saving', function (): void {
        $families = new InMemoryFamilyApplicationRepository();

        assertThrows(
            static fn () => (new CreateFamily(
                $families,
                new InMemoryRepresentativeApplicationRepository(),
                familyRelationshipTypes(),
            ))->handle(familyCreateInput(99)),
            RepresentativeNotFoundForFamily::class,
        );
        assertSameValue(0, $families->saveCalls());
    });

    $runner->add('CreateFamily rejects a missing RelationshipType without saving', function (): void {
        $families = new InMemoryFamilyApplicationRepository();

        assertThrows(
            static fn () => (new CreateFamily(
                $families,
                familyRepresentativeRepository(12),
                new FakeRelationshipTypeLookup([]),
            ))->handle(familyCreateInput(12)),
            RelationshipTypeNotFound::class,
        );
        assertSameValue(0, $families->saveCalls());
    });

    $runner->add('CreateFamily propagates DisplayName invariants before saving', function (): void {
        $families = new InMemoryFamilyApplicationRepository();
        $input = new CreateFamilyInput(
            ' ',
            FamilyStatus::Active,
            13,
            1,
            familyApplicationDate('2026-08-01 09:00:00'),
        );

        assertThrows(
            static fn () => (new CreateFamily(
                $families,
                familyRepresentativeRepository(13),
                familyRelationshipTypes(),
            ))->handle($input),
            InvalidFamilyState::class,
        );
        assertSameValue(0, $families->saveCalls());
    });

    $runner->add('CreateFamily rejects a persisted result without Family identity', function (): void {
        $families = new InMemoryFamilyApplicationRepository();
        $families->returnWithoutFamilyId();

        assertThrows(
            static fn () => (new CreateFamily(
                $families,
                familyRepresentativeRepository(14),
                familyRelationshipTypes(),
            ))->handle(familyCreateInput(14)),
            InvalidPersistedFamilyResult::class,
        );
        assertSameValue(1, $families->saveCalls());
    });

    $runner->add('CreateFamily rejects a persisted principal membership without identity', function (): void {
        $families = new InMemoryFamilyApplicationRepository();
        $families->returnWithoutPrimaryMembershipId();

        assertThrows(
            static fn () => (new CreateFamily(
                $families,
                familyRepresentativeRepository(15),
                familyRelationshipTypes(),
            ))->handle(familyCreateInput(15)),
            InvalidPersistedFamilyResult::class,
        );
        assertSameValue(1, $families->saveCalls());
    });

    $runner->add('GetFamily returns all active and historical memberships without Domain Entities', function (): void {
        $families = new InMemoryFamilyApplicationRepository();
        $families->seed(familyCompleteApplicationAggregate(20, FamilyStatus::Inactive));

        $output = (new GetFamily($families))->handle(20);

        assertSameValue(20, $output->id);
        assertSameValue(FamilyStatus::Inactive, $output->status);
        assertSameValue(3, count($output->representatives));
        assertSameValue(2, count($output->students));
        $primary = array_values(array_filter(
            $output->representatives,
            static fn (FamilyRepresentativeOutput $membership): bool =>
                $membership->isPrimary && $membership->isActive,
        ));
        assertSameValue(1, count($primary));
        assertSameValue(10, $primary[0]->representativeId);
        assertSameValue(false, $output->representatives[2]->isActive);
        assertSameValue('2026-07-10 09:00:00', $output->representatives[2]->endedAt?->format('Y-m-d H:i:s'));
        assertSameValue(false, $output->students[1]->isActive);
        assertSameValue('2026-07-11 09:00:00', $output->students[1]->endedAt?->format('Y-m-d H:i:s'));
        assertSameValue(true, $output->representatives[0] instanceof FamilyRepresentativeOutput);
        assertSameValue(true, $output->students[0] instanceof FamilyStudentOutput);
        assertSameValue(false, $output->representatives[0] instanceof FamilyRepresentative);
        assertSameValue(false, $output->students[0] instanceof FamilyStudent);
        assertSameValue(0, $families->saveCalls());
    });

    $runner->add('GetFamily and GetFamilyMembership throw FamilyNotFound when absent', function (): void {
        $families = new InMemoryFamilyApplicationRepository();

        assertThrows(static fn () => (new GetFamily($families))->handle(999), FamilyNotFound::class);
        assertThrows(
            static fn () => (new GetFamilyMembership($families))->handle(999),
            FamilyNotFound::class,
        );
    });

    $runner->add('GetFamilyMembership reuses the complete FamilyOutput representation', function (): void {
        $families = new InMemoryFamilyApplicationRepository();
        $families->seed(familyCompleteApplicationAggregate(21));

        $family = (new GetFamily($families))->handle(21);
        $membership = (new GetFamilyMembership($families))->handle(21);

        assertSameValue(true, $family instanceof FamilyOutput);
        assertSameValue(true, $membership instanceof FamilyOutput);
        assertSameValue(familyOutputSnapshot($family), familyOutputSnapshot($membership));
    });

    $runner->add('AddRepresentativeToFamily persists a non-primary membership and preserves history', function (): void {
        $families = new InMemoryFamilyApplicationRepository();
        $families->seed(familyCompleteApplicationAggregate(30));
        $output = (new AddRepresentativeToFamily(
            $families,
            familyRepresentativeRepository(13),
            familyRelationshipTypes(),
        ))->handle(new AddRepresentativeToFamilyInput(
            30,
            13,
            2,
            familyApplicationDate('2026-08-05 10:11:12.456789'),
        ));

        $added = familyRepresentativeOutput($output, 13, true);
        assertSameValue(true, $added->id > 0);
        assertSameValue(false, $added->isPrimary);
        assertSameValue('2026-08-05 10:11:12.000000', $added->startedAt->format('Y-m-d H:i:s.u'));
        assertSameValue(10, familyPrimaryOutput($output)->representativeId);
        assertSameValue(false, familyRepresentativeOutput($output, 12, false)->isActive);
        assertSameValue(1, $families->saveCalls());
    });

    $runner->add('AddRepresentativeToFamily rejects a missing Family before external validation', function (): void {
        $families = new InMemoryFamilyApplicationRepository();

        assertThrows(
            static fn () => (new AddRepresentativeToFamily(
                $families,
                familyRepresentativeRepository(13),
                familyRelationshipTypes(),
            ))->handle(familyAddRepresentativeInput(999, 13)),
            FamilyNotFound::class,
        );
        assertSameValue(0, $families->saveCalls());
    });

    $runner->add('AddRepresentativeToFamily rejects a missing Representative without saving', function (): void {
        $families = familySeededRepository(familyBaseApplicationAggregate(31));

        assertThrows(
            static fn () => (new AddRepresentativeToFamily(
                $families,
                new InMemoryRepresentativeApplicationRepository(),
                familyRelationshipTypes(),
            ))->handle(familyAddRepresentativeInput(31, 99)),
            RepresentativeNotFoundForFamily::class,
        );
        assertSameValue(0, $families->saveCalls());
    });

    $runner->add('AddRepresentativeToFamily rejects a missing RelationshipType without saving', function (): void {
        $families = familySeededRepository(familyBaseApplicationAggregate(32));

        assertThrows(
            static fn () => (new AddRepresentativeToFamily(
                $families,
                familyRepresentativeRepository(13),
                new FakeRelationshipTypeLookup([]),
            ))->handle(familyAddRepresentativeInput(32, 13)),
            RelationshipTypeNotFound::class,
        );
        assertSameValue(0, $families->saveCalls());
    });

    $runner->add('AddRepresentativeToFamily permits a Representative active in another Family', function (): void {
        $families = new InMemoryFamilyApplicationRepository();
        $families->seed(familyBaseApplicationAggregate(33));
        $families->seed(familyApplicationAggregate(
            34,
            FamilyStatus::Active,
            [familyApplicationRepresentativeMembership(340, 13, 1, true, '2026-08-01 09:00:00')],
        ));

        $output = (new AddRepresentativeToFamily(
            $families,
            familyRepresentativeRepository(13),
            familyRelationshipTypes(),
        ))->handle(familyAddRepresentativeInput(33, 13));

        assertSameValue(13, familyRepresentativeOutput($output, 13, true)->representativeId);
        assertSameValue(2, count($families->findActiveByRepresentativeId(
            new FamilyRepresentativeReference(13)
        )));
    });

    $runner->add('AddRepresentativeToFamily delegates active duplication to Family Domain', function (): void {
        $families = familySeededRepository(familyCompleteApplicationAggregate(35));

        assertThrows(
            static fn () => (new AddRepresentativeToFamily(
                $families,
                familyRepresentativeRepository(11),
                familyRelationshipTypes(),
            ))->handle(familyAddRepresentativeInput(35, 11)),
            InvalidFamilyState::class,
        );
        assertSameValue(0, $families->saveCalls());
    });

    $runner->add('AddRepresentativeToFamily rejects a result without the new membership identity', function (): void {
        $families = familySeededRepository(familyBaseApplicationAggregate(36));
        $families->returnWithoutNewRepresentativeMembershipId();

        assertThrows(
            static fn () => (new AddRepresentativeToFamily(
                $families,
                familyRepresentativeRepository(13),
                familyRelationshipTypes(),
            ))->handle(familyAddRepresentativeInput(36, 13)),
            InvalidPersistedFamilyResult::class,
        );
    });

    $runner->add('AddStudentToFamily persists a Student with no active Family', function (): void {
        $families = familySeededRepository(familyBaseApplicationAggregate(40));
        $output = (new AddStudentToFamily(
            $families,
            familyStudentRepository(20),
        ))->handle(familyAddStudentInput(40, 20));

        $added = familyStudentOutput($output, 20, true);
        assertSameValue(true, $added->id > 0);
        assertSameValue(true, $added->isActive);
        assertSameValue(1, $families->saveCalls());
    });

    $runner->add('AddStudentToFamily rejects a missing Family without saving', function (): void {
        $families = new InMemoryFamilyApplicationRepository();

        assertThrows(
            static fn () => (new AddStudentToFamily(
                $families,
                familyStudentRepository(20),
            ))->handle(familyAddStudentInput(999, 20)),
            FamilyNotFound::class,
        );
        assertSameValue(0, $families->saveCalls());
    });

    $runner->add('AddStudentToFamily rejects a missing Student without saving', function (): void {
        $families = familySeededRepository(familyBaseApplicationAggregate(41));

        assertThrows(
            static fn () => (new AddStudentToFamily(
                $families,
                new InMemoryStudentApplicationRepository(),
            ))->handle(familyAddStudentInput(41, 99)),
            StudentNotFoundForFamily::class,
        );
        assertSameValue(0, $families->saveCalls());
    });

    $runner->add('AddStudentToFamily rejects a Student active in another Family', function (): void {
        $families = new InMemoryFamilyApplicationRepository();
        $families->seed(familyBaseApplicationAggregate(42));
        $families->seed(familyApplicationAggregate(
            43,
            FamilyStatus::Active,
            [familyApplicationRepresentativeMembership(430, 14, 1, true, '2026-08-01 09:00:00')],
            [familyApplicationStudentMembership(431, 20, '2026-08-02 09:00:00')],
        ));

        assertThrows(
            static fn () => (new AddStudentToFamily(
                $families,
                familyStudentRepository(20),
            ))->handle(familyAddStudentInput(42, 20)),
            StudentAlreadyHasActiveFamily::class,
        );
        assertSameValue(0, $families->saveCalls());
    });

    $runner->add('AddStudentToFamily consistently rejects a Student already active in the same Family', function (): void {
        $families = familySeededRepository(familyApplicationAggregate(
            44,
            FamilyStatus::Active,
            [familyApplicationRepresentativeMembership(440, 10, 1, true, '2026-08-01 09:00:00')],
            [familyApplicationStudentMembership(441, 20, '2026-08-02 09:00:00')],
        ));

        assertThrows(
            static fn () => (new AddStudentToFamily(
                $families,
                familyStudentRepository(20),
            ))->handle(familyAddStudentInput(44, 20)),
            StudentAlreadyHasActiveFamily::class,
        );
        assertSameValue(0, $families->saveCalls());
    });

    $runner->add('AddStudentToFamily permits a new cycle after historical membership ended', function (): void {
        $families = familySeededRepository(familyApplicationAggregate(
            45,
            FamilyStatus::Active,
            [familyApplicationRepresentativeMembership(450, 10, 1, true, '2026-08-01 09:00:00')],
            [familyApplicationStudentMembership(
                451,
                20,
                '2026-07-01 09:00:00',
                '2026-07-10 09:00:00',
            )],
        ));

        $output = (new AddStudentToFamily(
            $families,
            familyStudentRepository(20),
        ))->handle(familyAddStudentInput(45, 20));

        $cycles = array_values(array_filter(
            $output->students,
            static fn (FamilyStudentOutput $membership): bool => $membership->studentId === 20,
        ));
        assertSameValue(2, count($cycles));
        assertSameValue(false, $cycles[0]->isActive);
        assertSameValue(true, $cycles[1]->isActive);
        assertSameValue(true, $cycles[1]->id > 0);
    });

    $runner->add('AddStudentToFamily rejects a result without the new membership identity', function (): void {
        $families = familySeededRepository(familyBaseApplicationAggregate(46));
        $families->returnWithoutNewStudentMembershipId();

        assertThrows(
            static fn () => (new AddStudentToFamily(
                $families,
                familyStudentRepository(20),
            ))->handle(familyAddStudentInput(46, 20)),
            InvalidPersistedFamilyResult::class,
        );
    });

    $runner->add('EndRepresentativeMembership ends a non-primary membership and preserves history', function (): void {
        $families = familySeededRepository(familyCompleteApplicationAggregate(50));
        $output = (new EndRepresentativeMembership($families))->handle(
            new EndRepresentativeMembershipInput(
                50,
                11,
                familyApplicationDate('2026-08-06 12:13:14.987654'),
            ),
        );

        $ended = familyRepresentativeOutput($output, 11, false);
        assertSameValue(false, $ended->isActive);
        assertSameValue('2026-08-06 12:13:14.000000', $ended->endedAt?->format('Y-m-d H:i:s.u'));
        assertSameValue(10, familyPrimaryOutput($output)->representativeId);
        assertSameValue(3, count($output->representatives));
        assertSameValue(1, $families->saveCalls());
    });

    $runner->add('EndRepresentativeMembership throws FamilyNotFound for an absent Family', function (): void {
        assertThrows(
            static fn () => (new EndRepresentativeMembership(
                new InMemoryFamilyApplicationRepository()
            ))->handle(new EndRepresentativeMembershipInput(
                999,
                11,
                familyApplicationDate('2026-08-06 09:00:00'),
            )),
            FamilyNotFound::class,
        );
    });

    $runner->add('EndRepresentativeMembership delegates membership and primary invariants to Domain', function (): void {
        $missing = familySeededRepository(familyCompleteApplicationAggregate(51));
        assertThrows(
            static fn () => (new EndRepresentativeMembership($missing))->handle(
                familyEndRepresentativeInput(51, 99),
            ),
            InvalidFamilyState::class,
        );
        assertSameValue(0, $missing->saveCalls());

        $last = familySeededRepository(familyBaseApplicationAggregate(52));
        assertThrows(
            static fn () => (new EndRepresentativeMembership($last))->handle(
                familyEndRepresentativeInput(52, 10),
            ),
            InvalidFamilyState::class,
        );
        assertSameValue(0, $last->saveCalls());

        $primary = familySeededRepository(familyCompleteApplicationAggregate(53));
        assertThrows(
            static fn () => (new EndRepresentativeMembership($primary))->handle(
                familyEndRepresentativeInput(53, 10),
            ),
            InvalidFamilyState::class,
        );
        assertSameValue(0, $primary->saveCalls());
    });

    $runner->add('EndRepresentativeMembership rejects a date before membership start', function (): void {
        $families = familySeededRepository(familyCompleteApplicationAggregate(54));

        assertThrows(
            static fn () => (new EndRepresentativeMembership($families))->handle(
                new EndRepresentativeMembershipInput(
                    54,
                    11,
                    familyApplicationDate('2026-08-01 08:00:00'),
                ),
            ),
            InvalidFamilyState::class,
        );
        assertSameValue(0, $families->saveCalls());
    });

    $runner->add('EndStudentMembership ends membership and clears active lookup', function (): void {
        $families = familySeededRepository(familyApplicationAggregate(
            60,
            FamilyStatus::Active,
            [familyApplicationRepresentativeMembership(600, 10, 1, true, '2026-08-01 09:00:00')],
            [familyApplicationStudentMembership(601, 20, '2026-08-02 09:00:00')],
        ));
        $output = (new EndStudentMembership($families))->handle(
            new EndStudentMembershipInput(
                60,
                20,
                familyApplicationDate('2026-08-07 12:13:14.654321'),
            ),
        );

        $ended = familyStudentOutput($output, 20, false);
        assertSameValue(false, $ended->isActive);
        assertSameValue('2026-08-07 12:13:14.000000', $ended->endedAt?->format('Y-m-d H:i:s.u'));
        assertSameValue(null, $families->findActiveByStudentId(new FamilyStudentReference(20)));
        assertSameValue(1, $families->saveCalls());
    });

    $runner->add('EndStudentMembership throws FamilyNotFound for an absent Family', function (): void {
        assertThrows(
            static fn () => (new EndStudentMembership(
                new InMemoryFamilyApplicationRepository()
            ))->handle(new EndStudentMembershipInput(
                999,
                20,
                familyApplicationDate('2026-08-07 09:00:00'),
            )),
            FamilyNotFound::class,
        );
    });

    $runner->add('EndStudentMembership delegates absence dates and repeated termination to Domain', function (): void {
        $missing = familySeededRepository(familyBaseApplicationAggregate(61));
        assertThrows(
            static fn () => (new EndStudentMembership($missing))->handle(
                familyEndStudentInput(61, 99),
            ),
            InvalidFamilyState::class,
        );
        assertSameValue(0, $missing->saveCalls());

        $invalidDate = familySeededRepository(familyApplicationAggregate(
            62,
            FamilyStatus::Active,
            [familyApplicationRepresentativeMembership(620, 10, 1, true, '2026-08-01 09:00:00')],
            [familyApplicationStudentMembership(621, 20, '2026-08-03 09:00:00')],
        ));
        assertThrows(
            static fn () => (new EndStudentMembership($invalidDate))->handle(
                new EndStudentMembershipInput(
                    62,
                    20,
                    familyApplicationDate('2026-08-02 09:00:00'),
                ),
            ),
            InvalidFamilyState::class,
        );
        assertSameValue(0, $invalidDate->saveCalls());

        $twice = familySeededRepository(familyApplicationAggregate(
            63,
            FamilyStatus::Active,
            [familyApplicationRepresentativeMembership(630, 10, 1, true, '2026-08-01 09:00:00')],
            [familyApplicationStudentMembership(631, 20, '2026-08-02 09:00:00')],
        ));
        $useCase = new EndStudentMembership($twice);
        $useCase->handle(familyEndStudentInput(63, 20));
        assertThrows(
            static fn () => $useCase->handle(familyEndStudentInput(63, 20)),
            InvalidFamilyState::class,
        );
        assertSameValue(1, $twice->saveCalls());
    });

    $runner->add('Family Application uses only approved ports and contains no transactions or delivery', function (): void {
        $create = (new ReflectionClass(CreateFamily::class))->getConstructor()?->getParameters();
        assertSameValue(FamilyRepository::class, $create[0]->getType()?->getName());
        assertSameValue(RepresentativeRepository::class, $create[1]->getType()?->getName());
        assertSameValue(RelationshipTypeLookup::class, $create[2]->getType()?->getName());

        $addRepresentative = (new ReflectionClass(
            AddRepresentativeToFamily::class
        ))->getConstructor()?->getParameters();
        assertSameValue(FamilyRepository::class, $addRepresentative[0]->getType()?->getName());
        assertSameValue(RepresentativeRepository::class, $addRepresentative[1]->getType()?->getName());
        assertSameValue(RelationshipTypeLookup::class, $addRepresentative[2]->getType()?->getName());

        $addStudent = (new ReflectionClass(AddStudentToFamily::class))->getConstructor()?->getParameters();
        assertSameValue(FamilyRepository::class, $addStudent[0]->getType()?->getName());
        assertSameValue(StudentRepository::class, $addStudent[1]->getType()?->getName());

        foreach ([
            GetFamily::class,
            GetFamilyMembership::class,
            EndRepresentativeMembership::class,
            EndStudentMembership::class,
        ] as $useCase) {
            $parameter = (new ReflectionClass($useCase))->getConstructor()?->getParameters()[0] ?? null;
            assertSameValue(FamilyRepository::class, $parameter?->getType()?->getName());
        }

        $lookupMethod = new ReflectionMethod(RelationshipTypeLookup::class, 'exists');
        assertSameValue(1, count((new ReflectionClass(RelationshipTypeLookup::class))->getMethods()));
        assertSameValue('int', $lookupMethod->getParameters()[0]->getType()?->getName());
        assertSameValue('bool', $lookupMethod->getReturnType()?->getName());

        $source = familyApplicationSource();
        foreach ([
            'PDO',
            '\\Infrastructure\\',
            '\\Http\\',
            'Controller',
            'Session',
            '\\Views\\',
            'App\\Person\\',
            'TransactionManager',
            'UnitOfWork',
            'RepresentativeStudent',
            'CatalogRepository',
            'BaseCatalogRepository',
            'beginTransaction',
            'commit(',
            'rollBack',
        ] as $forbidden) {
            assertSameValue(false, str_contains($source, $forbidden));
        }

        foreach ([
            CreateFamily::class,
            AddRepresentativeToFamily::class,
            AddStudentToFamily::class,
            EndRepresentativeMembership::class,
            EndStudentMembership::class,
        ] as $writeUseCase) {
            $file = (new ReflectionClass($writeUseCase))->getFileName();
            assertSameValue(true, is_string($file));
            assertSameValue(1, substr_count((string) file_get_contents($file), '->save('));
        }
    });
}

function familyCreateInput(
    int $representativeId,
    FamilyStatus $status = FamilyStatus::Active,
): CreateFamilyInput {
    return new CreateFamilyInput(
        'Application Family',
        $status,
        $representativeId,
        1,
        familyApplicationDate('2026-08-01 09:00:00'),
    );
}

function familyAddRepresentativeInput(int $familyId, int $representativeId): AddRepresentativeToFamilyInput
{
    return new AddRepresentativeToFamilyInput(
        $familyId,
        $representativeId,
        1,
        familyApplicationDate('2026-08-05 09:00:00'),
    );
}

function familyAddStudentInput(int $familyId, int $studentId): AddStudentToFamilyInput
{
    return new AddStudentToFamilyInput(
        $familyId,
        $studentId,
        familyApplicationDate('2026-08-05 09:00:00'),
    );
}

function familyEndRepresentativeInput(int $familyId, int $representativeId): EndRepresentativeMembershipInput
{
    return new EndRepresentativeMembershipInput(
        $familyId,
        $representativeId,
        familyApplicationDate('2026-08-06 09:00:00'),
    );
}

function familyEndStudentInput(int $familyId, int $studentId): EndStudentMembershipInput
{
    return new EndStudentMembershipInput(
        $familyId,
        $studentId,
        familyApplicationDate('2026-08-07 09:00:00'),
    );
}

function familyApplicationDate(string $value): DateTimeImmutable
{
    return new DateTimeImmutable($value, new DateTimeZone('UTC'));
}

function familyRelationshipTypes(): FakeRelationshipTypeLookup
{
    return new FakeRelationshipTypeLookup([1, 2, 3]);
}

function familyRepresentativeRepository(int ...$representativeIds): InMemoryRepresentativeApplicationRepository
{
    $repository = new InMemoryRepresentativeApplicationRepository();
    foreach ($representativeIds as $representativeId) {
        $repository->seed(new Representative(
            new RepresentativeId($representativeId),
            new RepresentativePersonId($representativeId + 1000),
            null,
            RepresentativeStatus::Active,
        ));
    }

    return $repository;
}

function familyStudentRepository(int ...$studentIds): InMemoryStudentApplicationRepository
{
    $repository = new InMemoryStudentApplicationRepository();
    foreach ($studentIds as $studentId) {
        $repository->seed(new Student(
            new StudentId($studentId),
            new StudentPersonId($studentId + 2000),
            new InstitutionalCode('FAMILY-STUDENT-' . $studentId),
            new AdmissionDate(
                familyApplicationDate('2020-01-01 00:00:00'),
                familyApplicationDate('2026-08-04 00:00:00'),
            ),
            StudentStatus::Active,
        ));
    }

    return $repository;
}

function familySeededRepository(Family $family): InMemoryFamilyApplicationRepository
{
    $repository = new InMemoryFamilyApplicationRepository();
    $repository->seed($family);

    return $repository;
}

function familyBaseApplicationAggregate(
    int $familyId,
    FamilyStatus $status = FamilyStatus::Active,
): Family {
    return familyApplicationAggregate(
        $familyId,
        $status,
        [familyApplicationRepresentativeMembership(100, 10, 1, true, '2026-08-01 09:00:00')],
    );
}

function familyCompleteApplicationAggregate(
    int $familyId,
    FamilyStatus $status = FamilyStatus::Active,
): Family {
    return familyApplicationAggregate(
        $familyId,
        $status,
        [
            familyApplicationRepresentativeMembership(101, 10, 1, true, '2026-08-01 09:00:00'),
            familyApplicationRepresentativeMembership(102, 11, 2, false, '2026-08-02 09:00:00'),
            familyApplicationRepresentativeMembership(
                103,
                12,
                2,
                false,
                '2026-07-01 09:00:00',
                '2026-07-10 09:00:00',
            ),
        ],
        [
            familyApplicationStudentMembership(201, 20, '2026-08-03 09:00:00'),
            familyApplicationStudentMembership(
                202,
                21,
                '2026-07-02 09:00:00',
                '2026-07-11 09:00:00',
            ),
        ],
    );
}

/**
 * @param list<FamilyRepresentative> $representatives
 * @param list<FamilyStudent> $students
 */
function familyApplicationAggregate(
    int $familyId,
    FamilyStatus $status,
    array $representatives,
    array $students = [],
): Family {
    return Family::reconstitute(
        new FamilyId($familyId),
        new DisplayName('Family ' . $familyId),
        $status,
        $representatives,
        $students,
    );
}

function familyApplicationRepresentativeMembership(
    int $membershipId,
    int $representativeId,
    int $relationshipTypeId,
    bool $isPrimary,
    string $startedAt,
    ?string $endedAt = null,
): FamilyRepresentative {
    return new FamilyRepresentative(
        new FamilyRepresentativeId($membershipId),
        new FamilyRepresentativeReference($representativeId),
        new RelationshipTypeId($relationshipTypeId),
        $isPrimary,
        familyApplicationDate($startedAt),
        $endedAt === null ? null : familyApplicationDate($endedAt),
    );
}

function familyApplicationStudentMembership(
    int $membershipId,
    int $studentId,
    string $startedAt,
    ?string $endedAt = null,
): FamilyStudent {
    return new FamilyStudent(
        new FamilyStudentId($membershipId),
        new FamilyStudentReference($studentId),
        familyApplicationDate($startedAt),
        $endedAt === null ? null : familyApplicationDate($endedAt),
    );
}

function familyPrimaryOutput(FamilyOutput $output): FamilyRepresentativeOutput
{
    foreach ($output->representatives as $membership) {
        if ($membership->isPrimary && $membership->isActive) {
            return $membership;
        }
    }

    throw new InvalidFamilyState('Expected active primary Representative output was not found.');
}

function familyRepresentativeOutput(
    FamilyOutput $output,
    int $representativeId,
    bool $active,
): FamilyRepresentativeOutput {
    foreach ($output->representatives as $membership) {
        if ($membership->representativeId === $representativeId
            && $membership->isActive === $active
        ) {
            return $membership;
        }
    }

    throw new InvalidFamilyState('Expected Representative membership output was not found.');
}

function familyStudentOutput(
    FamilyOutput $output,
    int $studentId,
    bool $active,
): FamilyStudentOutput {
    foreach ($output->students as $membership) {
        if ($membership->studentId === $studentId && $membership->isActive === $active) {
            return $membership;
        }
    }

    throw new InvalidFamilyState('Expected Student membership output was not found.');
}

/** @return array<string, mixed> */
function familyOutputSnapshot(FamilyOutput $output): array
{
    return [
        'id' => $output->id,
        'displayName' => $output->displayName,
        'status' => $output->status->value,
        'representatives' => array_map(
            static fn (FamilyRepresentativeOutput $membership): array => [
                $membership->id,
                $membership->representativeId,
                $membership->relationshipTypeId,
                $membership->isPrimary,
                $membership->startedAt->format('Y-m-d H:i:s.u e'),
                $membership->endedAt?->format('Y-m-d H:i:s.u e'),
                $membership->isActive,
            ],
            $output->representatives,
        ),
        'students' => array_map(
            static fn (FamilyStudentOutput $membership): array => [
                $membership->id,
                $membership->studentId,
                $membership->startedAt->format('Y-m-d H:i:s.u e'),
                $membership->endedAt?->format('Y-m-d H:i:s.u e'),
                $membership->isActive,
            ],
            $output->students,
        ),
    ];
}

function familyApplicationSource(): string
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        dirname(__DIR__) . '/app/Family/Application'
    ));
    foreach ($iterator as $file) {
        if ($file->isFile()
            && $file->getExtension() === 'php'
            && !str_contains(
                $file->getPathname(),
                DIRECTORY_SEPARATOR . 'Orchestration' . DIRECTORY_SEPARATOR,
            )
            && !str_contains(
                $file->getPathname(),
                DIRECTORY_SEPARATOR . 'RepresentativeResources' . DIRECTORY_SEPARATOR,
            )
        ) {
            $files[] = $file->getPathname();
        }
    }

    sort($files, SORT_STRING);

    return implode("\n", array_map(
        static fn (string $file): string => (string) file_get_contents($file),
        $files,
    ));
}
