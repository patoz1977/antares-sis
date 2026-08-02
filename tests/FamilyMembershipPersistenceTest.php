<?php

declare(strict_types=1);

namespace Tests;

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
use App\Family\Domain\ValueObject\RepresentativeId;
use App\Family\Domain\ValueObject\StudentId;
use App\Family\Infrastructure\Persistence\PdoFamilyRepository;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use Tests\Support\TestRunner;

function registerFamilyMembershipPersistenceTests(TestRunner $runner): void
{
    $runner->add('FamilyRepository exposes only the approved Aggregate operations', function (): void {
        $reflection = new ReflectionClass(FamilyRepository::class);
        $methods = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            $reflection->getMethods(),
        );
        sort($methods, SORT_STRING);

        assertSameValue(
            ['findActiveByRepresentativeId', 'findActiveByStudentId', 'findById', 'save'],
            $methods,
        );
        assertSameValue(Family::class, $reflection->getMethod('save')->getReturnType()?->getName());
        assertSameValue(true, (new ReflectionClass(PdoFamilyRepository::class))->implementsInterface(
            FamilyRepository::class
        ));

        $source = familyPersistenceSource('app/Family/Domain/FamilyRepository.php');
        assertSameValue(false, str_contains($source, 'Infrastructure'));
        assertSameValue(false, str_contains($source, 'PDO'));
    });

    $runner->add('pdo Family repository finds complete active and inactive Aggregates', function (): void {
        $pdo = sqliteFamilyDatabase();
        insertRawFamily($pdo, 100, 1, 'Family Active', 1, '2026-01-02 10:00:00');
        insertRawRepresentativeMembership($pdo, 1002, 100, 2, 1, false, '2025-01-01 09:00:00', '2025-12-01 09:00:00');
        insertRawRepresentativeMembership($pdo, 1003, 100, 3, 1, false, '2026-01-01 09:00:00', null);
        insertRawStudentMembership($pdo, 2001, 100, 1, '2026-02-01 08:00:00', null);
        insertRawStudentMembership($pdo, 2002, 100, 2, '2025-02-01 08:00:00', '2025-11-01 08:00:00');
        insertRawFamily($pdo, 101, 2, 'Family Inactive', 4, '2026-01-01 10:00:00');
        $repository = familyPersistenceRepositoryWithPdo($pdo);

        $family = $repository->findById(new FamilyId(100));
        $inactive = $repository->findById(new FamilyId(101));

        assertSameValue(100, $family?->id()?->value());
        assertSameValue('Family Active', $family?->displayName()->value());
        assertSameValue(FamilyStatus::Active, $family?->status());
        assertSameValue(1, $family?->primaryRepresentative()->representativeId()->value());
        assertSameValue([2, 3, 1], array_map(
            static fn (FamilyRepresentative $membership): int => $membership->representativeId()->value(),
            $family?->representatives() ?? [],
        ));
        assertSameValue([2, 1], array_map(
            static fn (FamilyStudent $membership): int => $membership->studentId()->value(),
            $family?->students() ?? [],
        ));
        assertSameValue(FamilyStatus::Inactive, $inactive?->status());
        assertSameValue(null, $repository->findById(new FamilyId(999)));
    });

    $runner->add('pdo Family repository rejects wrong or unsupported GENERAL_STATUS', function (): void {
        $pdo = sqliteFamilyDatabase();
        insertRawFamily($pdo, 110, 3, 'Wrong Type', 1, '2026-01-01 00:00:00');
        insertRawFamily($pdo, 111, 4, 'Wrong Code', 2, '2026-01-01 00:00:00');
        $repository = familyPersistenceRepositoryWithPdo($pdo);

        assertThrows(
            static fn (): ?Family => $repository->findById(new FamilyId(110)),
            RuntimeException::class,
        );
        assertThrows(
            static fn (): ?Family => $repository->findById(new FamilyId(111)),
            RuntimeException::class,
        );
    });

    $runner->add('pdo Family repository requires exact UTC membership timestamps', function (): void {
        $pdo = sqliteFamilyDatabase(false);
        insertRawFamily($pdo, 120, 1, 'Invalid Representative Date', 1, 'not-a-timestamp');
        insertRawFamily($pdo, 121, 1, 'Invalid Student Date', 2, '2026-01-01 00:00:00');
        insertRawStudentMembership($pdo, 1211, 121, 1, '2026-02-30 00:00:00', null);
        $repository = familyPersistenceRepositoryWithPdo($pdo);

        assertThrows(
            static fn (): ?Family => $repository->findById(new FamilyId(120)),
            RuntimeException::class,
        );
        assertThrows(
            static fn (): ?Family => $repository->findById(new FamilyId(121)),
            RuntimeException::class,
        );
    });

    $runner->add('pdo Family reconstruction exposes persisted membership corruption', function (): void {
        $repositoryCases = [];

        $withoutActive = sqliteFamilyDatabase(false);
        insertRawFamily($withoutActive, 130, 1, 'No Active', 1, '2026-01-01 00:00:00', '2026-02-01 00:00:00');
        $repositoryCases[] = [familyPersistenceRepositoryWithPdo($withoutActive), 130];

        $withoutPrimary = sqliteFamilyDatabase(false);
        insertRawFamily($withoutPrimary, 131, 1, 'No Primary', 1, '2026-01-01 00:00:00', null, false);
        $repositoryCases[] = [familyPersistenceRepositoryWithPdo($withoutPrimary), 131];

        $manyPrimary = sqliteFamilyDatabase(false);
        insertRawFamily($manyPrimary, 132, 1, 'Many Primary', 1, '2026-01-01 00:00:00');
        insertRawRepresentativeMembership($manyPrimary, 1322, 132, 2, 1, true, '2026-01-02 00:00:00', null);
        $repositoryCases[] = [familyPersistenceRepositoryWithPdo($manyPrimary), 132];

        $duplicateRepresentative = sqliteFamilyDatabase(false);
        insertRawFamily($duplicateRepresentative, 133, 1, 'Duplicate Representative', 1, '2026-01-01 00:00:00');
        insertRawRepresentativeMembership($duplicateRepresentative, 1332, 133, 1, 1, false, '2026-01-02 00:00:00', null);
        $repositoryCases[] = [familyPersistenceRepositoryWithPdo($duplicateRepresentative), 133];

        $duplicateStudent = sqliteFamilyDatabase(false);
        insertRawFamily($duplicateStudent, 134, 1, 'Duplicate Student', 1, '2026-01-01 00:00:00');
        insertRawStudentMembership($duplicateStudent, 1341, 134, 1, '2026-01-01 00:00:00', null);
        insertRawStudentMembership($duplicateStudent, 1342, 134, 1, '2026-01-02 00:00:00', null);
        $repositoryCases[] = [familyPersistenceRepositoryWithPdo($duplicateStudent), 134];

        foreach ($repositoryCases as [$repository, $id]) {
            assertThrows(
                static fn (): ?Family => $repository->findById(new FamilyId($id)),
                RuntimeException::class,
            );
        }
    });

    $runner->add('pdo Family lookup by Representative returns active memberships deterministically', function (): void {
        $pdo = sqliteFamilyDatabase();
        insertRawFamily($pdo, 140, 2, 'Inactive Family Still Related', 1, '2026-01-01 00:00:00');
        insertRawFamily($pdo, 141, 1, 'Second Family', 2, '2026-01-01 00:00:00');
        insertRawRepresentativeMembership($pdo, 1412, 141, 1, 1, false, '2025-01-01 00:00:00', null);
        insertRawFamily($pdo, 142, 1, 'Historical Only', 3, '2025-01-01 00:00:00', '2025-02-01 00:00:00');
        $repository = familyPersistenceRepositoryWithPdo($pdo);

        $families = $repository->findActiveByRepresentativeId(new RepresentativeId(1));

        assertSameValue([140, 141], array_map(
            static fn (Family $family): ?int => $family->id()?->value(),
            $families,
        ));
        assertSameValue(FamilyStatus::Inactive, $families[0]->status());
        assertSameValue([], $repository->findActiveByRepresentativeId(new RepresentativeId(10)));
    });

    $runner->add('pdo Family lookup by Student returns only the active complete Family', function (): void {
        $pdo = sqliteFamilyDatabase();
        insertRawFamily($pdo, 150, 1, 'Historical Student', 1, '2026-01-01 00:00:00');
        insertRawStudentMembership($pdo, 1501, 150, 1, '2025-01-01 00:00:00', '2025-02-01 00:00:00');
        insertRawFamily($pdo, 151, 1, 'Active Student', 2, '2026-01-01 00:00:00');
        insertRawStudentMembership($pdo, 1511, 151, 1, '2026-01-01 00:00:00', null);
        $repository = familyPersistenceRepositoryWithPdo($pdo);

        assertSameValue(151, $repository->findActiveByStudentId(new StudentId(1))?->id()?->value());
        assertSameValue(null, $repository->findActiveByStudentId(new StudentId(10)));
    });

    $runner->add('pdo Family lookup rejects multiple active Families for one Student', function (): void {
        $pdo = sqliteFamilyDatabase(false);
        insertRawFamily($pdo, 160, 1, 'First', 1, '2026-01-01 00:00:00');
        insertRawFamily($pdo, 161, 1, 'Second', 2, '2026-01-01 00:00:00');
        insertRawStudentMembership($pdo, 1601, 160, 1, '2026-01-01 00:00:00', null);
        insertRawStudentMembership($pdo, 1611, 161, 1, '2026-02-01 00:00:00', null);
        $repository = familyPersistenceRepositoryWithPdo($pdo);

        assertThrows(
            static fn (): ?Family => $repository->findActiveByStudentId(new StudentId(1)),
            RuntimeException::class,
        );
    });

    $runner->add('pdo Family insert is atomic and returns every generated identity', function (): void {
        $pdo = sqliteFamilyDatabase();
        $repository = familyPersistenceRepositoryWithPdo($pdo);
        $new = newFamilyPersistenceFixture(1, FamilyStatus::Active, '  O\'Brien Family  ');
        $new->addRepresentative(new RepresentativeId(2), new RelationshipTypeId(1), familyPersistenceTime('2026-01-02 10:00:00'));
        $new->addStudent(new StudentId(1), familyPersistenceTime('2026-01-03 10:00:00'));

        $persisted = $repository->save($new);

        assertSameValue(null, $new->id());
        assertSameValue(true, requiredFamilyPersistenceId($persisted)->value() > 0);
        assertSameValue("O'Brien Family", $persisted->displayName()->value());
        assertSameValue(2, count($persisted->representatives()));
        assertSameValue(1, count($persisted->students()));
        foreach ($persisted->representatives() as $membership) {
            assertSameValue(true, ($membership->id()?->value() ?? 0) > 0);
        }
        assertSameValue(true, ($persisted->students()[0]->id()?->value() ?? 0) > 0);
        assertSameValue(false, $pdo->inTransaction());
        assertSameValue(1, (int) $pdo->query('SELECT COUNT(*) FROM families')->fetchColumn());
    });

    $runner->add('pdo Family inserts use distinct positive database identities without sequence assumptions', function (): void {
        $pdo = sqliteFamilyDatabase();
        $pdo->exec("INSERT INTO families (id, display_name, status_id) VALUES (50, 'Sequence Gap', 1)");
        $pdo->exec('DELETE FROM families WHERE id = 50');
        $repository = familyPersistenceRepositoryWithPdo($pdo);

        $first = $repository->save(newFamilyPersistenceFixture(1));
        $second = $repository->save(newFamilyPersistenceFixture(2));
        $firstId = requiredFamilyPersistenceId($first);
        $secondId = requiredFamilyPersistenceId($second);

        assertSameValue(true, $firstId->value() > 0);
        assertSameValue(true, $secondId->value() > 0);
        assertSameValue(false, $firstId->equals($secondId));
        assertSameValue($secondId->value(), $repository->findById($secondId)?->id()?->value());
    });

    $runner->add('pdo Family insert rolls back when its initial Representative fails', function (): void {
        $pdo = sqliteFamilyDatabase();
        $repository = familyPersistenceRepositoryWithPdo($pdo);
        $invalid = newFamilyPersistenceFixture(999);

        assertThrows(static fn (): Family => $repository->save($invalid), PDOException::class);
        assertSameValue(false, $pdo->inTransaction());
        assertSameValue(0, (int) $pdo->query('SELECT COUNT(*) FROM families')->fetchColumn());
        assertSameValue(0, (int) $pdo->query('SELECT COUNT(*) FROM family_representatives')->fetchColumn());
    });

    $runner->add('pdo Family insert rolls back all rows when a Student fails', function (): void {
        $pdo = sqliteFamilyDatabase();
        $repository = familyPersistenceRepositoryWithPdo($pdo);
        $invalid = newFamilyPersistenceFixture(1);
        $invalid->addStudent(new StudentId(999), familyPersistenceTime('2026-01-02 00:00:00'));

        assertThrows(static fn (): Family => $repository->save($invalid), PDOException::class);
        assertSameValue(0, (int) $pdo->query('SELECT COUNT(*) FROM families')->fetchColumn());
        assertSameValue(0, (int) $pdo->query('SELECT COUNT(*) FROM family_representatives')->fetchColumn());
        assertSameValue(0, (int) $pdo->query('SELECT COUNT(*) FROM family_students')->fetchColumn());
    });

    $runner->add('pdo Family repository participates in a successful outer transaction', function (): void {
        $pdo = sqliteFamilyDatabase();
        $repository = familyPersistenceRepositoryWithPdo($pdo);
        $pdo->beginTransaction();

        $persisted = $repository->save(newFamilyPersistenceFixture(1));

        assertSameValue(true, $pdo->inTransaction());
        assertSameValue(true, requiredFamilyPersistenceId($persisted)->value() > 0);
        $pdo->rollBack();
        assertSameValue(0, (int) $pdo->query('SELECT COUNT(*) FROM families')->fetchColumn());
    });

    $runner->add('pdo Family repository does not roll back an outer transaction on failure', function (): void {
        $pdo = sqliteFamilyDatabase();
        $repository = familyPersistenceRepositoryWithPdo($pdo);
        $pdo->beginTransaction();

        assertThrows(
            static fn (): Family => $repository->save(newFamilyPersistenceFixture(999)),
            PDOException::class,
        );
        assertSameValue(true, $pdo->inTransaction());
        $pdo->rollBack();
    });

    $runner->add('pdo Family update persists root changes and new memberships', function (): void {
        $pdo = sqliteFamilyDatabase();
        $repository = familyPersistenceRepositoryWithPdo($pdo);
        $family = $repository->save(newFamilyPersistenceFixture(1));
        $family->updateDisplayName(new DisplayName('Updated Family'));
        $family->deactivate();
        $family->addRepresentative(new RepresentativeId(2), new RelationshipTypeId(2), familyPersistenceTime('2026-02-01 00:00:00'));
        $family->addStudent(new StudentId(1), familyPersistenceTime('2026-02-02 00:00:00'));

        $updated = $repository->save($family);

        assertSameValue('Updated Family', $updated->displayName()->value());
        assertSameValue(FamilyStatus::Inactive, $updated->status());
        assertSameValue(2, count($updated->representatives()));
        assertSameValue(1, count($updated->students()));
        assertSameValue(true, ($updated->representatives()[1]->id()?->value() ?? 0) > 0);
        assertSameValue(true, ($updated->students()[0]->id()?->value() ?? 0) > 0);
    });

    $runner->add('pdo Family update ends memberships and preserves complete history', function (): void {
        $pdo = sqliteFamilyDatabase();
        $repository = familyPersistenceRepositoryWithPdo($pdo);
        $family = $repository->save(newFamilyPersistenceFixture(1));
        $family->addRepresentative(new RepresentativeId(2), new RelationshipTypeId(1), familyPersistenceTime('2026-01-02 00:00:00'));
        $family->addStudent(new StudentId(1), familyPersistenceTime('2026-01-03 00:00:00'));
        $family = $repository->save($family);
        $family->endRepresentativeMembership(new RepresentativeId(2), familyPersistenceTime('2026-02-01 00:00:00'));
        $family->endStudentMembership(new StudentId(1), familyPersistenceTime('2026-02-02 00:00:00'));

        $updated = $repository->save($family);

        assertSameValue(2, count($updated->representatives()));
        assertSameValue(1, count($updated->students()));
        assertSameValue(false, $updated->representatives()[1]->isActive());
        assertSameValue(false, $updated->students()[0]->isActive());
        assertSameValue(2, (int) $pdo->query('SELECT COUNT(*) FROM family_representatives')->fetchColumn());
        assertSameValue(1, (int) $pdo->query('SELECT COUNT(*) FROM family_students')->fetchColumn());
    });

    $runner->add('pdo Family update accepts zero-row writes only for identical state', function (): void {
        $pdo = sqliteFamilyDatabase();
        $repository = familyPersistenceRepositoryWithPdo($pdo);
        $family = $repository->save(newFamilyPersistenceFixture(1));
        $pdo->exec(
            'CREATE TRIGGER ignore_family_update BEFORE UPDATE ON families '
            . 'BEGIN SELECT RAISE(IGNORE); END;'
            . 'CREATE TRIGGER ignore_family_representative_update BEFORE UPDATE ON family_representatives '
            . 'BEGIN SELECT RAISE(IGNORE); END;'
        );

        $persisted = $repository->save($family);

        assertSameValue(true, requiredFamilyPersistenceId($persisted)->equals(
            requiredFamilyPersistenceId($family)
        ));
    });

    $runner->add('pdo Family update rejects a missing root or membership row', function (): void {
        $pdoRoot = sqliteFamilyDatabase();
        $rootRepository = familyPersistenceRepositoryWithPdo($pdoRoot);
        $missingRoot = $rootRepository->save(newFamilyPersistenceFixture(1));
        $pdoRoot->exec('PRAGMA foreign_keys = OFF');
        $pdoRoot->exec('DELETE FROM families');
        assertThrows(static fn (): Family => $rootRepository->save($missingRoot), RuntimeException::class);

        $pdoMembership = sqliteFamilyDatabase();
        $membershipRepository = familyPersistenceRepositoryWithPdo($pdoMembership);
        $missingMembership = $membershipRepository->save(newFamilyPersistenceFixture(1));
        $pdoMembership->exec('DELETE FROM family_representatives');
        assertThrows(
            static fn (): Family => $membershipRepository->save($missingMembership),
            RuntimeException::class,
        );
    });

    $runner->add('pdo Family synchronization rejects omitted and unknown membership identities', function (): void {
        $pdo = sqliteFamilyDatabase();
        $repository = familyPersistenceRepositoryWithPdo($pdo);
        $persisted = $repository->save(newFamilyPersistenceFixture(1));
        $persisted->addStudent(new StudentId(1), familyPersistenceTime('2026-01-02 00:00:00'));
        $persisted = $repository->save($persisted);
        $primary = $persisted->primaryRepresentative();

        $omitted = Family::reconstitute(
            requiredFamilyPersistenceId($persisted),
            $persisted->displayName(),
            $persisted->status(),
            [$primary],
            [],
        );
        assertThrows(static fn (): Family => $repository->save($omitted), RuntimeException::class);

        $unknown = Family::reconstitute(
            requiredFamilyPersistenceId($persisted),
            $persisted->displayName(),
            $persisted->status(),
            [$primary],
            [new FamilyStudent(
                new FamilyStudentId(9999),
                new StudentId(2),
                familyPersistenceTime('2026-02-01 00:00:00'),
                null,
            )],
        );
        assertThrows(static fn (): Family => $repository->save($unknown), RuntimeException::class);
    });

    $runner->add('pdo Family synchronization rejects membership identities owned by another Family', function (): void {
        $pdo = sqliteFamilyDatabase();
        $repository = familyPersistenceRepositoryWithPdo($pdo);
        $first = $repository->save(newFamilyPersistenceFixture(1));
        $second = $repository->save(newFamilyPersistenceFixture(2));
        $foreignPrimary = $second->primaryRepresentative();

        $invalid = Family::reconstitute(
            requiredFamilyPersistenceId($first),
            $first->displayName(),
            $first->status(),
            [$foreignPrimary],
            [],
        );

        assertThrows(static fn (): Family => $repository->save($invalid), RuntimeException::class);
    });

    $runner->add('pdo Family synchronization rejects duplicate persisted identities', function (): void {
        $pdo = sqliteFamilyDatabase();
        $repository = familyPersistenceRepositoryWithPdo($pdo);
        $family = $repository->save(newFamilyPersistenceFixture(1));
        $family->addRepresentative(new RepresentativeId(2), new RelationshipTypeId(1), familyPersistenceTime('2026-01-02 00:00:00'));
        $family = $repository->save($family);
        [$primary, $additional] = $family->representatives();

        $duplicate = Family::reconstitute(
            requiredFamilyPersistenceId($family),
            $family->displayName(),
            $family->status(),
            [$primary, $additional, new FamilyRepresentative(
                $additional->id(),
                new RepresentativeId(3),
                new RelationshipTypeId(1),
                false,
                familyPersistenceTime('2026-01-03 00:00:00'),
                null,
            )],
            [],
        );

        assertThrows(static fn (): Family => $repository->save($duplicate), RuntimeException::class);
    });

    $runner->add('pdo Family rejects Representative immutable-field changes', function (): void {
        foreach (['representative', 'relationship', 'primary', 'started'] as $change) {
            $pdo = sqliteFamilyDatabase();
            $repository = familyPersistenceRepositoryWithPdo($pdo);
            $family = $repository->save(newFamilyPersistenceFixture(1));
            $family->addRepresentative(new RepresentativeId(2), new RelationshipTypeId(1), familyPersistenceTime('2026-01-02 00:00:00'));
            $family = $repository->save($family);
            [$primary, $additional] = $family->representatives();

            $changedPrimary = $change === 'primary'
                ? new FamilyRepresentative(
                    $primary->id(),
                    $primary->representativeId(),
                    $primary->relationshipTypeId(),
                    false,
                    $primary->startedAt(),
                    null,
                )
                : $primary;
            $changedAdditional = new FamilyRepresentative(
                $additional->id(),
                $change === 'representative' ? new RepresentativeId(3) : $additional->representativeId(),
                $change === 'relationship' ? new RelationshipTypeId(2) : $additional->relationshipTypeId(),
                $change === 'primary',
                $change === 'started' ? familyPersistenceTime('2026-01-03 00:00:00') : $additional->startedAt(),
                null,
            );
            $invalid = Family::reconstitute(
                requiredFamilyPersistenceId($family),
                $family->displayName(),
                $family->status(),
                [$changedPrimary, $changedAdditional],
                [],
            );

            assertThrows(static fn (): Family => $repository->save($invalid), RuntimeException::class);
        }
    });

    $runner->add('pdo Family rejects Student immutable-field changes', function (): void {
        foreach (['student', 'started'] as $change) {
            $pdo = sqliteFamilyDatabase();
            $repository = familyPersistenceRepositoryWithPdo($pdo);
            $family = $repository->save(newFamilyPersistenceFixture(1));
            $family->addStudent(new StudentId(1), familyPersistenceTime('2026-01-02 00:00:00'));
            $family = $repository->save($family);
            $student = $family->students()[0];
            $changed = new FamilyStudent(
                $student->id(),
                $change === 'student' ? new StudentId(2) : $student->studentId(),
                $change === 'started' ? familyPersistenceTime('2026-01-03 00:00:00') : $student->startedAt(),
                null,
            );
            $invalid = Family::reconstitute(
                requiredFamilyPersistenceId($family),
                $family->displayName(),
                $family->status(),
                $family->representatives(),
                [$changed],
            );

            assertThrows(static fn (): Family => $repository->save($invalid), RuntimeException::class);
        }
    });

    $runner->add('pdo Family rejects membership reactivation or changed persisted end date', function (): void {
        $pdo = sqliteFamilyDatabase();
        $repository = familyPersistenceRepositoryWithPdo($pdo);
        insertRawFamily($pdo, 170, 1, 'Temporal Guard', 1, '2026-01-01 00:00:00');
        insertRawRepresentativeMembership($pdo, 1702, 170, 2, 1, false, '2025-01-01 00:00:00', '2025-02-01 00:00:00');
        insertRawStudentMembership($pdo, 1701, 170, 1, '2025-01-01 00:00:00', '2025-02-01 00:00:00');
        $family = $repository->findById(new FamilyId(170));
        if ($family === null) {
            throw new RuntimeException('Expected persisted temporal fixture.');
        }

        $reactivatedRepresentative = new FamilyRepresentative(
            new FamilyRepresentativeId(1702),
            new RepresentativeId(2),
            new RelationshipTypeId(1),
            false,
            familyPersistenceTime('2025-01-01 00:00:00'),
            null,
        );
        $reactivation = Family::reconstitute(
            new FamilyId(170),
            $family->displayName(),
            $family->status(),
            [$family->primaryRepresentative(), $reactivatedRepresentative],
            $family->students(),
        );
        assertThrows(static fn (): Family => $repository->save($reactivation), RuntimeException::class);

        $changedStudentEnd = new FamilyStudent(
            new FamilyStudentId(1701),
            new StudentId(1),
            familyPersistenceTime('2025-01-01 00:00:00'),
            familyPersistenceTime('2025-03-01 00:00:00'),
        );
        $changedEnd = Family::reconstitute(
            new FamilyId(170),
            $family->displayName(),
            $family->status(),
            $family->representatives(),
            [$changedStudentEnd],
        );
        assertThrows(static fn (): Family => $repository->save($changedEnd), RuntimeException::class);
    });

    $runner->add('pdo Family persistence uses fixed prepared SQL and no destructive synchronization', function (): void {
        $source = familyPersistenceSource(
            'app/Family/Infrastructure/Persistence/PdoFamilyRepository.php'
        );

        assertSameValue(true, str_contains($source, 'implements FamilyRepository'));
        assertSameValue(true, str_contains($source, '->inTransaction()'));
        assertSameValue(true, str_contains($source, 'beginTransaction()'));
        assertSameValue(true, str_contains($source, 'commit()'));
        assertSameValue(true, str_contains($source, 'rollBack()'));
        assertSameValue(false, preg_match('/\b(?:DELETE|REPLACE)\b/i', $source) === 1);
        assertSameValue(false, str_contains($source, 'INSERT IGNORE'));
        assertSameValue(false, str_contains($source, 'ON DUPLICATE'));
        assertSameValue(false, str_contains($source, 'MAX('));
        assertSameValue(false, str_contains($source, 'active_family_representative_key,'));
        assertSameValue(false, str_contains($source, 'active_primary_family_id,'));
        assertSameValue(false, str_contains($source, 'active_student_id,'));
        assertSameValue(false, str_contains($source, 'INSERT INTO families (id,'));
        assertSameValue(false, str_contains($source, 'INSERT INTO family_representatives (id,'));
        assertSameValue(false, str_contains($source, 'INSERT INTO family_students (id,'));
        assertSameValue(false, str_contains($source, 'SELECT *'));
        assertSameValue(false, str_contains($source, '{$'));
        assertSameValue(false, str_contains($source, 'SAVEPOINT'));
        assertSameValue(false, str_contains($source, 'TransactionManager'));
    });

    $runner->add('Family persistence stays isolated from delivery and other repositories', function (): void {
        $source = familyPersistenceSource(
            'app/Family/Infrastructure/Persistence/PdoFamilyRepository.php'
        );
        $forbidden = [
            'PersonRepository',
            'RepresentativeRepository',
            'StudentRepository',
            'App\\Http',
            'Controller',
            'View',
            'Application',
            'RepresentativeStudent',
        ];

        foreach ($forbidden as $value) {
            assertSameValue(false, str_contains($source, $value));
        }
    });
}

function sqliteFamilyDatabase(bool $enforceAggregateConstraints = true): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $dateCheck = $enforceAggregateConstraints
        ? ', CHECK (ended_at IS NULL OR ended_at >= started_at)'
        : '';

    $pdo->exec(
        'CREATE TABLE status_types (id INTEGER PRIMARY KEY, code TEXT NOT NULL UNIQUE);'
        . 'CREATE TABLE statuses ('
        . 'id INTEGER PRIMARY KEY, status_type_id INTEGER NOT NULL, code TEXT NOT NULL, '
        . 'UNIQUE (status_type_id, code), FOREIGN KEY (status_type_id) REFERENCES status_types(id));'
        . 'CREATE TABLE representatives (id INTEGER PRIMARY KEY);'
        . 'CREATE TABLE students (id INTEGER PRIMARY KEY);'
        . 'CREATE TABLE relationship_types (id INTEGER PRIMARY KEY);'
        . 'CREATE TABLE families ('
        . 'id INTEGER PRIMARY KEY AUTOINCREMENT, display_name TEXT NOT NULL, status_id INTEGER NOT NULL, '
        . 'created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, '
        . 'updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, '
        . 'FOREIGN KEY (status_id) REFERENCES statuses(id));'
        . 'CREATE TABLE family_representatives ('
        . 'id INTEGER PRIMARY KEY AUTOINCREMENT, family_id INTEGER NOT NULL, '
        . 'representative_id INTEGER NOT NULL, relationship_type_id INTEGER NOT NULL, '
        . 'is_primary INTEGER NOT NULL DEFAULT 0, started_at TEXT NOT NULL, ended_at TEXT NULL, '
        . 'created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, '
        . 'updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, '
        . 'UNIQUE (family_id, representative_id, started_at), CHECK (is_primary IN (0, 1))'
        . $dateCheck . ', FOREIGN KEY (family_id) REFERENCES families(id), '
        . 'FOREIGN KEY (representative_id) REFERENCES representatives(id), '
        . 'FOREIGN KEY (relationship_type_id) REFERENCES relationship_types(id));'
        . 'CREATE TABLE family_students ('
        . 'id INTEGER PRIMARY KEY AUTOINCREMENT, family_id INTEGER NOT NULL, student_id INTEGER NOT NULL, '
        . 'started_at TEXT NOT NULL, ended_at TEXT NULL, '
        . 'created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, '
        . 'updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, '
        . 'UNIQUE (family_id, student_id, started_at)'
        . $dateCheck . ', FOREIGN KEY (family_id) REFERENCES families(id), '
        . 'FOREIGN KEY (student_id) REFERENCES students(id));'
        . "INSERT INTO status_types (id, code) VALUES (1, 'GENERAL_STATUS'), (2, 'USER_STATUS');"
        . "INSERT INTO statuses (id, status_type_id, code) VALUES "
        . "(1, 1, 'ACTIVE'), (2, 1, 'INACTIVE'), (3, 2, 'ACTIVE'), (4, 1, 'ARCHIVED');"
    );

    if ($enforceAggregateConstraints) {
        $pdo->exec(
            'CREATE UNIQUE INDEX uq_family_representatives_active '
            . 'ON family_representatives (family_id, representative_id) WHERE ended_at IS NULL;'
            . 'CREATE UNIQUE INDEX uq_family_representatives_primary '
            . 'ON family_representatives (family_id) WHERE ended_at IS NULL AND is_primary = 1;'
            . 'CREATE UNIQUE INDEX uq_family_students_active '
            . 'ON family_students (student_id) WHERE ended_at IS NULL;'
        );
    }

    $representative = $pdo->prepare('INSERT INTO representatives (id) VALUES (:id)');
    $student = $pdo->prepare('INSERT INTO students (id) VALUES (:id)');
    foreach (range(1, 50) as $id) {
        $representative->execute([':id' => $id]);
        $student->execute([':id' => $id]);
    }
    $pdo->exec('INSERT INTO relationship_types (id) VALUES (1), (2)');

    return $pdo;
}

function familyPersistenceRepositoryWithPdo(PDO $pdo): PdoFamilyRepository
{
    $reflection = new ReflectionClass(PdoFamilyRepository::class);
    $repository = $reflection->newInstanceWithoutConstructor();
    $property = new ReflectionProperty(PdoFamilyRepository::class, 'connection');
    $property->setValue($repository, $pdo);

    return $repository;
}

function newFamilyPersistenceFixture(
    int $representativeId,
    FamilyStatus $status = FamilyStatus::Active,
    string $displayName = 'Persistence Family',
): Family {
    return Family::create(
        new DisplayName($displayName),
        $status,
        new RepresentativeId($representativeId),
        new RelationshipTypeId(1),
        familyPersistenceTime('2026-01-01 05:30:15', 'America/Guayaquil'),
    );
}

function requiredFamilyPersistenceId(Family $family): FamilyId
{
    $id = $family->id();
    if ($id === null) {
        throw new RuntimeException('Expected a persisted Family identity.');
    }

    return $id;
}

function familyPersistenceTime(string $value, string $timezone = 'UTC'): DateTimeImmutable
{
    return new DateTimeImmutable($value, new DateTimeZone($timezone));
}

function insertRawFamily(
    PDO $pdo,
    int $familyId,
    int $statusId,
    string $displayName,
    int $representativeId,
    string $startedAt,
    ?string $endedAt = null,
    bool $isPrimary = true,
): void {
    $family = $pdo->prepare(
        'INSERT INTO families (id, display_name, status_id) VALUES (:id, :displayName, :statusId)'
    );
    $family->execute([
        ':id' => $familyId,
        ':displayName' => $displayName,
        ':statusId' => $statusId,
    ]);
    insertRawRepresentativeMembership(
        $pdo,
        $familyId * 10 + 1,
        $familyId,
        $representativeId,
        1,
        $isPrimary,
        $startedAt,
        $endedAt,
    );
}

function insertRawRepresentativeMembership(
    PDO $pdo,
    int $id,
    int $familyId,
    int $representativeId,
    int $relationshipTypeId,
    bool $isPrimary,
    string $startedAt,
    ?string $endedAt,
): void {
    $statement = $pdo->prepare(
        'INSERT INTO family_representatives ('
        . 'id, family_id, representative_id, relationship_type_id, is_primary, started_at, ended_at'
        . ') VALUES (:id, :familyId, :representativeId, :relationshipTypeId, :isPrimary, :startedAt, :endedAt)'
    );
    $statement->execute([
        ':id' => $id,
        ':familyId' => $familyId,
        ':representativeId' => $representativeId,
        ':relationshipTypeId' => $relationshipTypeId,
        ':isPrimary' => $isPrimary ? 1 : 0,
        ':startedAt' => $startedAt,
        ':endedAt' => $endedAt,
    ]);
}

function insertRawStudentMembership(
    PDO $pdo,
    int $id,
    int $familyId,
    int $studentId,
    string $startedAt,
    ?string $endedAt,
): void {
    $statement = $pdo->prepare(
        'INSERT INTO family_students (id, family_id, student_id, started_at, ended_at) '
        . 'VALUES (:id, :familyId, :studentId, :startedAt, :endedAt)'
    );
    $statement->execute([
        ':id' => $id,
        ':familyId' => $familyId,
        ':studentId' => $studentId,
        ':startedAt' => $startedAt,
        ':endedAt' => $endedAt,
    ]);
}

function familyPersistenceSource(string $relativePath): string
{
    $source = file_get_contents(dirname(__DIR__) . '/' . $relativePath);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $relativePath . '.');
    }

    return $source;
}
