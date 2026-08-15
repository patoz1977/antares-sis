<?php

declare(strict_types=1);

namespace Tests;

use App\InstitutionalDocuments\Domain\AcknowledgementRequirement;
use App\InstitutionalDocuments\Domain\AcknowledgementRequirementRepository;
use App\InstitutionalDocuments\Domain\AcknowledgementRequirementStatus;
use App\InstitutionalDocuments\Domain\RepresentativeAcknowledgementCompletion;
use App\InstitutionalDocuments\Domain\RepresentativeAcknowledgementCompletionRepository;
use App\InstitutionalDocuments\Domain\ValueObject\AcademicPeriodId;
use App\InstitutionalDocuments\Domain\ValueObject\AcknowledgementOfficialReference;
use App\InstitutionalDocuments\Domain\ValueObject\AcknowledgementRequirementId;
use App\InstitutionalDocuments\Domain\ValueObject\AcknowledgementRequirementTitle;
use App\InstitutionalDocuments\Domain\ValueObject\AcknowledgementRequirementUrl;
use App\InstitutionalDocuments\Domain\ValueObject\RepresentativeId;
use App\InstitutionalDocuments\Infrastructure\Persistence\PdoAcknowledgementRequirementRepository;
use App\InstitutionalDocuments\Infrastructure\Persistence\PdoRepresentativeAcknowledgementCompletionRepository;
use DateTimeImmutable;
use PDO;
use PDOException;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use Tests\Support\TestRunner;

function registerInstitutionalAcknowledgementsPersistenceTests(TestRunner $runner): void
{
    $runner->add('Institutional Acknowledgements repositories expose only approved Aggregate operations', function (): void {
        assertSameValue(
            [
                'findByAcademicPeriodId', 'findById', 'hasAcknowledgements',
                'lockForCompletion', 'lockForPostUseUpdate', 'save',
            ],
            institutionalPublicMethods(AcknowledgementRequirementRepository::class),
        );
        assertSameValue(
            ['findByRepresentativeAndAcademicPeriod', 'save'],
            institutionalPublicMethods(RepresentativeAcknowledgementCompletionRepository::class),
        );
        assertSameValue(true, (new ReflectionClass(PdoAcknowledgementRequirementRepository::class))->implementsInterface(
            AcknowledgementRequirementRepository::class
        ));
        assertSameValue(true, (new ReflectionClass(PdoRepresentativeAcknowledgementCompletionRepository::class))->implementsInterface(
            RepresentativeAcknowledgementCompletionRepository::class
        ));
    });

    $runner->add('Requirement persistence inserts database identity and roundtrips complete UTF-8 state', function (): void {
        $pdo = sqliteInstitutionalAcknowledgementsDatabase();
        $repository = institutionalRequirementRepository($pdo);
        $new = institutionalNewRequirement(
            1,
            'Política institucional ñ',
            'recursos/política-2026',
            'RES-Ñ-2026',
            AcknowledgementRequirementStatus::Active,
        );

        $persisted = $repository->save($new);

        assertSameValue(null, $new->id());
        assertSameValue(true, ($persisted->id()?->value() ?? 0) > 0);
        assertInstitutionalRequirementState($persisted, 1, 'Política institucional ñ', 'recursos/política-2026', 'RES-Ñ-2026', true);
        $found = $repository->findById($persisted->id());
        assertSameValue(true, $found !== null);
        assertInstitutionalRequirementState($found, 1, 'Política institucional ñ', 'recursos/política-2026', 'RES-Ñ-2026', true);
        assertSameValue(1, (int) $pdo->query('SELECT COUNT(*) FROM acknowledgement_requirements')->fetchColumn());
    });

    $runner->add('Requirement persistence preserves nullable reference and both GENERAL_STATUS values', function (): void {
        $repository = institutionalRequirementRepository(sqliteInstitutionalAcknowledgementsDatabase());
        $active = $repository->save(institutionalNewRequirement(1, 'Active', 'active/url', null));
        $inactive = $repository->save(institutionalNewRequirement(
            1,
            'Inactive',
            'inactive/url',
            null,
            AcknowledgementRequirementStatus::Inactive,
        ));

        assertSameValue(null, $active->officialReference());
        assertSameValue(true, $active->isActive());
        assertSameValue(null, $inactive->officialReference());
        assertSameValue(false, $inactive->isActive());
    });

    $runner->add('Requirement persistence updates only approved mutable columns', function (): void {
        $pdo = sqliteInstitutionalAcknowledgementsDatabase();
        $repository = institutionalRequirementRepository($pdo);
        $persisted = $repository->save(institutionalNewRequirement(1, 'Original', 'original/url', 'ORIGINAL'));
        $id = $persisted->id();
        $persisted->update(
            new AcknowledgementRequirementTitle('Updated'),
            new AcknowledgementRequirementUrl('updated/url'),
            null,
            false,
        );
        $persisted->deactivate();

        $updated = $repository->save($persisted);

        assertSameValue(true, $updated->id()?->equals($id));
        assertInstitutionalRequirementState($updated, 1, 'Updated', 'updated/url', null, false);
        assertSameValue(1, (int) $pdo->query(
            'SELECT academic_period_id FROM acknowledgement_requirements WHERE id = ' . $id->value()
        )->fetchColumn());
    });

    $runner->add('Requirement list includes all states in deterministic identity order', function (): void {
        $pdo = sqliteInstitutionalAcknowledgementsDatabase();
        $repository = institutionalRequirementRepository($pdo);
        $other = $repository->save(institutionalNewRequirement(2, 'Other period', 'other/url', null));
        $first = $repository->save(institutionalNewRequirement(1, 'First', 'first/url', null));
        $second = $repository->save(institutionalNewRequirement(
            1,
            'Second',
            'second/url',
            null,
            AcknowledgementRequirementStatus::Inactive,
        ));

        $requirements = $repository->findByAcademicPeriodId(new AcademicPeriodId(1));
        assertSameValue([$first->id()?->value(), $second->id()?->value()], array_map(
            static fn (AcknowledgementRequirement $requirement): ?int => $requirement->id()?->value(),
            $requirements,
        ));
        assertSameValue([true, false], array_map(
            static fn (AcknowledgementRequirement $requirement): bool => $requirement->isActive(),
            $requirements,
        ));
        assertSameValue(false, in_array($other->id()?->value(), array_map(
            static fn (AcknowledgementRequirement $requirement): ?int => $requirement->id()?->value(),
            $requirements,
        ), true));
    });

    $runner->add('Requirement history lookup changes only after a persisted child exists', function (): void {
        $pdo = sqliteInstitutionalAcknowledgementsDatabase();
        $requirementRepository = institutionalRequirementRepository($pdo);
        $completionRepository = institutionalCompletionRepository($pdo);
        $requirement = $requirementRepository->save(institutionalNewRequirement(1, 'History', 'history/url', null));

        assertSameValue(false, $requirementRepository->hasAcknowledgements($requirement->id()));
        $completionRepository->save(institutionalNewCompletion(1, 1, [$requirement]));
        assertSameValue(true, $requirementRepository->hasAcknowledgements($requirement->id()));
    });

    $runner->add('Requirement locking requires a caller transaction and fails closed for missing identity', function (): void {
        $pdo = sqliteInstitutionalAcknowledgementsDatabase();
        $repository = institutionalRequirementRepository($pdo);
        $persisted = $repository->save(institutionalNewRequirement(1, 'Locked', 'locked/url', null));

        assertThrows(
            static fn () => $repository->lockForPostUseUpdate($persisted->id()),
            RuntimeException::class,
        );
        $pdo->beginTransaction();
        $locked = $repository->lockForPostUseUpdate($persisted->id());
        $missing = $repository->lockForPostUseUpdate(new AcknowledgementRequirementId(999));
        assertSameValue(true, $pdo->inTransaction());
        assertSameValue(true, $locked?->id()?->equals($persisted->id()));
        assertSameValue(null, $missing);
        $pdo->rollBack();
    });

    $runner->add('Requirement Completion locking returns fresh history in deterministic identity order', function (): void {
        $pdo = sqliteInstitutionalAcknowledgementsDatabase();
        $requirements = institutionalPersistedRequirements($pdo, 1, 3);
        institutionalCompletionRepository($pdo)->save(institutionalNewCompletion(1, 1, [$requirements[1]]));
        $repository = institutionalRequirementRepository($pdo);

        $pdo->beginTransaction();
        $locked = $repository->lockForCompletion(new AcademicPeriodId(1));
        $lockedIds = array_map(
            static fn (AcknowledgementRequirement $requirement): ?int => $requirement->id()?->value(),
            $locked,
        );
        $sortedIds = $lockedIds;
        sort($sortedIds, SORT_NUMERIC);

        assertSameValue($sortedIds, $lockedIds);
        assertSameValue(true, $repository->hasAcknowledgements($requirements[1]->id()));
        assertSameValue(true, $pdo->inTransaction());
        $pdo->rollBack();
    });

    $runner->add('Requirement reconstruction fails closed for incompatible persisted status', function (): void {
        $pdo = sqliteInstitutionalAcknowledgementsDatabase();
        $repository = institutionalRequirementRepository($pdo);
        $persisted = $repository->save(institutionalNewRequirement(1, 'Status', 'status/url', null));
        $pdo->exec('UPDATE acknowledgement_requirements SET status_id = 20 WHERE id = ' . $persisted->id()?->value());
        assertThrows(static fn () => $repository->findById($persisted->id()), RuntimeException::class);

        $pdo->exec('UPDATE acknowledgement_requirements SET status_id = 12 WHERE id = ' . $persisted->id()?->value());
        assertThrows(static fn () => $repository->findById($persisted->id()), RuntimeException::class);
    });

    $runner->add('Requirement save owns rollback and participates in an outer transaction', function (): void {
        $pdo = sqliteInstitutionalAcknowledgementsDatabase();
        $repository = institutionalRequirementRepository($pdo);
        $pdo->exec("CREATE TRIGGER reject_requirement BEFORE INSERT ON acknowledgement_requirements BEGIN SELECT RAISE(ABORT, 'rejected'); END");
        assertThrows(
            static fn () => $repository->save(institutionalNewRequirement(1, 'Rejected', 'rejected/url', null)),
            PDOException::class,
        );
        assertSameValue(false, $pdo->inTransaction());
        assertSameValue(0, (int) $pdo->query('SELECT COUNT(*) FROM acknowledgement_requirements')->fetchColumn());

        $pdo->exec('DROP TRIGGER reject_requirement');
        $pdo->beginTransaction();
        $repository->save(institutionalNewRequirement(1, 'External', 'external/url', null));
        assertSameValue(true, $pdo->inTransaction());
        assertSameValue(1, (int) $pdo->query('SELECT COUNT(*) FROM acknowledgement_requirements')->fetchColumn());
        $pdo->rollBack();
        assertSameValue(0, (int) $pdo->query('SELECT COUNT(*) FROM acknowledgement_requirements')->fetchColumn());
    });

    $runner->add('Completion persistence inserts root and multiple database-generated children', function (): void {
        $pdo = sqliteInstitutionalAcknowledgementsDatabase();
        $requirementRepository = institutionalRequirementRepository($pdo);
        $completionRepository = institutionalCompletionRepository($pdo);
        $first = $requirementRepository->save(institutionalNewRequirement(1, 'First', 'first/url', null));
        $second = $requirementRepository->save(institutionalNewRequirement(1, 'Second', 'second/url', 'REF-2'));

        $persisted = $completionRepository->save(institutionalNewCompletion(1, 1, [$second, $first]));
        $children = $persisted->acknowledgements();

        assertSameValue(true, ($persisted->id()?->value() ?? 0) > 0);
        assertSameValue(2, count($children));
        assertSameValue(true, ($children[0]->id()?->value() ?? 0) > 0);
        assertSameValue(true, ($children[1]->id()?->value() ?? 0) > 0);
        assertSameValue(false, $children[0]->id()?->equals($children[1]->id()));
        assertSameValue(1, (int) $pdo->query('SELECT COUNT(*) FROM representative_acknowledgement_completions')->fetchColumn());
        assertSameValue(2, (int) $pdo->query('SELECT COUNT(*) FROM representative_acknowledgements')->fetchColumn());
    });

    $runner->add('Completion lookup roundtrips exact UTC seconds and deterministic child order', function (): void {
        $pdo = sqliteInstitutionalAcknowledgementsDatabase();
        $requirements = institutionalPersistedRequirements($pdo, 1, 2);
        $repository = institutionalCompletionRepository($pdo);
        $completedAt = new DateTimeImmutable('2026-08-14 10:11:12.987654-05:00');
        $new = RepresentativeAcknowledgementCompletion::complete(
            new RepresentativeId(1),
            new AcademicPeriodId(1),
            $completedAt,
            array_reverse($requirements),
        );

        $persisted = $repository->save($new);
        $found = $repository->findByRepresentativeAndAcademicPeriod(
            new RepresentativeId(1),
            new AcademicPeriodId(1),
        );

        assertSameValue(true, $found !== null);
        assertSameValue('2026-08-14 15:11:12', $persisted->completedAt()->format('Y-m-d H:i:s'));
        assertSameValue('+00:00', $persisted->completedAt()->format('P'));
        assertSameValue(
            array_map(static fn ($child): ?int => $child->id()?->value(), $persisted->acknowledgements()),
            array_map(static fn ($child): ?int => $child->id()?->value(), $found->acknowledgements()),
        );
        $childIds = array_map(static fn ($child): ?int => $child->id()?->value(), $found->acknowledgements());
        $sorted = $childIds;
        sort($sorted, SORT_NUMERIC);
        assertSameValue($sorted, $childIds);
    });

    $runner->add('Completion lookup returns null for absent Representative period pair', function (): void {
        $repository = institutionalCompletionRepository(sqliteInstitutionalAcknowledgementsDatabase());
        assertSameValue(null, $repository->findByRepresentativeAndAcademicPeriod(
            new RepresentativeId(1),
            new AcademicPeriodId(1),
        ));
    });

    $runner->add('Historical Completion reconstruction ignores later Requirement status', function (): void {
        $pdo = sqliteInstitutionalAcknowledgementsDatabase();
        $requirementRepository = institutionalRequirementRepository($pdo);
        $completionRepository = institutionalCompletionRepository($pdo);
        $requirement = $requirementRepository->save(institutionalNewRequirement(1, 'Historical', 'old/url', null));
        $completionRepository->save(institutionalNewCompletion(1, 1, [$requirement]));
        $requirement->deactivate();
        $requirement->update(
            $requirement->title(),
            new AcknowledgementRequirementUrl('new/url'),
            null,
            true,
        );
        $requirementRepository->save($requirement);

        $found = $completionRepository->findByRepresentativeAndAcademicPeriod(
            new RepresentativeId(1),
            new AcademicPeriodId(1),
        );
        assertSameValue(true, $found !== null);
        assertSameValue($requirement->id()?->value(), $found->acknowledgements()[0]->acknowledgementRequirementId()->value());
    });

    $runner->add('Persisted Completion is rejected by immutable save contract', function (): void {
        $pdo = sqliteInstitutionalAcknowledgementsDatabase();
        $requirements = institutionalPersistedRequirements($pdo, 1, 1);
        $repository = institutionalCompletionRepository($pdo);
        $persisted = $repository->save(institutionalNewCompletion(1, 1, $requirements));

        assertThrows(static fn () => $repository->save($persisted), RuntimeException::class);
        assertSameValue(1, (int) $pdo->query('SELECT COUNT(*) FROM representative_acknowledgement_completions')->fetchColumn());
        assertSameValue(1, (int) $pdo->query('SELECT COUNT(*) FROM representative_acknowledgements')->fetchColumn());
    });

    $runner->add('Owned Completion transaction rolls back root and children on child failure', function (): void {
        $pdo = sqliteInstitutionalAcknowledgementsDatabase();
        $requirements = institutionalPersistedRequirements($pdo, 1, 2);
        $failedRequirementId = $requirements[1]->id()?->value();
        $pdo->exec(
            'CREATE TRIGGER reject_second_child BEFORE INSERT ON representative_acknowledgements '
            . 'WHEN NEW.acknowledgement_requirement_id = ' . $failedRequirementId . ' '
            . "BEGIN SELECT RAISE(ABORT, 'rejected child'); END"
        );

        assertThrows(
            static fn () => institutionalCompletionRepository($pdo)->save(
                institutionalNewCompletion(1, 1, $requirements)
            ),
            PDOException::class,
        );
        assertSameValue(false, $pdo->inTransaction());
        assertSameValue(0, (int) $pdo->query('SELECT COUNT(*) FROM representative_acknowledgement_completions')->fetchColumn());
        assertSameValue(0, (int) $pdo->query('SELECT COUNT(*) FROM representative_acknowledgements')->fetchColumn());
    });

    $runner->add('External Completion transaction remains caller-owned after child failure', function (): void {
        $pdo = sqliteInstitutionalAcknowledgementsDatabase();
        $requirements = institutionalPersistedRequirements($pdo, 1, 2);
        $failedRequirementId = $requirements[1]->id()?->value();
        $pdo->exec(
            'CREATE TRIGGER reject_external_child BEFORE INSERT ON representative_acknowledgements '
            . 'WHEN NEW.acknowledgement_requirement_id = ' . $failedRequirementId . ' '
            . "BEGIN SELECT RAISE(ABORT, 'rejected child'); END"
        );
        $pdo->beginTransaction();

        assertThrows(
            static fn () => institutionalCompletionRepository($pdo)->save(
                institutionalNewCompletion(1, 1, $requirements)
            ),
            PDOException::class,
        );
        assertSameValue(true, $pdo->inTransaction());
        assertSameValue(1, (int) $pdo->query('SELECT COUNT(*) FROM representative_acknowledgement_completions')->fetchColumn());
        assertSameValue(1, (int) $pdo->query('SELECT COUNT(*) FROM representative_acknowledgements')->fetchColumn());
        $pdo->rollBack();
        assertSameValue(0, (int) $pdo->query('SELECT COUNT(*) FROM representative_acknowledgement_completions')->fetchColumn());
    });

    $runner->add('Completion physical uniqueness and same-period child ownership stay enforced', function (): void {
        $pdo = sqliteInstitutionalAcknowledgementsDatabase();
        $requirements = institutionalPersistedRequirements($pdo, 1, 2);
        $repository = institutionalCompletionRepository($pdo);
        $persisted = $repository->save(institutionalNewCompletion(1, 1, [$requirements[0]]));

        assertThrows(
            static fn () => $repository->save(institutionalNewCompletion(1, 1, [$requirements[1]])),
            PDOException::class,
        );
        assertSameValue(1, (int) $pdo->query('SELECT COUNT(*) FROM representative_acknowledgement_completions')->fetchColumn());

        $otherPeriod = institutionalRequirementRepository($pdo)->save(
            institutionalNewRequirement(2, 'Other', 'other/url', null)
        );
        assertThrows(static function () use ($pdo, $persisted, $otherPeriod): void {
            $statement = $pdo->prepare(
                'INSERT INTO representative_acknowledgements '
                . '(representative_acknowledgement_completion_id, acknowledgement_requirement_id, academic_period_id) '
                . 'VALUES (:completionId, :requirementId, 1)'
            );
            $statement->execute([
                ':completionId' => $persisted->id()?->value(),
                ':requirementId' => $otherPeriod->id()?->value(),
            ]);
        }, PDOException::class);
    });

    $runner->add('Duplicate persisted child Requirement remains physically rejected', function (): void {
        $pdo = sqliteInstitutionalAcknowledgementsDatabase();
        $requirements = institutionalPersistedRequirements($pdo, 1, 1);
        $persisted = institutionalCompletionRepository($pdo)->save(
            institutionalNewCompletion(1, 1, $requirements)
        );

        assertThrows(static function () use ($pdo, $persisted, $requirements): void {
            $statement = $pdo->prepare(
                'INSERT INTO representative_acknowledgements '
                . '(representative_acknowledgement_completion_id, acknowledgement_requirement_id, academic_period_id) '
                . 'VALUES (:completionId, :requirementId, 1)'
            );
            $statement->execute([
                ':completionId' => $persisted->id()?->value(),
                ':requirementId' => $requirements[0]->id()?->value(),
            ]);
        }, PDOException::class);
    });

    $runner->add('Corrupt persisted Completion data fails closed during reconstruction', function (): void {
        $pdo = sqliteInstitutionalAcknowledgementsDatabase(false);
        $pdo->exec("INSERT INTO representative_acknowledgement_completions (id, representative_id, academic_period_id, completed_at) VALUES (1, 1, 1, '2026-08-14 15:11:12')");
        $repository = institutionalCompletionRepository($pdo);
        assertThrows(
            static fn () => $repository->findByRepresentativeAndAcademicPeriod(new RepresentativeId(1), new AcademicPeriodId(1)),
            RuntimeException::class,
        );

        $pdo->exec('INSERT INTO representative_acknowledgements (id, representative_acknowledgement_completion_id, acknowledgement_requirement_id, academic_period_id) VALUES (1, 1, 10, 1)');
        $pdo->exec('INSERT INTO representative_acknowledgements (id, representative_acknowledgement_completion_id, acknowledgement_requirement_id, academic_period_id) VALUES (2, 1, 10, 1)');
        assertThrows(
            static fn () => $repository->findByRepresentativeAndAcademicPeriod(new RepresentativeId(1), new AcademicPeriodId(1)),
            \DomainException::class,
        );
    });

    $runner->add('Institutional Acknowledgements persistence remains prepared isolated and non-destructive', function (): void {
        $requirementSource = (string) file_get_contents(
            __DIR__ . '/../app/InstitutionalDocuments/Infrastructure/Persistence/PdoAcknowledgementRequirementRepository.php'
        );
        $completionSource = (string) file_get_contents(
            __DIR__ . '/../app/InstitutionalDocuments/Infrastructure/Persistence/PdoRepresentativeAcknowledgementCompletionRepository.php'
        );
        $domainSource = '';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            __DIR__ . '/../app/InstitutionalDocuments/Domain'
        )) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $domainSource .= (string) file_get_contents($file->getPathname());
            }
        }

        foreach (['PDO', 'Infrastructure', 'Http', 'Controller', 'Request', 'Response', 'Session'] as $forbidden) {
            assertSameValue(false, str_contains($domainSource, $forbidden));
        }
        foreach (['DELETE FROM', 'UPDATE representative_acknowledgement_completions', 'UPDATE representative_acknowledgements'] as $forbidden) {
            assertSameValue(false, str_contains($completionSource, $forbidden));
        }
        foreach (['MAX(', 'last_insert_rowid', 'App\\Family', 'App\\Representative', 'Enrollment', 'Controller', 'Http'] as $forbidden) {
            assertSameValue(false, str_contains($requirementSource . $completionSource, $forbidden));
        }
        assertSameValue(true, str_contains($requirementSource, '->prepare('));
        assertSameValue(true, str_contains($completionSource, '->prepare('));
        assertSameValue(true, is_dir(__DIR__ . '/../app/InstitutionalDocuments/Http'));
    });
}

/** @return list<string> */
function institutionalPublicMethods(string $class): array
{
    $methods = array_map(
        static fn (\ReflectionMethod $method): string => $method->getName(),
        (new ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC),
    );
    sort($methods, SORT_STRING);

    return $methods;
}

function sqliteInstitutionalAcknowledgementsDatabase(bool $constraints = true): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $uniqueCompletion = $constraints ? ', UNIQUE (representative_id, academic_period_id)' : '';
    $uniqueChild = $constraints ? ', UNIQUE (representative_acknowledgement_completion_id, acknowledgement_requirement_id)' : '';
    $foreignKeys = $constraints
        ? ', FOREIGN KEY (academic_period_id) REFERENCES academic_periods(id)'
            . ', FOREIGN KEY (status_id) REFERENCES statuses(id)'
        : '';
    $completionForeignKeys = $constraints
        ? ', FOREIGN KEY (representative_id) REFERENCES representatives(id)'
            . ', FOREIGN KEY (academic_period_id) REFERENCES academic_periods(id)'
        : '';
    $childForeignKeys = $constraints
        ? ', FOREIGN KEY (representative_acknowledgement_completion_id, academic_period_id) '
            . 'REFERENCES representative_acknowledgement_completions(id, academic_period_id)'
            . ', FOREIGN KEY (acknowledgement_requirement_id, academic_period_id) '
            . 'REFERENCES acknowledgement_requirements(id, academic_period_id)'
        : '';

    $pdo->exec(
        'CREATE TABLE status_types (id INTEGER PRIMARY KEY, code TEXT NOT NULL);'
        . 'CREATE TABLE statuses (id INTEGER PRIMARY KEY, status_type_id INTEGER NOT NULL, code TEXT NOT NULL);'
        . 'CREATE TABLE academic_periods (id INTEGER PRIMARY KEY);'
        . 'CREATE TABLE representatives (id INTEGER PRIMARY KEY);'
        . 'CREATE TABLE acknowledgement_requirements ('
        . 'id INTEGER PRIMARY KEY AUTOINCREMENT, academic_period_id INTEGER NOT NULL, '
        . 'title TEXT NOT NULL CHECK (length(trim(title)) BETWEEN 1 AND 200), '
        . 'url TEXT NOT NULL CHECK (length(trim(url)) BETWEEN 1 AND 500), '
        . 'official_reference TEXT NULL CHECK (official_reference IS NULL OR length(trim(official_reference)) BETWEEN 1 AND 255), '
        . 'status_id INTEGER NOT NULL, UNIQUE (id, academic_period_id)' . $foreignKeys . ');'
        . 'CREATE TABLE representative_acknowledgement_completions ('
        . 'id INTEGER PRIMARY KEY AUTOINCREMENT, representative_id INTEGER NOT NULL, '
        . 'academic_period_id INTEGER NOT NULL, completed_at TEXT NOT NULL, '
        . 'UNIQUE (id, academic_period_id)' . $uniqueCompletion . $completionForeignKeys . ');'
        . 'CREATE TABLE representative_acknowledgements ('
        . 'id INTEGER PRIMARY KEY AUTOINCREMENT, representative_acknowledgement_completion_id INTEGER NOT NULL, '
        . 'acknowledgement_requirement_id INTEGER NOT NULL, academic_period_id INTEGER NOT NULL'
        . $uniqueChild . $childForeignKeys . ');'
        . "INSERT INTO status_types (id, code) VALUES (1, 'GENERAL_STATUS'), (2, 'OTHER_STATUS');"
        . "INSERT INTO statuses (id, status_type_id, code) VALUES (10, 1, 'ACTIVE'), (11, 1, 'INACTIVE'), (12, 1, 'BROKEN'), (20, 2, 'ACTIVE');"
        . 'INSERT INTO academic_periods (id) VALUES (1), (2);'
        . 'INSERT INTO representatives (id) VALUES (1), (2);'
    );

    return $pdo;
}

function institutionalRequirementRepository(PDO $pdo): PdoAcknowledgementRequirementRepository
{
    $repository = (new ReflectionClass(PdoAcknowledgementRequirementRepository::class))->newInstanceWithoutConstructor();
    $property = new ReflectionProperty(PdoAcknowledgementRequirementRepository::class, 'connection');
    $property->setValue($repository, $pdo);

    return $repository;
}

function institutionalCompletionRepository(PDO $pdo): PdoRepresentativeAcknowledgementCompletionRepository
{
    $repository = (new ReflectionClass(PdoRepresentativeAcknowledgementCompletionRepository::class))->newInstanceWithoutConstructor();
    $property = new ReflectionProperty(PdoRepresentativeAcknowledgementCompletionRepository::class, 'connection');
    $property->setValue($repository, $pdo);

    return $repository;
}

function institutionalNewRequirement(
    int $academicPeriodId,
    string $title,
    string $url,
    ?string $officialReference,
    AcknowledgementRequirementStatus $status = AcknowledgementRequirementStatus::Active,
): AcknowledgementRequirement {
    return AcknowledgementRequirement::create(
        new AcademicPeriodId($academicPeriodId),
        new AcknowledgementRequirementTitle($title),
        new AcknowledgementRequirementUrl($url),
        $officialReference === null ? null : new AcknowledgementOfficialReference($officialReference),
        $status,
    );
}

/** @param list<AcknowledgementRequirement> $requirements */
function institutionalNewCompletion(
    int $representativeId,
    int $academicPeriodId,
    array $requirements,
): RepresentativeAcknowledgementCompletion {
    return RepresentativeAcknowledgementCompletion::complete(
        new RepresentativeId($representativeId),
        new AcademicPeriodId($academicPeriodId),
        new DateTimeImmutable('2026-08-14 10:11:12-05:00'),
        $requirements,
    );
}

/** @return list<AcknowledgementRequirement> */
function institutionalPersistedRequirements(PDO $pdo, int $academicPeriodId, int $count): array
{
    $repository = institutionalRequirementRepository($pdo);
    $requirements = [];
    for ($index = 1; $index <= $count; $index++) {
        $requirements[] = $repository->save(institutionalNewRequirement(
            $academicPeriodId,
            'Requirement ' . $index,
            'requirement/' . $index,
            $index % 2 === 0 ? 'REF-' . $index : null,
        ));
    }

    return $requirements;
}

function assertInstitutionalRequirementState(
    AcknowledgementRequirement $requirement,
    int $academicPeriodId,
    string $title,
    string $url,
    ?string $officialReference,
    bool $active,
): void {
    assertSameValue($academicPeriodId, $requirement->academicPeriodId()->value());
    assertSameValue($title, $requirement->title()->value());
    assertSameValue($url, $requirement->url()->value());
    assertSameValue($officialReference, $requirement->officialReference()?->value());
    assertSameValue($active, $requirement->isActive());
}
