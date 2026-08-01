<?php

declare(strict_types=1);

namespace Tests;

use App\Person\Domain\Person;
use App\Person\Domain\PersonRepository;
use App\Person\Domain\PersonStatus;
use App\Person\Domain\ValueObject\ContactInformation;
use App\Person\Domain\ValueObject\Identification;
use App\Person\Domain\ValueObject\PersonalName;
use App\Person\Domain\ValueObject\PersonId;
use App\Person\Infrastructure\Persistence\PdoPersonRepository;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use Tests\Support\TestRunner;

function registerPersonPersistenceTests(TestRunner $runner): void
{
    $runner->add('PersonRepository exposes only the approved persistence operations', function (): void {
        $methods = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass(PersonRepository::class))->getMethods(),
        );
        sort($methods, SORT_STRING);

        assertSameValue(['findById', 'findByIdentification', 'save'], $methods);
    });

    $runner->add('pdo Person repository inserts and reconstructs every aggregate field', function (): void {
        $pdo = sqlitePersonDatabase();
        $repository = personPersistenceRepositoryWithPdo($pdo);
        $person = completePersistencePerson(10, 'Doc-100');

        $repository->save($person);

        $row = $pdo->query('SELECT * FROM persons WHERE id = 10')->fetch(PDO::FETCH_ASSOC);
        assertSameValue('Ana', $row['first_name']);
        assertSameValue('Maria', $row['middle_name']);
        assertSameValue('Perez', $row['first_surname']);
        assertSameValue('Lopez', $row['second_surname']);
        assertSameValue(1, (int) $row['document_type_id']);
        assertSameValue('Doc-100', $row['document_number']);
        assertSameValue('1:DOC-100', $row['identification_key']);
        assertSameValue('2000-02-03', $row['birth_date']);
        assertSameValue(1, (int) $row['sex_id']);
        assertSameValue(1, (int) $row['marital_status_id']);
        assertSameValue(1, (int) $row['education_level_id']);
        assertSameValue('ana@example.com', $row['email']);
        assertSameValue('mobile extension', $row['mobile_phone']);
        assertSameValue('landline extension', $row['landline_phone']);
        assertSameValue(1, (int) $row['status_id']);
        assertSameValue(true, is_string($row['created_at']) && $row['created_at'] !== '');
        assertSameValue(true, is_string($row['updated_at']) && $row['updated_at'] !== '');

        $reloaded = $repository->findById(new PersonId(10));
        assertSameValue(10, $reloaded?->id()->value());
        assertSameValue(true, $reloaded?->personalName()->equals($person->personalName()));
        assertSameValue(true, $reloaded?->identification()?->equals($person->identification()));
        assertSameValue('2000-02-03', $reloaded?->birthDate()->format('Y-m-d'));
        assertSameValue(1, $reloaded?->sexId());
        assertSameValue(1, $reloaded?->maritalStatusId());
        assertSameValue(1, $reloaded?->educationLevelId());
        assertSameValue(true, $reloaded?->contactInformation()?->equals($person->contactInformation()));
        assertSameValue(PersonStatus::Active, $reloaded?->status());
    });

    $runner->add('pdo Person repository finds existing and missing identities', function (): void {
        $repository = personPersistenceRepositoryWithPdo(sqlitePersonDatabase());
        $repository->save(completePersistencePerson(10, 'Mixed-Case'));

        assertSameValue(10, $repository->findById(new PersonId(10))?->id()->value());
        assertSameValue(null, $repository->findById(new PersonId(999)));
        assertSameValue(
            10,
            $repository->findByIdentification(new Identification(1, '  mixed-case  '))?->id()->value(),
        );
        assertSameValue(
            null,
            $repository->findByIdentification(new Identification(1, 'missing')),
        );
    });

    $runner->add('pdo Person repository persists absent optional identity and contact as null', function (): void {
        $pdo = sqlitePersonDatabase();
        $repository = personPersistenceRepositoryWithPdo($pdo);
        $repository->save(minimalPersistencePerson(20));

        $row = $pdo->query(
            'SELECT middle_name, second_surname, document_type_id, document_number, '
            . 'identification_key, marital_status_id, education_level_id, email, '
            . 'mobile_phone, landline_phone FROM persons WHERE id = 20'
        )->fetch(PDO::FETCH_ASSOC);
        assertSameValue([
            'middle_name' => null,
            'second_surname' => null,
            'document_type_id' => null,
            'document_number' => null,
            'identification_key' => null,
            'marital_status_id' => null,
            'education_level_id' => null,
            'email' => null,
            'mobile_phone' => null,
            'landline_phone' => null,
        ], $row);

        $reloaded = $repository->findById(new PersonId(20));
        assertSameValue(null, $reloaded?->identification());
        assertSameValue(null, $reloaded?->contactInformation());
        assertSameValue(null, $reloaded?->maritalStatusId());
        assertSameValue(null, $reloaded?->educationLevelId());
    });

    $runner->add('pdo Person repository updates state without changing created_at', function (): void {
        $pdo = sqlitePersonDatabase();
        $repository = personPersistenceRepositoryWithPdo($pdo);
        $person = completePersistencePerson(10, 'Doc-100');
        $repository->save($person);
        $pdo->exec("UPDATE persons SET created_at = '2026-01-02 03:04:05' WHERE id = 10");

        $person->updateIdentity(
            new PersonalName('Updated', null, 'Person', null),
            null,
            new DateTimeImmutable('2001-04-05', new DateTimeZone('UTC')),
            1,
            null,
            null,
            personPersistenceToday(),
        );
        $person->updateContactInformation(null);
        $person->deactivate();
        $repository->save($person);

        $row = $pdo->query(
            'SELECT first_name, middle_name, document_type_id, document_number, identification_key, '
            . 'email, mobile_phone, landline_phone, status_id, created_at FROM persons WHERE id = 10'
        )->fetch(PDO::FETCH_ASSOC);
        assertSameValue('Updated', $row['first_name']);
        assertSameValue(null, $row['middle_name']);
        assertSameValue(null, $row['document_type_id']);
        assertSameValue(null, $row['document_number']);
        assertSameValue(null, $row['identification_key']);
        assertSameValue(null, $row['email']);
        assertSameValue(null, $row['mobile_phone']);
        assertSameValue(null, $row['landline_phone']);
        assertSameValue(2, (int) $row['status_id']);
        assertSameValue('2026-01-02 03:04:05', $row['created_at']);
        assertSameValue(PersonStatus::Inactive, $repository->findById(new PersonId(10))?->status());
    });

    $runner->add('pdo Person repository rejects statuses outside the approved type or codes', function (): void {
        $pdo = sqlitePersonDatabase();
        $repository = personPersistenceRepositoryWithPdo($pdo);
        insertRawPerson($pdo, 30, 3);
        insertRawPerson($pdo, 31, 4);

        assertThrows(
            static fn (): ?Person => $repository->findById(new PersonId(30)),
            RuntimeException::class,
        );
        assertThrows(
            static fn (): ?Person => $repository->findById(new PersonId(31)),
            RuntimeException::class,
        );
    });

    $runner->add('pdo Person repository exposes normalized identification uniqueness violations', function (): void {
        $repository = personPersistenceRepositoryWithPdo(sqlitePersonDatabase());
        $repository->save(completePersistencePerson(10, 'Unique-100'));

        assertThrows(
            static fn (): null => $repository->save(completePersistencePerson(11, 'unique-100')),
            PDOException::class,
        );
    });

    $runner->add('pdo Person repository rejects an update whose row disappears', function (): void {
        $pdo = sqlitePersonDatabase();
        $repository = personPersistenceRepositoryWithPdo($pdo);
        $person = completePersistencePerson(10, 'Doc-100');
        $repository->save($person);
        $pdo->exec(
            'CREATE TRIGGER delete_person_before_update BEFORE UPDATE ON persons '
            . 'WHEN OLD.id = 10 BEGIN '
            . 'DELETE FROM persons WHERE id = OLD.id; '
            . 'SELECT RAISE(IGNORE); END'
        );
        $person->deactivate();

        assertThrows(static fn (): null => $repository->save($person), RuntimeException::class);
        assertSameValue(0, (int) $pdo->query('SELECT COUNT(*) FROM persons WHERE id = 10')->fetchColumn());
    });
}

function sqlitePersonDatabase(): PDO
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
        . 'CREATE TABLE document_types (id INTEGER PRIMARY KEY);'
        . 'CREATE TABLE sexes (id INTEGER PRIMARY KEY);'
        . 'CREATE TABLE marital_statuses (id INTEGER PRIMARY KEY);'
        . 'CREATE TABLE education_levels (id INTEGER PRIMARY KEY);'
        . 'CREATE TABLE persons ('
        . 'id INTEGER PRIMARY KEY, first_name TEXT NOT NULL, middle_name TEXT NULL, '
        . 'first_surname TEXT NOT NULL, second_surname TEXT NULL, '
        . 'document_type_id INTEGER NULL, document_number TEXT NULL, '
        . 'identification_key TEXT GENERATED ALWAYS AS ('
        . "CASE WHEN document_type_id IS NULL OR document_number IS NULL THEN NULL ELSE "
        . "CAST(document_type_id AS TEXT) || ':' || UPPER(TRIM(document_number)) END) STORED, "
        . 'birth_date TEXT NOT NULL, sex_id INTEGER NOT NULL, marital_status_id INTEGER NULL, '
        . 'education_level_id INTEGER NULL, email TEXT NULL, mobile_phone TEXT NULL, '
        . 'landline_phone TEXT NULL, status_id INTEGER NOT NULL, '
        . 'created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, '
        . 'updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, '
        . 'UNIQUE (identification_key), '
        . 'CHECK ((document_type_id IS NULL) = (document_number IS NULL)), '
        . 'FOREIGN KEY (document_type_id) REFERENCES document_types(id), '
        . 'FOREIGN KEY (sex_id) REFERENCES sexes(id), '
        . 'FOREIGN KEY (marital_status_id) REFERENCES marital_statuses(id), '
        . 'FOREIGN KEY (education_level_id) REFERENCES education_levels(id), '
        . 'FOREIGN KEY (status_id) REFERENCES statuses(id));'
        . "INSERT INTO status_types (id, code) VALUES (1, 'GENERAL_STATUS'), (2, 'USER_STATUS');"
        . "INSERT INTO statuses (id, status_type_id, code) VALUES "
        . "(1, 1, 'ACTIVE'), (2, 1, 'INACTIVE'), (3, 2, 'ACTIVE'), (4, 1, 'ARCHIVED');"
        . 'INSERT INTO document_types (id) VALUES (1);'
        . 'INSERT INTO sexes (id) VALUES (1);'
        . 'INSERT INTO marital_statuses (id) VALUES (1);'
        . 'INSERT INTO education_levels (id) VALUES (1);'
    );

    return $pdo;
}

function personPersistenceRepositoryWithPdo(PDO $pdo): PdoPersonRepository
{
    $reflection = new ReflectionClass(PdoPersonRepository::class);
    $repository = $reflection->newInstanceWithoutConstructor();
    $property = new ReflectionProperty(PdoPersonRepository::class, 'connection');
    $property->setValue($repository, $pdo);

    return $repository;
}

function completePersistencePerson(int $id, string $documentNumber): Person
{
    return new Person(
        new PersonId($id),
        new PersonalName('Ana', 'Maria', 'Perez', 'Lopez'),
        new Identification(1, $documentNumber),
        new DateTimeImmutable('2000-02-03', new DateTimeZone('UTC')),
        1,
        1,
        1,
        new ContactInformation('ana@example.com', 'mobile extension', 'landline extension'),
        PersonStatus::Active,
        personPersistenceToday(),
    );
}

function minimalPersistencePerson(int $id): Person
{
    return new Person(
        new PersonId($id),
        new PersonalName('Luis', null, 'Vega', null),
        null,
        new DateTimeImmutable('2002-03-04', new DateTimeZone('UTC')),
        1,
        null,
        null,
        null,
        PersonStatus::Active,
        personPersistenceToday(),
    );
}

function personPersistenceToday(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-08-01', new DateTimeZone('UTC'));
}

function insertRawPerson(PDO $pdo, int $id, int $statusId): void
{
    $statement = $pdo->prepare(
        'INSERT INTO persons (id, first_name, first_surname, birth_date, sex_id, status_id) '
        . 'VALUES (:id, :firstName, :firstSurname, :birthDate, 1, :statusId)'
    );
    $statement->execute([
        ':id' => $id,
        ':firstName' => 'Raw',
        ':firstSurname' => 'Person',
        ':birthDate' => '2000-01-01',
        ':statusId' => $statusId,
    ]);
}
