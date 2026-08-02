<?php

declare(strict_types=1);

namespace Tests;

use App\Student\Domain\Student;
use App\Student\Domain\StudentRepository;
use App\Student\Domain\StudentStatus;
use App\Student\Domain\ValueObject\AdmissionDate;
use App\Student\Domain\ValueObject\InstitutionalCode;
use App\Student\Domain\ValueObject\PersonId;
use App\Student\Domain\ValueObject\StudentId;
use App\Student\Infrastructure\Persistence\PdoStudentRepository;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use Tests\Support\TestRunner;

function registerStudentPersistenceTests(TestRunner $runner): void
{
    $runner->add('StudentRepository exposes only the approved persistence operations', function (): void {
        $reflection = new ReflectionClass(StudentRepository::class);
        $methods = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            $reflection->getMethods(),
        );
        sort($methods, SORT_STRING);

        assertSameValue(['findById', 'findByInstitutionalCode', 'findByPersonId', 'save'], $methods);
        assertSameValue(Student::class, $reflection->getMethod('save')->getReturnType()?->getName());

        $source = file_get_contents(dirname(__DIR__) . '/app/Student/Domain/StudentRepository.php');
        assertSameValue(true, is_string($source));
        assertSameValue(false, str_contains((string) $source, 'Infrastructure'));
        assertSameValue(false, str_contains((string) $source, 'PDO'));
    });

    $runner->add('pdo Student repository finds existing and missing approved identities', function (): void {
        $pdo = sqliteStudentDatabase();
        insertRawStudent($pdo, 100, 1, 'Stu-001', '2020-09-01', 1);
        $repository = studentPersistenceRepositoryWithPdo($pdo);

        $byId = $repository->findById(new StudentId(100));
        $byPerson = $repository->findByPersonId(new PersonId(1));
        $byCode = $repository->findByInstitutionalCode(new InstitutionalCode('Stu-001'));

        assertSameValue(100, $byId?->id()?->value());
        assertSameValue(1, $byPerson?->personId()->value());
        assertSameValue('Stu-001', $byCode?->institutionalCode()->value());
        assertSameValue('2020-09-01', $byId?->admissionDate()->value()->format('Y-m-d'));
        assertSameValue('00:00:00', $byId?->admissionDate()->value()->format('H:i:s'));
        assertSameValue(StudentStatus::Active, $byId?->status());
        assertSameValue(null, $repository->findById(new StudentId(999)));
        assertSameValue(null, $repository->findByPersonId(new PersonId(50)));
        assertSameValue(null, $repository->findByInstitutionalCode(new InstitutionalCode('MISSING')));
    });

    $runner->add('pdo Student repository reconstructs both GENERAL_STATUS values', function (): void {
        $pdo = sqliteStudentDatabase();
        insertRawStudent($pdo, 101, 2, 'STU-002', '2021-01-01', 1);
        insertRawStudent($pdo, 102, 3, 'STU-003', '2021-01-02', 2);
        $repository = studentPersistenceRepositoryWithPdo($pdo);

        assertSameValue(StudentStatus::Active, $repository->findById(new StudentId(101))?->status());
        assertSameValue(StudentStatus::Inactive, $repository->findById(new StudentId(102))?->status());
    });

    $runner->add('pdo Student repository rejects wrong status type and unsupported code', function (): void {
        $pdo = sqliteStudentDatabase();
        insertRawStudent($pdo, 103, 4, 'STU-004', '2021-01-03', 3);
        insertRawStudent($pdo, 104, 5, 'STU-005', '2021-01-04', 4);
        $repository = studentPersistenceRepositoryWithPdo($pdo);

        assertThrows(
            static fn (): ?Student => $repository->findById(new StudentId(103)),
            RuntimeException::class,
        );
        assertThrows(
            static fn (): ?Student => $repository->findById(new StudentId(104)),
            RuntimeException::class,
        );
    });

    $runner->add('pdo Student repository rejects invalid and future persisted admission dates', function (): void {
        $pdo = sqliteStudentDatabase();
        insertRawStudent($pdo, 105, 6, 'STU-006', '2026-02-30', 1);
        insertRawStudent($pdo, 106, 7, 'STU-007', '2999-01-01', 1);
        $repository = studentPersistenceRepositoryWithPdo($pdo);

        assertThrows(
            static fn (): ?Student => $repository->findById(new StudentId(105)),
            RuntimeException::class,
        );
        assertThrows(
            static fn (): ?Student => $repository->findById(new StudentId(106)),
            RuntimeException::class,
        );
    });

    $runner->add('pdo Student repository inserts without manual identity and reloads generated state', function (): void {
        $pdo = sqliteStudentDatabase();
        $repository = studentPersistenceRepositoryWithPdo($pdo);
        $new = studentPersistenceFixture(null, 8, 'STU-008', '2020-01-08');

        $persisted = $repository->save($new);
        $id = requiredPersistedStudentId($persisted);

        assertSameValue(null, $new->id());
        assertSameValue(false, $persisted === $new);
        assertSameValue(true, $id->value() > 0);
        assertSameValue($id->value(), $repository->findById($id)?->id()?->value());
        assertSameValue('STU-008', $persisted->institutionalCode()->value());
    });

    $runner->add('pdo Student repository updates code date and status without changing PersonId', function (): void {
        $pdo = sqliteStudentDatabase();
        $repository = studentPersistenceRepositoryWithPdo($pdo);
        $student = $repository->save(studentPersistenceFixture(null, 9, 'STU-009', '2020-01-09'));
        $id = requiredPersistedStudentId($student);

        $student->updateAcademicInformation(
            new InstitutionalCode('Updated-Code / 9'),
            studentPersistenceAdmissionDate('2022-03-04'),
        );
        $student->deactivate();
        $updated = $repository->save($student);

        $statement = $pdo->prepare(
            'SELECT person_id, institutional_code, admission_date, status_id FROM students WHERE id = :id'
        );
        $statement->execute([':id' => $id->value()]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        assertSameValue(9, (int) $row['person_id']);
        assertSameValue('Updated-Code / 9', $row['institutional_code']);
        assertSameValue('2022-03-04', $row['admission_date']);
        assertSameValue(2, (int) $row['status_id']);
        assertSameValue('Updated-Code / 9', $updated->institutionalCode()->value());
        assertSameValue('2022-03-04', $updated->admissionDate()->value()->format('Y-m-d'));
        assertSameValue(StudentStatus::Inactive, $updated->status());
    });

    $runner->add('pdo Student repository accepts zero-row identical updates', function (): void {
        $pdo = sqliteStudentDatabase();
        $repository = studentPersistenceRepositoryWithPdo($pdo);
        $student = $repository->save(studentPersistenceFixture(null, 10, 'STU-010', '2020-01-10'));
        $pdo->exec(
            'CREATE TRIGGER ignore_identical_student_update BEFORE UPDATE ON students '
            . 'BEGIN SELECT RAISE(IGNORE); END'
        );

        $persisted = $repository->save($student);

        assertSameValue(true, requiredPersistedStudentId($persisted)->equals(requiredPersistedStudentId($student)));
        assertSameValue(true, $persisted->institutionalCode()->equals($student->institutionalCode()));
        assertSameValue(true, $persisted->admissionDate()->equals($student->admissionDate()));
    });

    $runner->add('pdo Student repository rejects an update whose row disappears', function (): void {
        $pdo = sqliteStudentDatabase();
        $repository = studentPersistenceRepositoryWithPdo($pdo);
        $student = $repository->save(studentPersistenceFixture(null, 11, 'STU-011', '2020-01-11'));
        $pdo->exec(
            'CREATE TRIGGER delete_student_before_update BEFORE UPDATE ON students '
            . 'BEGIN DELETE FROM students WHERE id = OLD.id; SELECT RAISE(IGNORE); END'
        );
        $student->deactivate();

        assertThrows(static fn (): Student => $repository->save($student), RuntimeException::class);
    });

    $runner->add('pdo Student repository exposes Person and InstitutionalCode uniqueness violations', function (): void {
        $repository = studentPersistenceRepositoryWithPdo(sqliteStudentDatabase());
        $repository->save(studentPersistenceFixture(null, 12, 'Unique-Code', '2020-01-12'));

        assertThrows(
            static fn (): Student => $repository->save(
                studentPersistenceFixture(null, 12, 'OTHER-CODE', '2020-02-12')
            ),
            PDOException::class,
        );
        assertThrows(
            static fn (): Student => $repository->save(
                studentPersistenceFixture(null, 13, 'unique-code', '2020-02-13')
            ),
            PDOException::class,
        );
    });

    $runner->add('pdo Student lookup uses direct prepared indexed code comparison including quotes', function (): void {
        $repository = studentPersistenceRepositoryWithPdo(sqliteStudentDatabase());
        $persisted = $repository->save(studentPersistenceFixture(null, 14, "Stu-'Quoted", '2020-01-14'));
        $id = requiredPersistedStudentId($persisted);

        assertSameValue(
            $id->value(),
            $repository->findByInstitutionalCode(new InstitutionalCode("Stu-'Quoted"))?->id()?->value(),
        );

        $source = file_get_contents(
            dirname(__DIR__) . '/app/Student/Infrastructure/Persistence/PdoStudentRepository.php'
        );
        if (!is_string($source)) {
            throw new RuntimeException('Unable to read PdoStudentRepository source.');
        }

        assertSameValue(true, str_contains(
            $source,
            'WHERE s.institutional_code = :institutionalCode LIMIT 1'
        ));
        assertSameValue(true, str_contains(
            $source,
            "':institutionalCode' => " . '$institutionalCode->value()'
        ));
        assertSameValue(false, preg_match(
            '/WHERE\s+(?:UPPER|LOWER|TRIM|CAST|COLLATE)\s*\(\s*s\.institutional_code/i',
            $source,
        ) === 1);
    });

    $runner->add('pdo Student repository uses fixed SQL without manual IDs or internal transactions', function (): void {
        $source = file_get_contents(
            dirname(__DIR__) . '/app/Student/Infrastructure/Persistence/PdoStudentRepository.php'
        );
        if (!is_string($source)) {
            throw new RuntimeException('Unable to read PdoStudentRepository source.');
        }

        assertSameValue(true, str_contains($source, 'WHERE s.id = :id LIMIT 1'));
        assertSameValue(true, str_contains($source, 'WHERE s.person_id = :personId LIMIT 1'));
        assertSameValue(false, str_contains($source, 'INSERT INTO students (id,'));
        assertSameValue(false, str_contains($source, 'UPDATE students SET person_id'));
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

function sqliteStudentDatabase(): PDO
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
        . 'CREATE TABLE students ('
        . 'id INTEGER PRIMARY KEY AUTOINCREMENT, person_id INTEGER NOT NULL UNIQUE, '
        . 'institutional_code TEXT COLLATE NOCASE NOT NULL UNIQUE, admission_date TEXT NOT NULL, '
        . 'status_id INTEGER NOT NULL, FOREIGN KEY (person_id) REFERENCES persons(id), '
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

function studentPersistenceRepositoryWithPdo(PDO $pdo): PdoStudentRepository
{
    $reflection = new ReflectionClass(PdoStudentRepository::class);
    $repository = $reflection->newInstanceWithoutConstructor();
    $property = new ReflectionProperty(PdoStudentRepository::class, 'connection');
    $property->setValue($repository, $pdo);

    return $repository;
}

function studentPersistenceFixture(
    ?int $id,
    int $personId,
    string $institutionalCode,
    string $admissionDate,
): Student {
    return new Student(
        $id === null ? null : new StudentId($id),
        new PersonId($personId),
        new InstitutionalCode($institutionalCode),
        studentPersistenceAdmissionDate($admissionDate),
        StudentStatus::Active,
    );
}

function studentPersistenceAdmissionDate(string $value): AdmissionDate
{
    $timezone = new DateTimeZone('UTC');

    return new AdmissionDate(
        new DateTimeImmutable($value, $timezone),
        new DateTimeImmutable('2998-12-31', $timezone),
    );
}

function requiredPersistedStudentId(Student $student): StudentId
{
    $id = $student->id();
    if ($id === null) {
        throw new RuntimeException('Expected a persisted Student identity.');
    }

    return $id;
}

function insertRawStudent(
    PDO $pdo,
    int $id,
    int $personId,
    string $institutionalCode,
    string $admissionDate,
    int $statusId,
): void {
    $statement = $pdo->prepare(
        'INSERT INTO students (id, person_id, institutional_code, admission_date, status_id) '
        . 'VALUES (:id, :personId, :institutionalCode, :admissionDate, :statusId)'
    );
    $statement->execute([
        ':id' => $id,
        ':personId' => $personId,
        ':institutionalCode' => $institutionalCode,
        ':admissionDate' => $admissionDate,
        ':statusId' => $statusId,
    ]);
}
