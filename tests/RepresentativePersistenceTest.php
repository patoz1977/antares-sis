<?php

declare(strict_types=1);

namespace Tests;

use App\Representative\Domain\Representative;
use App\Representative\Domain\RepresentativeRepository;
use App\Representative\Domain\RepresentativeStatus;
use App\Representative\Domain\ValueObject\EmploymentInformation;
use App\Representative\Domain\ValueObject\PersonId;
use App\Representative\Domain\ValueObject\RepresentativeId;
use App\Representative\Infrastructure\Persistence\PdoRepresentativeRepository;
use PDO;
use PDOException;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use Tests\Support\TestRunner;

function registerRepresentativePersistenceTests(TestRunner $runner): void
{
    $runner->add('RepresentativeRepository exposes only the approved persistence operations', function (): void {
        $reflection = new ReflectionClass(RepresentativeRepository::class);
        $methods = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            $reflection->getMethods(),
        );
        sort($methods, SORT_STRING);

        assertSameValue(['findById', 'findByIdForUpdate', 'findByPersonId', 'save'], $methods);
        assertSameValue(Representative::class, $reflection->getMethod('save')->getReturnType()?->getName());

        $source = file_get_contents(
            dirname(__DIR__) . '/app/Representative/Domain/RepresentativeRepository.php'
        );
        assertSameValue(true, is_string($source));
        assertSameValue(false, str_contains((string) $source, 'Infrastructure'));
        assertSameValue(false, str_contains((string) $source, 'PDO'));
    });

    $runner->add('pdo Representative repository finds existing and missing identities', function (): void {
        $pdo = sqliteRepresentativeDatabase();
        insertRawRepresentative($pdo, 100, 1, 1, 'Teacher', 'School', 'Lead', 'office ABC', 'work@example.com');
        $repository = representativePersistenceRepositoryWithPdo($pdo);

        $byId = $repository->findById(new RepresentativeId(100));
        $byPerson = $repository->findByPersonId(new PersonId(1));

        assertSameValue(100, $byId?->id()?->value());
        assertSameValue(1, $byPerson?->personId()->value());
        assertSameValue('Teacher', $byId?->employmentInformation()?->occupation());
        assertSameValue('School', $byId?->employmentInformation()?->companyName());
        assertSameValue('Lead', $byId?->employmentInformation()?->position());
        assertSameValue('office ABC', $byId?->employmentInformation()?->workPhone());
        assertSameValue('work@example.com', $byId?->employmentInformation()?->workEmail());
        assertSameValue(RepresentativeStatus::Active, $byId?->status());
        assertSameValue(null, $repository->findById(new RepresentativeId(999)));
        assertSameValue(null, $repository->findByPersonId(new PersonId(50)));
    });

    $runner->add('pdo Representative portal row lock requires caller transaction and returns exact identity', function (): void {
        $pdo = sqliteRepresentativeDatabase();
        insertRawRepresentative($pdo, 100, 1, 1, null, null, null, null, null);
        $repository = representativePersistenceRepositoryWithPdo($pdo);

        assertThrows(
            static fn () => $repository->findByIdForUpdate(new RepresentativeId(100)),
            RuntimeException::class,
        );
        $pdo->beginTransaction();
        try {
            assertSameValue(100, $repository->findByIdForUpdate(new RepresentativeId(100))?->id()?->value());
            assertSameValue(null, $repository->findByIdForUpdate(new RepresentativeId(999)));
            assertSameValue(true, $pdo->inTransaction());
        } finally {
            $pdo->rollBack();
        }
    });

    $runner->add('pdo Representative repository reconstructs partial and absent EmploymentInformation', function (): void {
        $pdo = sqliteRepresentativeDatabase();
        insertRawRepresentative($pdo, 101, 2, 1, null, 'Partial Company', null, null, null);
        insertRawRepresentative($pdo, 102, 3, 1, null, null, null, null, null);
        $repository = representativePersistenceRepositoryWithPdo($pdo);

        $partial = $repository->findById(new RepresentativeId(101));
        $absent = $repository->findById(new RepresentativeId(102));

        assertSameValue(null, $partial?->employmentInformation()?->occupation());
        assertSameValue('Partial Company', $partial?->employmentInformation()?->companyName());
        assertSameValue(null, $partial?->employmentInformation()?->workEmail());
        assertSameValue(null, $absent?->employmentInformation());
    });

    $runner->add('pdo Representative repository reconstructs both GENERAL_STATUS values', function (): void {
        $pdo = sqliteRepresentativeDatabase();
        insertRawRepresentative($pdo, 103, 4, 1, null, null, null, null, null);
        insertRawRepresentative($pdo, 104, 5, 2, null, null, null, null, null);
        $repository = representativePersistenceRepositoryWithPdo($pdo);

        assertSameValue(RepresentativeStatus::Active, $repository->findById(new RepresentativeId(103))?->status());
        assertSameValue(RepresentativeStatus::Inactive, $repository->findById(new RepresentativeId(104))?->status());
    });

    $runner->add('pdo Representative repository rejects wrong status type and unsupported code', function (): void {
        $pdo = sqliteRepresentativeDatabase();
        insertRawRepresentative($pdo, 105, 6, 3, null, null, null, null, null);
        insertRawRepresentative($pdo, 106, 7, 4, null, null, null, null, null);
        $repository = representativePersistenceRepositoryWithPdo($pdo);

        assertThrows(
            static fn (): ?Representative => $repository->findById(new RepresentativeId(105)),
            RuntimeException::class,
        );
        assertThrows(
            static fn (): ?Representative => $repository->findById(new RepresentativeId(106)),
            RuntimeException::class,
        );
    });

    $runner->add('pdo Representative repository inserts without manual identity and reloads generated state', function (): void {
        $pdo = sqliteRepresentativeDatabase();
        $repository = representativePersistenceRepositoryWithPdo($pdo);
        $new = representativePersistenceFixture(null, 8);

        $persisted = $repository->save($new);
        $id = requiredPersistedRepresentativeId($persisted);

        assertSameValue(null, $new->id());
        assertSameValue(false, $persisted === $new);
        assertSameValue(true, $id->value() > 0);
        assertSameValue($id->value(), $repository->findById($id)?->id()?->value());
        assertSameValue(true, $persisted->employmentInformation()?->equals($new->employmentInformation()));
    });

    $runner->add('pdo Representative repository updates employment and status without changing PersonId', function (): void {
        $pdo = sqliteRepresentativeDatabase();
        $repository = representativePersistenceRepositoryWithPdo($pdo);
        $representative = $repository->save(representativePersistenceFixture(null, 9));
        $id = requiredPersistedRepresentativeId($representative);

        $representative->replaceEmploymentInformation(
            new EmploymentInformation('Engineer', null, 'Manager', 'desk 200', null)
        );
        $representative->deactivate();
        $updated = $repository->save($representative);

        $statement = $pdo->prepare(
            'SELECT person_id, occupation, company, position, work_phone, work_email, status_id '
            . 'FROM representatives WHERE id = :id'
        );
        $statement->execute([':id' => $id->value()]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        assertSameValue(9, (int) $row['person_id']);
        assertSameValue('Engineer', $row['occupation']);
        assertSameValue(null, $row['company']);
        assertSameValue('Manager', $row['position']);
        assertSameValue('desk 200', $row['work_phone']);
        assertSameValue(null, $row['work_email']);
        assertSameValue(2, (int) $row['status_id']);
        assertSameValue(RepresentativeStatus::Inactive, $updated->status());
    });

    $runner->add('pdo Representative repository removes EmploymentInformation completely', function (): void {
        $pdo = sqliteRepresentativeDatabase();
        $repository = representativePersistenceRepositoryWithPdo($pdo);
        $representative = $repository->save(representativePersistenceFixture(null, 10));
        $id = requiredPersistedRepresentativeId($representative);

        $representative->replaceEmploymentInformation(null);
        $updated = $repository->save($representative);

        $statement = $pdo->prepare(
            'SELECT occupation, company, position, work_phone, work_email FROM representatives WHERE id = :id'
        );
        $statement->execute([':id' => $id->value()]);
        assertSameValue([
            'occupation' => null,
            'company' => null,
            'position' => null,
            'work_phone' => null,
            'work_email' => null,
        ], $statement->fetch(PDO::FETCH_ASSOC));
        assertSameValue(null, $updated->employmentInformation());
    });

    $runner->add('pdo Representative repository accepts zero-row identical updates', function (): void {
        $pdo = sqliteRepresentativeDatabase();
        $repository = representativePersistenceRepositoryWithPdo($pdo);
        $representative = $repository->save(representativePersistenceFixture(null, 11));
        $pdo->exec(
            'CREATE TRIGGER ignore_identical_representative_update BEFORE UPDATE ON representatives '
            . 'BEGIN SELECT RAISE(IGNORE); END'
        );

        $persisted = $repository->save($representative);

        assertSameValue(true, requiredPersistedRepresentativeId($persisted)->equals(
            requiredPersistedRepresentativeId($representative)
        ));
        assertSameValue(true, $persisted->employmentInformation()?->equals($representative->employmentInformation()));
    });

    $runner->add('pdo Representative repository rejects an update whose row disappears', function (): void {
        $pdo = sqliteRepresentativeDatabase();
        $repository = representativePersistenceRepositoryWithPdo($pdo);
        $representative = $repository->save(representativePersistenceFixture(null, 12));
        $pdo->exec(
            'CREATE TRIGGER delete_representative_before_update BEFORE UPDATE ON representatives '
            . 'BEGIN DELETE FROM representatives WHERE id = OLD.id; SELECT RAISE(IGNORE); END'
        );
        $representative->deactivate();

        assertThrows(
            static fn (): Representative => $repository->save($representative),
            RuntimeException::class,
        );
    });

    $runner->add('pdo Representative repository exposes per-Person uniqueness violations', function (): void {
        $repository = representativePersistenceRepositoryWithPdo(sqliteRepresentativeDatabase());
        $repository->save(representativePersistenceFixture(null, 13));

        assertThrows(
            static fn (): Representative => $repository->save(representativePersistenceFixture(null, 13)),
            PDOException::class,
        );
    });

    $runner->add('pdo Representative repository uses prepared fixed SQL without internal transactions', function (): void {
        $source = file_get_contents(
            dirname(__DIR__) . '/app/Representative/Infrastructure/Persistence/PdoRepresentativeRepository.php'
        );
        if (!is_string($source)) {
            throw new RuntimeException('Unable to read PdoRepresentativeRepository source.');
        }

        assertSameValue(true, str_contains($source, 'WHERE r.id = :id LIMIT 1'));
        assertSameValue(true, str_contains($source, 'WHERE r.person_id = :personId LIMIT 1'));
        assertSameValue(true, str_contains($source, "':personId' => " . '$personId->value()'));
        assertSameValue(false, str_contains($source, 'INSERT INTO representatives (id,'));
        assertSameValue(false, str_contains($source, 'exists('));
        assertSameValue(false, str_contains($source, 'beginTransaction'));
        assertSameValue(false, str_contains($source, 'commit('));
        assertSameValue(false, str_contains($source, 'rollBack'));
        assertSameValue(false, str_contains($source, 'REPLACE'));
        assertSameValue(false, str_contains($source, 'INSERT IGNORE'));
        assertSameValue(false, str_contains($source, 'MAX('));
        assertSameValue(false, str_contains($source, '{$'));
    });
}

function sqliteRepresentativeDatabase(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec(
        'CREATE TABLE status_types (id INTEGER PRIMARY KEY, code TEXT NOT NULL UNIQUE);'
        . 'CREATE TABLE statuses ('
        . 'id INTEGER PRIMARY KEY, status_type_id INTEGER NOT NULL, code TEXT NOT NULL, '
        . 'UNIQUE (status_type_id, code), FOREIGN KEY (status_type_id) REFERENCES status_types(id));'
        . 'CREATE TABLE persons (id INTEGER PRIMARY KEY);'
        . 'CREATE TABLE representatives ('
        . 'id INTEGER PRIMARY KEY AUTOINCREMENT, person_id INTEGER NOT NULL UNIQUE, '
        . 'occupation TEXT NULL, company TEXT NULL, position TEXT NULL, work_phone TEXT NULL, '
        . 'work_email TEXT NULL, status_id INTEGER NOT NULL, '
        . 'FOREIGN KEY (person_id) REFERENCES persons(id), '
        . 'FOREIGN KEY (status_id) REFERENCES statuses(id));'
        . "INSERT INTO status_types (id, code) VALUES (1, 'GENERAL_STATUS'), (2, 'USER_STATUS');"
        . "INSERT INTO statuses (id, status_type_id, code) VALUES "
        . "(1, 1, 'ACTIVE'), (2, 1, 'INACTIVE'), (3, 2, 'ACTIVE'), (4, 1, 'ARCHIVED');"
    );

    $personInsert = $pdo->prepare('INSERT INTO persons (id) VALUES (:id)');
    foreach (range(1, 50) as $personId) {
        $personInsert->execute([':id' => $personId]);
    }

    return $pdo;
}

function representativePersistenceRepositoryWithPdo(PDO $pdo): PdoRepresentativeRepository
{
    $reflection = new ReflectionClass(PdoRepresentativeRepository::class);
    $repository = $reflection->newInstanceWithoutConstructor();
    $property = new ReflectionProperty(PdoRepresentativeRepository::class, 'connection');
    $property->setValue($repository, $pdo);

    return $repository;
}

function representativePersistenceFixture(?int $id, int $personId): Representative
{
    return new Representative(
        $id === null ? null : new RepresentativeId($id),
        new PersonId($personId),
        new EmploymentInformation(
            'Teacher',
            'School',
            'Coordinator',
            'office extension',
            'representative@example.com',
        ),
        RepresentativeStatus::Active,
    );
}

function requiredPersistedRepresentativeId(Representative $representative): RepresentativeId
{
    $id = $representative->id();
    if ($id === null) {
        throw new RuntimeException('Expected a persisted Representative identity.');
    }

    return $id;
}

function insertRawRepresentative(
    PDO $pdo,
    int $id,
    int $personId,
    int $statusId,
    ?string $occupation,
    ?string $company,
    ?string $position,
    ?string $workPhone,
    ?string $workEmail,
): void {
    $statement = $pdo->prepare(
        'INSERT INTO representatives '
        . '(id, person_id, occupation, company, position, work_phone, work_email, status_id) '
        . 'VALUES (:id, :personId, :occupation, :company, :position, :workPhone, :workEmail, :statusId)'
    );
    $statement->execute([
        ':id' => $id,
        ':personId' => $personId,
        ':occupation' => $occupation,
        ':company' => $company,
        ':position' => $position,
        ':workPhone' => $workPhone,
        ':workEmail' => $workEmail,
        ':statusId' => $statusId,
    ]);
}
