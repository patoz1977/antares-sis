<?php

declare(strict_types=1);

namespace App\Student\Infrastructure\Persistence;

use App\Student\Domain\Exception\InvalidStudentState;
use App\Student\Domain\Student;
use App\Student\Domain\StudentRepository;
use App\Student\Domain\StudentStatus;
use App\Student\Domain\ValueObject\AdmissionDate;
use App\Student\Domain\ValueObject\InstitutionalCode;
use App\Student\Domain\ValueObject\PersonId;
use App\Student\Domain\ValueObject\StudentId;
use Core\Database\ConnectionManager;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

final class PdoStudentRepository implements StudentRepository
{
    private const STATUS_TYPE = 'GENERAL_STATUS';

    private PDO $connection;

    public function __construct(ConnectionManager $connectionManager)
    {
        $this->connection = $connectionManager->connection();
    }

    public function findById(StudentId $id): ?Student
    {
        $statement = $this->connection->prepare(
            $this->selectSql() . ' WHERE s.id = :id LIMIT 1'
        );
        $statement->execute([':id' => $id->value()]);

        return $this->mapRow($statement->fetch(PDO::FETCH_ASSOC));
    }

    public function findByPersonId(PersonId $personId): ?Student
    {
        $statement = $this->connection->prepare(
            $this->selectSql() . ' WHERE s.person_id = :personId LIMIT 1'
        );
        $statement->execute([':personId' => $personId->value()]);

        return $this->mapRow($statement->fetch(PDO::FETCH_ASSOC));
    }

    public function findByInstitutionalCode(InstitutionalCode $institutionalCode): ?Student
    {
        $statement = $this->connection->prepare(
            $this->selectSql() . ' WHERE s.institutional_code = :institutionalCode LIMIT 1'
        );
        $statement->execute([':institutionalCode' => $institutionalCode->value()]);

        return $this->mapRow($statement->fetch(PDO::FETCH_ASSOC));
    }

    public function save(Student $student): Student
    {
        $statusId = $this->resolveStatusId($student->status());

        if ($student->id() === null) {
            return $this->insert($student, $statusId);
        }

        return $this->update($student, $statusId);
    }

    private function insert(Student $student, int $statusId): Student
    {
        $statement = $this->connection->prepare(
            'INSERT INTO students (person_id, institutional_code, admission_date, status_id) '
            . 'VALUES (:personId, :institutionalCode, :admissionDate, :statusId)'
        );
        $values = $this->persistenceValues($student, $statusId);
        $values[':personId'] = $student->personId()->value();
        $statement->execute($values);

        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Student insert did not affect exactly one row.');
        }

        $generatedId = (int) $this->connection->lastInsertId();
        if ($generatedId <= 0) {
            throw new RuntimeException('Student insert did not produce a positive database identity.');
        }

        $persisted = $this->findById(new StudentId($generatedId));
        if ($persisted === null) {
            throw new RuntimeException('Inserted Student could not be reconstructed.');
        }

        return $persisted;
    }

    private function update(Student $student, int $statusId): Student
    {
        $id = $student->id();
        if ($id === null) {
            throw new RuntimeException('A Student without persisted identity cannot be updated.');
        }

        $statement = $this->connection->prepare(
            'UPDATE students SET institutional_code = :institutionalCode, '
            . 'admission_date = :admissionDate, status_id = :statusId WHERE id = :id'
        );
        $values = $this->persistenceValues($student, $statusId);
        $values[':id'] = $id->value();
        $statement->execute($values);

        $affectedRows = $statement->rowCount();
        if ($affectedRows !== 0 && $affectedRows !== 1) {
            throw new RuntimeException('Student update did not affect zero or one row.');
        }

        $persisted = $this->findById($id);
        if ($persisted === null) {
            throw new RuntimeException('Student update failed because the persisted row disappeared.');
        }

        if ($affectedRows === 0 && !$this->sameState($persisted, $student)) {
            throw new RuntimeException('Student update did not persist the requested state.');
        }

        return $persisted;
    }

    private function resolveStatusId(StudentStatus $status): int
    {
        $statement = $this->connection->prepare(
            'SELECT s.id FROM statuses s '
            . 'INNER JOIN status_types st ON st.id = s.status_type_id '
            . 'WHERE st.code = :statusType AND s.code = :statusCode'
        );
        $statement->execute([
            ':statusType' => self::STATUS_TYPE,
            ':statusCode' => $status->value,
        ]);
        $rows = $statement->fetchAll(PDO::FETCH_COLUMN);

        if (count($rows) !== 1) {
            throw new RuntimeException('Student status must resolve to exactly one GENERAL_STATUS row.');
        }

        return (int) $rows[0];
    }

    /** @return array<string, int|string> */
    private function persistenceValues(Student $student, int $statusId): array
    {
        return [
            ':institutionalCode' => $student->institutionalCode()->value(),
            ':admissionDate' => $student->admissionDate()->value()->format('Y-m-d'),
            ':statusId' => $statusId,
        ];
    }

    private function selectSql(): string
    {
        return 'SELECT s.id, s.person_id, s.institutional_code, s.admission_date, s.status_id, '
            . 'status_row.code AS status_code, st.code AS status_type_code '
            . 'FROM students s '
            . 'INNER JOIN statuses status_row ON status_row.id = s.status_id '
            . 'INNER JOIN status_types st ON st.id = status_row.status_type_id';
    }

    private function mapRow(array|false $row): ?Student
    {
        if ($row === false) {
            return null;
        }

        if ((string) $row['status_type_code'] !== self::STATUS_TYPE) {
            throw new RuntimeException('Student status does not belong to GENERAL_STATUS.');
        }

        $status = StudentStatus::tryFrom((string) $row['status_code']);
        if ($status === null) {
            throw new RuntimeException('Student has an unsupported GENERAL_STATUS value.');
        }

        return new Student(
            new StudentId((int) $row['id']),
            new PersonId((int) $row['person_id']),
            new InstitutionalCode((string) $row['institutional_code']),
            $this->mapAdmissionDate((string) $row['admission_date']),
            $status,
        );
    }

    private function mapAdmissionDate(string $value): AdmissionDate
    {
        $timezone = new DateTimeZone('UTC');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
        $errors = DateTimeImmutable::getLastErrors();

        if ($date === false
            || $date->format('Y-m-d') !== $value
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        ) {
            throw new RuntimeException('Student has an invalid persisted admission date.');
        }

        try {
            return new AdmissionDate($date, new DateTimeImmutable('today', $timezone));
        } catch (InvalidStudentState $exception) {
            throw new RuntimeException(
                'Student has a future persisted admission date.',
                previous: $exception,
            );
        }
    }

    private function sameState(Student $persisted, Student $student): bool
    {
        $persistedId = $persisted->id();
        $studentId = $student->id();
        if ($persistedId === null || $studentId === null || !$persistedId->equals($studentId)) {
            return false;
        }

        return $persisted->personId()->equals($student->personId())
            && $persisted->institutionalCode()->equals($student->institutionalCode())
            && $persisted->admissionDate()->equals($student->admissionDate())
            && $persisted->status() === $student->status();
    }
}
