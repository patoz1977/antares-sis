<?php

declare(strict_types=1);

namespace Tests;

use App\Family\Domain\Exception\InvalidFamilyState;
use App\Family\Domain\Family;
use App\Family\Domain\FamilyRepresentative;
use App\Family\Domain\FamilyStatus;
use App\Family\Domain\FamilyStudent;
use App\Family\Domain\ValueObject\DisplayName;
use App\Family\Domain\ValueObject\FamilyId;
use App\Family\Domain\ValueObject\FamilyRepresentativeId;
use App\Family\Domain\ValueObject\FamilyStudentId;
use App\Family\Domain\ValueObject\RelationshipTypeId;
use App\Family\Domain\ValueObject\RepresentativeId;
use App\Family\Domain\ValueObject\StudentId;
use DateTimeImmutable;
use DateTimeZone;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Tests\Support\TestRunner;
use TypeError;

function registerFamilyMembershipDomainTests(TestRunner $runner): void
{
    $runner->add('Family membership identities require positive immutable values and compare by value', function (): void {
        $identities = [
            [FamilyId::class, new FamilyId(10), new FamilyId(10), new FamilyId(11)],
            [FamilyRepresentativeId::class, new FamilyRepresentativeId(20), new FamilyRepresentativeId(20), new FamilyRepresentativeId(21)],
            [FamilyStudentId::class, new FamilyStudentId(30), new FamilyStudentId(30), new FamilyStudentId(31)],
            [RepresentativeId::class, new RepresentativeId(40), new RepresentativeId(40), new RepresentativeId(41)],
            [StudentId::class, new StudentId(50), new StudentId(50), new StudentId(51)],
            [RelationshipTypeId::class, new RelationshipTypeId(60), new RelationshipTypeId(60), new RelationshipTypeId(61)],
        ];

        foreach ($identities as [$class, $identity, $equal, $different]) {
            assertSameValue(true, $identity->value() > 0);
            assertSameValue(true, $identity->equals($equal));
            assertSameValue(false, $identity->equals($different));
            assertSameValue(true, (new ReflectionClass($class))->isReadOnly());
            assertThrows(static fn (): object => new $class(0), InvalidFamilyState::class);
            assertThrows(static fn (): object => new $class(-1), InvalidFamilyState::class);
        }
    });

    $runner->add('DisplayName trims boundaries and preserves case and internal format', function (): void {
        $name = new DisplayName('  Family García  López  ');

        assertSameValue('Family García  López', $name->value());
        assertSameValue(true, $name->equals(new DisplayName('Family García  López')));
        assertSameValue(false, $name->equals(new DisplayName('FAMILY GARCÍA  LÓPEZ')));
        assertSameValue(true, (new ReflectionClass(DisplayName::class))->isReadOnly());
    });

    $runner->add('DisplayName accepts exactly 150 characters and rejects missing or excessive values', function (): void {
        $maximum = str_repeat('x', 150);

        assertSameValue($maximum, (new DisplayName($maximum))->value());
        assertThrows(static fn (): DisplayName => new DisplayName(' '), InvalidFamilyState::class);
        assertThrows(
            static fn (): DisplayName => new DisplayName(str_repeat('x', 151)),
            InvalidFamilyState::class,
        );
    });

    $runner->add('Family creation is atomic with one active primary Representative and no Student', function (): void {
        $family = familyMembershipFixture();
        $representatives = $family->representatives();

        assertSameValue(null, $family->id());
        assertSameValue('Household One', $family->displayName()->value());
        assertSameValue(FamilyStatus::Active, $family->status());
        assertSameValue(true, $family->isActive());
        assertSameValue(1, count($representatives));
        assertSameValue(null, $representatives[0]->id());
        assertSameValue(101, $representatives[0]->representativeId()->value());
        assertSameValue(201, $representatives[0]->relationshipTypeId()->value());
        assertSameValue(true, $representatives[0]->isActive());
        assertSameValue(true, $representatives[0]->isPrimary());
        assertSameValue([], $family->students());
    });

    $runner->add('Family creation supports only ACTIVE and INACTIVE without changing membership', function (): void {
        $inactive = familyMembershipFixture(FamilyStatus::Inactive);

        assertSameValue(false, $inactive->isActive());
        assertSameValue(FamilyStatus::Inactive, $inactive->status());
        assertSameValue(true, $inactive->primaryRepresentative()->isActive());
        assertSameValue('ACTIVE', FamilyStatus::Active->value);
        assertSameValue('INACTIVE', FamilyStatus::Inactive->value);
        assertSameValue(2, count(FamilyStatus::cases()));
    });

    $runner->add('Family cannot be created without its initial Representative or with a caller-selected primary flag', function (): void {
        $create = new \ReflectionMethod(Family::class, 'create');
        $constructor = (new ReflectionClass(Family::class))->getConstructor();
        $parameterNames = array_map(
            static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
            $create->getParameters(),
        );

        assertSameValue(true, $constructor?->isPrivate());
        assertSameValue(
            ['displayName', 'status', 'initialRepresentativeId', 'initialRelationshipTypeId', 'startedAt'],
            $parameterNames,
        );
        assertSameValue(false, in_array('isPrimary', $parameterNames, true));
        assertThrows(
            static fn (): Family => Family::create(
                new DisplayName('Invalid'),
                FamilyStatus::Active,
                null,
                new RelationshipTypeId(1),
                familyMembershipStart(),
            ),
            TypeError::class,
        );
    });

    $runner->add('FamilyRepresentative exposes optional identity and immutable required references', function (): void {
        $membership = new FamilyRepresentative(
            null,
            new RepresentativeId(10),
            new RelationshipTypeId(20),
            false,
            familyMembershipStart(),
            null,
        );
        $reflection = new ReflectionClass($membership);

        assertSameValue(null, $membership->id());
        assertSameValue(10, $membership->representativeId()->value());
        assertSameValue(20, $membership->relationshipTypeId()->value());
        assertSameValue(false, $membership->isPrimary());
        assertSameValue(true, $membership->isActive());
        assertSameValue(true, $reflection->getProperty('id')->isReadOnly());
        assertSameValue(true, $reflection->getProperty('representativeId')->isReadOnly());
        assertSameValue(true, $reflection->getProperty('relationshipTypeId')->isReadOnly());
        assertSameValue(false, method_exists($membership, 'setRepresentativeId'));
        assertSameValue(false, method_exists($membership, 'setRelationshipTypeId'));
    });

    $runner->add('FamilyRepresentative normalizes timestamps to seconds without changing timezone', function (): void {
        $membership = new FamilyRepresentative(
            new FamilyRepresentativeId(1),
            new RepresentativeId(10),
            new RelationshipTypeId(20),
            true,
            new DateTimeImmutable('2026-08-01 10:11:12.987654+05:30'),
            null,
        );

        assertSameValue('2026-08-01 10:11:12.000000 +05:30', $membership->startedAt()->format('Y-m-d H:i:s.u P'));

        $membership->end(new DateTimeImmutable('2026-08-01 10:12:13.654321+05:30'));
        assertSameValue(false, $membership->isActive());
        assertSameValue('2026-08-01 10:12:13.000000 +05:30', $membership->endedAt()?->format('Y-m-d H:i:s.u P'));
    });

    $runner->add('FamilyRepresentative requires typed references and coherent explicit dates', function (): void {
        assertThrows(
            static fn (): FamilyRepresentative => new FamilyRepresentative(
                null,
                null,
                new RelationshipTypeId(1),
                false,
                familyMembershipStart(),
                null,
            ),
            TypeError::class,
        );
        assertThrows(
            static fn (): FamilyRepresentative => new FamilyRepresentative(
                null,
                new RepresentativeId(1),
                null,
                false,
                familyMembershipStart(),
                null,
            ),
            TypeError::class,
        );
        assertThrows(
            static fn (): FamilyRepresentative => familyRepresentativeMembership(
                1,
                false,
                '2026-08-02 00:00:00',
                '2026-08-01 23:59:59',
            ),
            InvalidFamilyState::class,
        );
    });

    $runner->add('Ended FamilyRepresentative membership cannot reactivate or end twice', function (): void {
        $membership = familyRepresentativeMembership(
            10,
            false,
            '2026-08-01 10:00:00',
            '2026-08-01 11:00:00',
        );

        assertSameValue(false, $membership->isActive());
        assertSameValue(false, method_exists($membership, 'activate'));
        assertSameValue(false, method_exists($membership, 'reactivate'));
        assertThrows(
            static fn () => $membership->end(new DateTimeImmutable('2026-08-01 12:00:00')),
            InvalidFamilyState::class,
        );
    });

    $runner->add('FamilyStudent exposes optional identity and immutable Student reference', function (): void {
        $membership = new FamilyStudent(null, new StudentId(30), familyMembershipStart(), null);
        $reflection = new ReflectionClass($membership);

        assertSameValue(null, $membership->id());
        assertSameValue(30, $membership->studentId()->value());
        assertSameValue(true, $membership->isActive());
        assertSameValue(true, $reflection->getProperty('id')->isReadOnly());
        assertSameValue(true, $reflection->getProperty('studentId')->isReadOnly());
        assertSameValue(false, method_exists($membership, 'setStudentId'));
    });

    $runner->add('FamilyStudent requires a typed Student and coherent explicit dates', function (): void {
        assertThrows(
            static fn (): FamilyStudent => new FamilyStudent(null, null, familyMembershipStart(), null),
            TypeError::class,
        );
        assertThrows(
            static fn (): FamilyStudent => familyStudentMembership(
                1,
                '2026-08-02 00:00:00',
                '2026-08-01 23:59:59',
            ),
            InvalidFamilyState::class,
        );
    });

    $runner->add('Ended FamilyStudent membership cannot reactivate or end twice', function (): void {
        $membership = familyStudentMembership(
            30,
            '2026-08-01 10:00:00',
            '2026-08-01 11:00:00',
        );

        assertSameValue(false, $membership->isActive());
        assertSameValue(false, method_exists($membership, 'activate'));
        assertSameValue(false, method_exists($membership, 'reactivate'));
        assertThrows(
            static fn () => $membership->end(new DateTimeImmutable('2026-08-01 12:00:00')),
            InvalidFamilyState::class,
        );
    });

    $runner->add('Family reconstitutes persisted identity and historical memberships', function (): void {
        $family = reconstitutedFamilyFixture();

        assertSameValue(500, $family->id()?->value());
        assertSameValue(3, count($family->representatives()));
        assertSameValue(2, count($family->activeRepresentatives()));
        assertSameValue(3, count($family->students()));
        assertSameValue(1, count($family->activeStudents()));
        assertSameValue(101, $family->primaryRepresentative()->representativeId()->value());
    });

    $runner->add('Family reconstruction rejects absence of an active Representative', function (): void {
        assertThrows(
            static fn (): Family => Family::reconstitute(
                new FamilyId(1),
                new DisplayName('Invalid'),
                FamilyStatus::Active,
                [familyRepresentativeMembership(1, true, '2026-01-01', '2026-02-01')],
                [],
            ),
            InvalidFamilyState::class,
        );
    });

    $runner->add('Family reconstruction rejects absence or multiplicity of active primary Representative', function (): void {
        assertThrows(
            static fn (): Family => Family::reconstitute(
                new FamilyId(1),
                new DisplayName('No primary'),
                FamilyStatus::Active,
                [familyRepresentativeMembership(1, false)],
                [],
            ),
            InvalidFamilyState::class,
        );
        assertThrows(
            static fn (): Family => Family::reconstitute(
                new FamilyId(1),
                new DisplayName('Two primary'),
                FamilyStatus::Active,
                [familyRepresentativeMembership(1, true), familyRepresentativeMembership(2, true)],
                [],
            ),
            InvalidFamilyState::class,
        );
    });

    $runner->add('Family reconstruction rejects duplicate active Representative membership', function (): void {
        assertThrows(
            static fn (): Family => Family::reconstitute(
                new FamilyId(1),
                new DisplayName('Duplicate representative'),
                FamilyStatus::Active,
                [familyRepresentativeMembership(1, true), familyRepresentativeMembership(1, false)],
                [],
            ),
            InvalidFamilyState::class,
        );
    });

    $runner->add('Family reconstruction rejects duplicate active Student membership', function (): void {
        assertThrows(
            static fn (): Family => Family::reconstitute(
                new FamilyId(1),
                new DisplayName('Duplicate student'),
                FamilyStatus::Active,
                [familyRepresentativeMembership(1, true)],
                [familyStudentMembership(10), familyStudentMembership(10)],
            ),
            InvalidFamilyState::class,
        );
    });

    $runner->add('Family reconstruction permits repeated historical identities without simultaneous activity', function (): void {
        $family = Family::reconstitute(
            new FamilyId(1),
            new DisplayName('Historical memberships'),
            FamilyStatus::Active,
            [
                familyRepresentativeMembership(1, true),
                familyRepresentativeMembership(2, false, '2024-01-01', '2024-02-01'),
                familyRepresentativeMembership(2, false, '2025-01-01', '2025-02-01'),
                familyRepresentativeMembership(2, false, '2026-01-01'),
            ],
            [
                familyStudentMembership(10, '2024-01-01', '2024-02-01'),
                familyStudentMembership(10, '2025-01-01', '2025-02-01'),
                familyStudentMembership(10, '2026-01-01'),
            ],
        );

        assertSameValue(4, count($family->representatives()));
        assertSameValue(2, count($family->activeRepresentatives()));
        assertSameValue(3, count($family->students()));
        assertSameValue(1, count($family->activeStudents()));
    });

    $runner->add('Family updates DisplayName and lifecycle without altering memberships', function (): void {
        $family = familyMembershipFixture();
        $originalRepresentative = $family->primaryRepresentative()->representativeId()->value();

        $family->updateDisplayName(new DisplayName('Updated Household'));
        $family->deactivate();
        assertSameValue('Updated Household', $family->displayName()->value());
        assertSameValue(FamilyStatus::Inactive, $family->status());
        assertSameValue(1, count($family->activeRepresentatives()));

        $family->activate();
        assertSameValue(FamilyStatus::Active, $family->status());
        assertSameValue($originalRepresentative, $family->primaryRepresentative()->representativeId()->value());
    });

    $runner->add('Family adds only non-primary additional Representative and rejects active duplicate atomically', function (): void {
        $family = familyMembershipFixture();
        $added = $family->addRepresentative(
            new RepresentativeId(102),
            new RelationshipTypeId(202),
            new DateTimeImmutable('2026-08-02 08:00:00', new DateTimeZone('UTC')),
        );

        assertSameValue(null, $added->id());
        assertSameValue(false, $added->isPrimary());
        assertSameValue(true, $added->isActive());
        assertSameValue(2, count($family->representatives()));
        assertSameValue(101, $family->primaryRepresentative()->representativeId()->value());

        assertThrows(
            static fn () => $family->addRepresentative(
                new RepresentativeId(102),
                new RelationshipTypeId(203),
                new DateTimeImmutable('2026-08-03', new DateTimeZone('UTC')),
            ),
            InvalidFamilyState::class,
        );
        assertSameValue(2, count($family->representatives()));
    });

    $runner->add('Family ends a non-primary Representative and permits a later active membership', function (): void {
        $family = familyMembershipFixture();
        $family->addRepresentative(new RepresentativeId(102), new RelationshipTypeId(202), familyMembershipStart());

        $family->endRepresentativeMembership(
            new RepresentativeId(102),
            new DateTimeImmutable('2026-08-02', new DateTimeZone('UTC')),
        );
        assertSameValue(1, count($family->activeRepresentatives()));
        assertSameValue(2, count($family->representatives()));

        $family->addRepresentative(
            new RepresentativeId(102),
            new RelationshipTypeId(203),
            new DateTimeImmutable('2026-08-03', new DateTimeZone('UTC')),
        );
        assertSameValue(2, count($family->activeRepresentatives()));
        assertSameValue(3, count($family->representatives()));
    });

    $runner->add('Family refuses to end its last or primary active Representative', function (): void {
        $family = familyMembershipFixture();

        assertThrows(
            static fn () => $family->endRepresentativeMembership(
                new RepresentativeId(101),
                new DateTimeImmutable('2026-08-02', new DateTimeZone('UTC')),
            ),
            InvalidFamilyState::class,
        );
        assertSameValue(1, count($family->activeRepresentatives()));
        assertSameValue(101, $family->primaryRepresentative()->representativeId()->value());

        $family->addRepresentative(new RepresentativeId(102), new RelationshipTypeId(202), familyMembershipStart());
        assertThrows(
            static fn () => $family->endRepresentativeMembership(
                new RepresentativeId(101),
                new DateTimeImmutable('2026-08-02', new DateTimeZone('UTC')),
            ),
            InvalidFamilyState::class,
        );
        assertSameValue(2, count($family->activeRepresentatives()));
    });

    $runner->add('Family Representative termination failure leaves membership unchanged', function (): void {
        $family = familyMembershipFixture();
        $family->addRepresentative(
            new RepresentativeId(102),
            new RelationshipTypeId(202),
            new DateTimeImmutable('2026-08-02', new DateTimeZone('UTC')),
        );

        assertThrows(
            static fn () => $family->endRepresentativeMembership(
                new RepresentativeId(102),
                new DateTimeImmutable('2026-08-01', new DateTimeZone('UTC')),
            ),
            InvalidFamilyState::class,
        );
        assertSameValue(2, count($family->activeRepresentatives()));
    });

    $runner->add('Family adds Student without Representative coupling and rejects active duplicate atomically', function (): void {
        $family = familyMembershipFixture();
        $added = $family->addStudent(new StudentId(301), familyMembershipStart());

        assertSameValue(null, $added->id());
        assertSameValue(301, $added->studentId()->value());
        assertSameValue(true, $added->isActive());
        assertSameValue(1, count($family->students()));

        assertThrows(
            static fn () => $family->addStudent(new StudentId(301), familyMembershipStart()),
            InvalidFamilyState::class,
        );
        assertSameValue(1, count($family->students()));
        assertSameValue(1, count($family->activeStudents()));
    });

    $runner->add('Family ends Student membership once and permits a later historical cycle', function (): void {
        $family = familyMembershipFixture();
        $family->addStudent(new StudentId(301), familyMembershipStart());

        $family->endStudentMembership(
            new StudentId(301),
            new DateTimeImmutable('2026-08-02', new DateTimeZone('UTC')),
        );
        assertSameValue(0, count($family->activeStudents()));
        assertSameValue(1, count($family->students()));

        assertThrows(
            static fn () => $family->endStudentMembership(
                new StudentId(301),
                new DateTimeImmutable('2026-08-03', new DateTimeZone('UTC')),
            ),
            InvalidFamilyState::class,
        );

        $family->addStudent(
            new StudentId(301),
            new DateTimeImmutable('2026-08-03', new DateTimeZone('UTC')),
        );
        assertSameValue(1, count($family->activeStudents()));
        assertSameValue(2, count($family->students()));
    });

    $runner->add('Family Student termination failure leaves membership unchanged', function (): void {
        $family = familyMembershipFixture();
        $family->addStudent(
            new StudentId(301),
            new DateTimeImmutable('2026-08-02', new DateTimeZone('UTC')),
        );

        assertThrows(
            static fn () => $family->endStudentMembership(
                new StudentId(301),
                new DateTimeImmutable('2026-08-01', new DateTimeZone('UTC')),
            ),
            InvalidFamilyState::class,
        );
        assertSameValue(1, count($family->activeStudents()));
    });

    $runner->add('Family collections and returned Entities cannot mutate Aggregate state externally', function (): void {
        $family = familyMembershipFixture();
        $family->addRepresentative(new RepresentativeId(102), new RelationshipTypeId(202), familyMembershipStart());
        $family->addStudent(new StudentId(301), familyMembershipStart());

        $representatives = $family->representatives();
        $students = $family->students();
        array_pop($representatives);
        array_pop($students);
        $family->primaryRepresentative()->end(new DateTimeImmutable('2026-08-02', new DateTimeZone('UTC')));
        $family->activeStudents()[0]->end(new DateTimeImmutable('2026-08-02', new DateTimeZone('UTC')));

        assertSameValue(2, count($family->representatives()));
        assertSameValue(1, count($family->students()));
        assertSameValue(true, $family->primaryRepresentative()->isActive());
        assertSameValue(1, count($family->activeStudents()));
    });

    $runner->add('Family Domain stays isolated and preserves membership Entity structure', function (): void {
        $domainDirectory = __DIR__ . '/../app/Family/Domain';
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($domainDirectory));
        $phpFiles = [];
        $modelSource = '';

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $phpFiles[] = str_replace('\\', '/', $file->getPathname());
                $fileSource = (string) file_get_contents($file->getPathname());
                if ($file->getFilename() !== 'FamilyRepository.php') {
                    $modelSource .= $fileSource;
                }
            }
        }

        sort($phpFiles, SORT_STRING);
        $familyProperties = array_map(
            static fn (\ReflectionProperty $property): string => $property->getName(),
            (new ReflectionClass(Family::class))->getProperties(),
        );
        sort($familyProperties, SORT_STRING);
        $representativeProperties = array_map(
            static fn (\ReflectionProperty $property): string => $property->getName(),
            (new ReflectionClass(FamilyRepresentative::class))->getProperties(),
        );
        sort($representativeProperties, SORT_STRING);
        $studentProperties = array_map(
            static fn (\ReflectionProperty $property): string => $property->getName(),
            (new ReflectionClass(FamilyStudent::class))->getProperties(),
        );
        sort($studentProperties, SORT_STRING);

        assertSameValue(37, count($phpFiles));
        assertSameValue(
            [
                'addresses',
                'authorizedPickupAssignments',
                'authorizedPickups',
                'displayName',
                'emergencyContactAssignments',
                'emergencyContacts',
                'id',
                'representativeAddressAssignments',
                'representatives',
                'status',
                'studentAddressAssignments',
                'students',
            ],
            $familyProperties,
        );
        assertSameValue(
            ['endedAt', 'id', 'isPrimary', 'relationshipTypeId', 'representativeId', 'startedAt'],
            $representativeProperties,
        );
        assertSameValue(['endedAt', 'id', 'startedAt', 'studentId'], $studentProperties);

        foreach ([
            'App\\Person\\',
            'App\\Representative\\',
            'App\\Student\\',
            'Infrastructure',
            'PDO',
            'SQL',
            'Http',
            'Controller',
            'View',
            'Enrollment',
            'User',
            'Repository',
            'Service',
            'Factory',
            'Builder',
            'RepresentativeStudent',
        ] as $forbidden) {
            assertSameValue(false, str_contains($modelSource, $forbidden));
        }
    });
}

function familyMembershipFixture(FamilyStatus $status = FamilyStatus::Active): Family
{
    return Family::create(
        new DisplayName('Household One'),
        $status,
        new RepresentativeId(101),
        new RelationshipTypeId(201),
        familyMembershipStart(),
    );
}

function reconstitutedFamilyFixture(): Family
{
    return Family::reconstitute(
        new FamilyId(500),
        new DisplayName('Persisted Household'),
        FamilyStatus::Active,
        [
            familyRepresentativeMembership(101, true),
            familyRepresentativeMembership(102, false, '2025-01-01', '2025-02-01'),
            familyRepresentativeMembership(102, false, '2026-01-01'),
        ],
        [
            familyStudentMembership(301, '2024-01-01', '2024-02-01'),
            familyStudentMembership(301, '2025-01-01', '2025-02-01'),
            familyStudentMembership(301, '2026-01-01'),
        ],
    );
}

function familyRepresentativeMembership(
    int $representativeId,
    bool $isPrimary,
    string $startedAt = '2026-01-01 00:00:00',
    ?string $endedAt = null,
): FamilyRepresentative {
    return new FamilyRepresentative(
        new FamilyRepresentativeId(abs($representativeId) + 1000),
        new RepresentativeId($representativeId),
        new RelationshipTypeId(201),
        $isPrimary,
        new DateTimeImmutable($startedAt, new DateTimeZone('UTC')),
        $endedAt === null ? null : new DateTimeImmutable($endedAt, new DateTimeZone('UTC')),
    );
}

function familyStudentMembership(
    int $studentId,
    string $startedAt = '2026-01-01 00:00:00',
    ?string $endedAt = null,
): FamilyStudent {
    return new FamilyStudent(
        new FamilyStudentId(abs($studentId) + 2000),
        new StudentId($studentId),
        new DateTimeImmutable($startedAt, new DateTimeZone('UTC')),
        $endedAt === null ? null : new DateTimeImmutable($endedAt, new DateTimeZone('UTC')),
    );
}

function familyMembershipStart(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-08-01 09:10:11.123456', new DateTimeZone('UTC'));
}
