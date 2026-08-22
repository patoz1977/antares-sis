<?php

declare(strict_types=1);

namespace Tests;

use App\AcademicCore\Domain\AcademicPeriodStatus;
use App\AcademicCore\Domain\Exception\AcademicPeriodOperationalStateConflict;
use App\AcademicCore\Domain\ValueObject\AcademicPeriodId;
use App\AcademicCore\Infrastructure\Persistence\PdoAcademicPeriodRepository;
use Core\Database\PdoTransactionRunner;
use PDO;
use RuntimeException;
use Tests\Support\TestRunner;

function registerAcademicPeriodPersistenceTests(TestRunner $runner): void
{
    $runner->add('E009 Phase 5.1 PDO AcademicPeriod maps GENERAL_STATUS and preserves the approved schema', function (): void {
        [$repository, $pdo] = academicPeriodPersistenceFixture();
        assertSameValue(1, $repository->findActive()?->id()?->value());
        $period = $repository->findById(new AcademicPeriodId(2));
        assertSameValue('INACTIVE', $period?->status()->value);
        assertSameValue('2027-09-01', $period?->dates()->startsOn()->format('Y-m-d'));

        $columns = $pdo->query('PRAGMA table_info(academic_periods)')->fetchAll(PDO::FETCH_ASSOC);
        assertSameValue(
            ['id', 'code', 'name', 'starts_on', 'ends_on', 'status_id', 'created_at', 'updated_at'],
            array_column($columns, 'name'),
        );
        $source = (string) file_get_contents(dirname(__DIR__) . '/app/AcademicCore/Infrastructure/Persistence/PdoAcademicPeriodRepository.php');
        assertSameValue(false, str_contains($source, 'CURRENT_DATE'));
        assertSameValue(false, str_contains($source, 'migration'));
        assertSameValue(false, str_contains($source, 'MAX('));
    });

    $runner->add('E009 Phase 5.1 PDO AcademicPeriod updates status without changing identity labels or dates', function (): void {
        [$repository, $pdo] = academicPeriodPersistenceFixture();
        $period = $repository->findById(new AcademicPeriodId(2));
        if ($period === null) {
            throw new RuntimeException('Fixture period was not found.');
        }
        $period->activate();
        $persisted = $repository->save($period);
        assertSameValue(AcademicPeriodStatus::Active, $persisted->status());
        $row = $pdo->query('SELECT id, code, name, starts_on, ends_on, status_id FROM academic_periods WHERE id = 2')
            ->fetch(PDO::FETCH_ASSOC);
        assertSameValue([2, 'P2', 'Period 2', '2027-09-01', '2028-06-30', 1], array_values($row));
    });

    $runner->add('E009 Phase 5.1 PDO AcademicPeriod operational lock requires a transaction and exact status row', function (): void {
        [$repository, , $manager] = academicPeriodPersistenceFixture();
        academicPeriodAssertThrows(
            static fn (): mixed => $repository->lockOperationalTransition(),
            RuntimeException::class,
        );
        $runner = new PdoTransactionRunner($manager);
        $runner->run(function () use ($repository): void {
            $repository->lockOperationalTransition();
        });
    });

    $runner->add('E011 PDO AcademicPeriod shared portal stabilization requires caller transaction', function (): void {
        [$repository, , $manager] = academicPeriodPersistenceFixture();
        academicPeriodAssertThrows(
            static fn (): mixed => $repository->lockActiveContextForRead(),
            RuntimeException::class,
        );
        (new PdoTransactionRunner($manager))->run(function () use ($repository): void {
            $repository->lockActiveContextForRead();
        });

        $source = (string) file_get_contents(
            dirname(__DIR__) . '/app/AcademicCore/Infrastructure/Persistence/PdoAcademicPeriodRepository.php'
        );
        assertSameValue(true, str_contains($source, 'LOCK IN SHARE MODE'));
    });

    $runner->add('E009 Phase 5.1 PDO AcademicPeriod findActive returns zero or one and rejects corruption', function (): void {
        [$repository, $pdo] = academicPeriodPersistenceFixture();
        $pdo->exec('UPDATE academic_periods SET status_id = 2');
        assertSameValue(null, $repository->findActive());
        $pdo->exec('UPDATE academic_periods SET status_id = 1');
        academicPeriodAssertThrows(
            static fn (): mixed => $repository->findActive(),
            AcademicPeriodOperationalStateConflict::class,
        );
    });

    $runner->add('E009 Phase 5.1 PDO AcademicPeriod rejects wrong status type unsupported status and missing rows', function (): void {
        foreach ([3, 4] as $statusId) {
            [$repository, $pdo] = academicPeriodPersistenceFixture();
            $pdo->exec('UPDATE academic_periods SET status_id = ' . $statusId . ' WHERE id = 1');
            academicPeriodAssertThrows(
                static fn (): mixed => $repository->findById(new AcademicPeriodId(1)),
                RuntimeException::class,
            );
            academicPeriodAssertThrows(
                static fn (): mixed => $repository->findActive(),
                RuntimeException::class,
            );
        }
        [$repository, $pdo] = academicPeriodPersistenceFixture();
        $period = $repository->findById(new AcademicPeriodId(2));
        $pdo->exec('DELETE FROM academic_periods WHERE id = 2');
        if ($period === null) {
            throw new RuntimeException('Fixture period was not found.');
        }
        $period->activate();
        academicPeriodAssertThrows(static fn (): mixed => $repository->save($period), RuntimeException::class);
    });
}

/** @return array{PdoAcademicPeriodRepository, PDO, \Core\Database\ConnectionManager} */
function academicPeriodPersistenceFixture(): array
{
    $manager = familySqliteManager();
    $pdo = $manager->connection();
    $pdo->exec('CREATE TABLE status_types (id INTEGER PRIMARY KEY, code TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE statuses (id INTEGER PRIMARY KEY, status_type_id INTEGER NOT NULL, code TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE academic_periods ('
        . 'id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT NOT NULL, name TEXT NOT NULL, '
        . 'starts_on TEXT NOT NULL, ends_on TEXT NOT NULL, status_id INTEGER NOT NULL, '
        . 'created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec("INSERT INTO status_types VALUES (1, 'GENERAL_STATUS'), (2, 'OTHER')");
    $pdo->exec("INSERT INTO statuses VALUES "
        . "(1, 1, 'ACTIVE'), (2, 1, 'INACTIVE'), (3, 2, 'ACTIVE'), (4, 1, 'ARCHIVED')");
    $pdo->exec("INSERT INTO academic_periods (id, code, name, starts_on, ends_on, status_id) VALUES "
        . "(1, 'P1', 'Period 1', '2026-09-01', '2027-06-30', 1), "
        . "(2, 'P2', 'Period 2', '2027-09-01', '2028-06-30', 2)");

    return [new PdoAcademicPeriodRepository($manager), $pdo, $manager];
}
