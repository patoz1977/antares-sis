<?php

declare(strict_types=1);

namespace App\Family\Infrastructure\Persistence;

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
use App\Family\Domain\ValueObject\RepresentativeId;
use App\Family\Domain\ValueObject\StudentId;
use Core\Database\ConnectionManager;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;

final class PdoFamilyRepository implements FamilyRepository
{
    private const STATUS_TYPE = 'GENERAL_STATUS';

    private PDO $connection;

    public function __construct(ConnectionManager $connectionManager)
    {
        $this->connection = $connectionManager->connection();
    }

    public function findById(FamilyId $id): ?Family
    {
        $row = $this->findFamilyRow($id);

        return $row === null ? null : $this->mapFamily($row);
    }

    public function findActiveByRepresentativeId(RepresentativeId $representativeId): array
    {
        $statement = $this->connection->prepare(
            'SELECT DISTINCT fr.family_id FROM family_representatives fr '
            . 'WHERE fr.representative_id = :representativeId AND fr.ended_at IS NULL '
            . 'ORDER BY fr.family_id'
        );
        $statement->execute([':representativeId' => $representativeId->value()]);

        return $this->findFamiliesByIds($statement->fetchAll(PDO::FETCH_COLUMN));
    }

    public function findActiveByStudentId(StudentId $studentId): ?Family
    {
        $statement = $this->connection->prepare(
            'SELECT DISTINCT fs.family_id FROM family_students fs '
            . 'WHERE fs.student_id = :studentId AND fs.ended_at IS NULL '
            . 'ORDER BY fs.family_id'
        );
        $statement->execute([':studentId' => $studentId->value()]);
        $familyIds = $statement->fetchAll(PDO::FETCH_COLUMN);

        if (count($familyIds) > 1) {
            throw new RuntimeException('Student has more than one active persisted Family membership.');
        }

        if ($familyIds === []) {
            return null;
        }

        $family = $this->findById(new FamilyId((int) $familyIds[0]));
        if ($family === null) {
            throw new RuntimeException('Active FamilyStudent membership references a missing Family.');
        }

        return $family;
    }

    public function save(Family $family): Family
    {
        $ownsTransaction = !$this->connection->inTransaction();
        if ($ownsTransaction && !$this->connection->beginTransaction()) {
            throw new RuntimeException('Family persistence could not start its transaction.');
        }

        try {
            $statusId = $this->resolveStatusId($family->status());
            $persisted = $family->id() === null
                ? $this->insertFamily($family, $statusId)
                : $this->updateFamily($family, $statusId);

            if ($ownsTransaction && !$this->connection->commit()) {
                throw new RuntimeException('Family persistence could not commit its transaction.');
            }

            return $persisted;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->connection->inTransaction()) {
                $this->connection->rollBack();
            }

            throw $exception;
        }
    }

    /** @param list<int|string> $ids @return list<Family> */
    private function findFamiliesByIds(array $ids): array
    {
        $families = [];
        foreach ($ids as $id) {
            $family = $this->findById(new FamilyId((int) $id));
            if ($family === null) {
                throw new RuntimeException('Active membership references a missing Family.');
            }

            $families[] = $family;
        }

        return $families;
    }

    private function insertFamily(Family $family, int $statusId): Family
    {
        $statement = $this->connection->prepare(
            'INSERT INTO families (display_name, status_id) VALUES (:displayName, :statusId)'
        );
        $statement->execute([
            ':displayName' => $family->displayName()->value(),
            ':statusId' => $statusId,
        ]);
        $this->requireSingleInsertedRow($statement->rowCount(), 'Family');

        $familyId = new FamilyId($this->generatedId('Family'));
        foreach ($family->representatives() as $membership) {
            if ($membership->id() !== null) {
                throw new RuntimeException('A new Family cannot contain a persisted representative membership.');
            }

            $this->insertRepresentativeMembership($familyId, $membership);
        }

        foreach ($family->students() as $membership) {
            if ($membership->id() !== null) {
                throw new RuntimeException('A new Family cannot contain a persisted student membership.');
            }

            $this->insertStudentMembership($familyId, $membership);
        }

        return $this->requirePersistedFamily($familyId, 'Inserted Family could not be reconstructed.');
    }

    private function updateFamily(Family $family, int $statusId): Family
    {
        $familyId = $family->id();
        if ($familyId === null) {
            throw new RuntimeException('A Family without persisted identity cannot be updated.');
        }

        $representatives = $family->representatives();
        $students = $family->students();
        $persistedRepresentatives = $this->representativeRows($familyId);
        $persistedStudents = $this->studentRows($familyId);

        $this->validateRepresentativeSynchronization(
            $familyId,
            $representatives,
            $persistedRepresentatives,
        );
        $this->validateStudentSynchronization($familyId, $students, $persistedStudents);

        $statement = $this->connection->prepare(
            'UPDATE families SET display_name = :displayName, status_id = :statusId WHERE id = :id'
        );
        $statement->execute([
            ':displayName' => $family->displayName()->value(),
            ':statusId' => $statusId,
            ':id' => $familyId->value(),
        ]);
        $this->requireZeroOrOneUpdatedRow($statement->rowCount(), 'Family');

        $persistedFamilyRow = $this->findFamilyRow($familyId);
        if ($persistedFamilyRow === null) {
            throw new RuntimeException('Family update failed because the persisted row disappeared.');
        }

        if (!$this->sameFamilyRow($persistedFamilyRow, $family)) {
            throw new RuntimeException('Family update did not persist the requested state.');
        }

        foreach ($representatives as $membership) {
            if ($membership->id() === null) {
                $this->insertRepresentativeMembership($familyId, $membership);
                continue;
            }

            $this->updateRepresentativeMembership($familyId, $membership);
        }

        foreach ($students as $membership) {
            if ($membership->id() === null) {
                $this->insertStudentMembership($familyId, $membership);
                continue;
            }

            $this->updateStudentMembership($familyId, $membership);
        }

        return $this->requirePersistedFamily($familyId, 'Updated Family could not be reconstructed.');
    }

    private function insertRepresentativeMembership(
        FamilyId $familyId,
        FamilyRepresentative $membership,
    ): void {
        $statement = $this->connection->prepare(
            'INSERT INTO family_representatives ('
            . 'family_id, representative_id, relationship_type_id, is_primary, started_at, ended_at'
            . ') VALUES ('
            . ':familyId, :representativeId, :relationshipTypeId, :isPrimary, :startedAt, :endedAt'
            . ')'
        );
        $statement->execute([
            ':familyId' => $familyId->value(),
            ':representativeId' => $membership->representativeId()->value(),
            ':relationshipTypeId' => $membership->relationshipTypeId()->value(),
            ':isPrimary' => $membership->isPrimary() ? 1 : 0,
            ':startedAt' => $this->formatTimestamp($membership->startedAt()),
            ':endedAt' => $this->formatNullableTimestamp($membership->endedAt()),
        ]);
        $this->requireSingleInsertedRow($statement->rowCount(), 'FamilyRepresentative');
        $this->generatedId('FamilyRepresentative');
    }

    private function insertStudentMembership(FamilyId $familyId, FamilyStudent $membership): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO family_students (family_id, student_id, started_at, ended_at) '
            . 'VALUES (:familyId, :studentId, :startedAt, :endedAt)'
        );
        $statement->execute([
            ':familyId' => $familyId->value(),
            ':studentId' => $membership->studentId()->value(),
            ':startedAt' => $this->formatTimestamp($membership->startedAt()),
            ':endedAt' => $this->formatNullableTimestamp($membership->endedAt()),
        ]);
        $this->requireSingleInsertedRow($statement->rowCount(), 'FamilyStudent');
        $this->generatedId('FamilyStudent');
    }

    private function updateRepresentativeMembership(
        FamilyId $familyId,
        FamilyRepresentative $membership,
    ): void {
        $id = $membership->id();
        if ($id === null) {
            throw new RuntimeException('A representative membership without identity cannot be updated.');
        }

        $statement = $this->connection->prepare(
            'UPDATE family_representatives SET ended_at = :endedAt '
            . 'WHERE id = :id AND family_id = :familyId'
        );
        $statement->execute([
            ':endedAt' => $this->formatNullableTimestamp($membership->endedAt()),
            ':id' => $id->value(),
            ':familyId' => $familyId->value(),
        ]);
        $this->requireZeroOrOneUpdatedRow($statement->rowCount(), 'FamilyRepresentative');

        $row = $this->findRepresentativeRow($id);
        if ($row === null) {
            throw new RuntimeException('FamilyRepresentative update failed because the row disappeared.');
        }

        if ((int) $row['family_id'] !== $familyId->value()) {
            throw new RuntimeException('FamilyRepresentative belongs to another Family.');
        }

        if (!$this->sameRepresentativeRow($row, $membership)) {
            throw new RuntimeException('FamilyRepresentative update did not persist the requested state.');
        }
    }

    private function updateStudentMembership(FamilyId $familyId, FamilyStudent $membership): void
    {
        $id = $membership->id();
        if ($id === null) {
            throw new RuntimeException('A student membership without identity cannot be updated.');
        }

        $statement = $this->connection->prepare(
            'UPDATE family_students SET ended_at = :endedAt WHERE id = :id AND family_id = :familyId'
        );
        $statement->execute([
            ':endedAt' => $this->formatNullableTimestamp($membership->endedAt()),
            ':id' => $id->value(),
            ':familyId' => $familyId->value(),
        ]);
        $this->requireZeroOrOneUpdatedRow($statement->rowCount(), 'FamilyStudent');

        $row = $this->findStudentRow($id);
        if ($row === null) {
            throw new RuntimeException('FamilyStudent update failed because the row disappeared.');
        }

        if ((int) $row['family_id'] !== $familyId->value()) {
            throw new RuntimeException('FamilyStudent belongs to another Family.');
        }

        if (!$this->sameStudentRow($row, $membership)) {
            throw new RuntimeException('FamilyStudent update did not persist the requested state.');
        }
    }

    /**
     * @param list<FamilyRepresentative> $memberships
     * @param list<array<string, mixed>> $persistedRows
     */
    private function validateRepresentativeSynchronization(
        FamilyId $familyId,
        array $memberships,
        array $persistedRows,
    ): void {
        $persistedById = $this->rowsById($persistedRows, 'FamilyRepresentative');
        $aggregateIds = [];

        foreach ($memberships as $membership) {
            $id = $membership->id();
            if ($id === null) {
                continue;
            }

            $value = $id->value();
            if (isset($aggregateIds[$value])) {
                throw new RuntimeException('Family contains a duplicate persisted FamilyRepresentative identity.');
            }
            $aggregateIds[$value] = true;

            $row = $persistedById[$value] ?? $this->findRepresentativeRow($id);
            if ($row === null) {
                throw new RuntimeException('Family contains an unknown FamilyRepresentative identity.');
            }
            if ((int) $row['family_id'] !== $familyId->value()) {
                throw new RuntimeException('FamilyRepresentative belongs to another Family.');
            }

            $this->assertRepresentativeTransition($row, $membership);
        }

        $this->assertNoPersistedMembershipOmitted(
            array_keys($persistedById),
            $aggregateIds,
            'FamilyRepresentative',
        );
    }

    /**
     * @param list<FamilyStudent> $memberships
     * @param list<array<string, mixed>> $persistedRows
     */
    private function validateStudentSynchronization(
        FamilyId $familyId,
        array $memberships,
        array $persistedRows,
    ): void {
        $persistedById = $this->rowsById($persistedRows, 'FamilyStudent');
        $aggregateIds = [];

        foreach ($memberships as $membership) {
            $id = $membership->id();
            if ($id === null) {
                continue;
            }

            $value = $id->value();
            if (isset($aggregateIds[$value])) {
                throw new RuntimeException('Family contains a duplicate persisted FamilyStudent identity.');
            }
            $aggregateIds[$value] = true;

            $row = $persistedById[$value] ?? $this->findStudentRow($id);
            if ($row === null) {
                throw new RuntimeException('Family contains an unknown FamilyStudent identity.');
            }
            if ((int) $row['family_id'] !== $familyId->value()) {
                throw new RuntimeException('FamilyStudent belongs to another Family.');
            }

            $this->assertStudentTransition($row, $membership);
        }

        $this->assertNoPersistedMembershipOmitted(
            array_keys($persistedById),
            $aggregateIds,
            'FamilyStudent',
        );
    }

    /** @param array<string, mixed> $row */
    private function assertRepresentativeTransition(array $row, FamilyRepresentative $membership): void
    {
        if ((int) $row['representative_id'] !== $membership->representativeId()->value()
            || (int) $row['relationship_type_id'] !== $membership->relationshipTypeId()->value()
            || (bool) $row['is_primary'] !== $membership->isPrimary()
            || (string) $row['started_at'] !== $this->formatTimestamp($membership->startedAt())
        ) {
            throw new RuntimeException('FamilyRepresentative immutable fields cannot be changed.');
        }

        $this->assertEndTransition($row['ended_at'], $membership->endedAt(), 'FamilyRepresentative');
    }

    /** @param array<string, mixed> $row */
    private function assertStudentTransition(array $row, FamilyStudent $membership): void
    {
        if ((int) $row['student_id'] !== $membership->studentId()->value()
            || (string) $row['started_at'] !== $this->formatTimestamp($membership->startedAt())
        ) {
            throw new RuntimeException('FamilyStudent immutable fields cannot be changed.');
        }

        $this->assertEndTransition($row['ended_at'], $membership->endedAt(), 'FamilyStudent');
    }

    private function assertEndTransition(mixed $persisted, ?DateTimeImmutable $requested, string $entity): void
    {
        $persistedValue = $persisted === null ? null : (string) $persisted;
        $requestedValue = $this->formatNullableTimestamp($requested);

        if ($persistedValue !== null && $persistedValue !== $requestedValue) {
            throw new RuntimeException($entity . ' ended_at cannot be changed or reactivated.');
        }
    }

    /**
     * @param list<int> $persistedIds
     * @param array<int, true> $aggregateIds
     */
    private function assertNoPersistedMembershipOmitted(
        array $persistedIds,
        array $aggregateIds,
        string $entity,
    ): void {
        foreach ($persistedIds as $id) {
            if (!isset($aggregateIds[$id])) {
                throw new RuntimeException($entity . ' persisted history cannot be omitted from Family.');
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function rowsById(array $rows, string $entity): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            if (isset($indexed[$id])) {
                throw new RuntimeException($entity . ' persistence returned a duplicate identity.');
            }
            $indexed[$id] = $row;
        }

        return $indexed;
    }

    private function resolveStatusId(FamilyStatus $status): int
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
            throw new RuntimeException('Family status must resolve to exactly one GENERAL_STATUS row.');
        }

        return (int) $rows[0];
    }

    /** @return array<string, mixed>|null */
    private function findFamilyRow(FamilyId $id): ?array
    {
        $statement = $this->connection->prepare(
            $this->familySelectSql() . ' WHERE f.id = :id LIMIT 1'
        );
        $statement->execute([':id' => $id->value()]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** @return list<array<string, mixed>> */
    private function representativeRows(FamilyId $familyId): array
    {
        $statement = $this->connection->prepare(
            'SELECT fr.id, fr.family_id, fr.representative_id, fr.relationship_type_id, '
            . 'fr.is_primary, fr.started_at, fr.ended_at FROM family_representatives fr '
            . 'WHERE fr.family_id = :familyId ORDER BY fr.started_at, fr.id'
        );
        $statement->execute([':familyId' => $familyId->value()]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string, mixed>> */
    private function studentRows(FamilyId $familyId): array
    {
        $statement = $this->connection->prepare(
            'SELECT fs.id, fs.family_id, fs.student_id, fs.started_at, fs.ended_at '
            . 'FROM family_students fs WHERE fs.family_id = :familyId '
            . 'ORDER BY fs.started_at, fs.id'
        );
        $statement->execute([':familyId' => $familyId->value()]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed>|null */
    private function findRepresentativeRow(FamilyRepresentativeId $id): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT fr.id, fr.family_id, fr.representative_id, fr.relationship_type_id, '
            . 'fr.is_primary, fr.started_at, fr.ended_at FROM family_representatives fr '
            . 'WHERE fr.id = :id LIMIT 1'
        );
        $statement->execute([':id' => $id->value()]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    private function findStudentRow(FamilyStudentId $id): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT fs.id, fs.family_id, fs.student_id, fs.started_at, fs.ended_at '
            . 'FROM family_students fs WHERE fs.id = :id LIMIT 1'
        );
        $statement->execute([':id' => $id->value()]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** @param array<string, mixed> $row */
    private function mapFamily(array $row): Family
    {
        try {
            if ((string) $row['status_type_code'] !== self::STATUS_TYPE) {
                throw new RuntimeException('Family status does not belong to GENERAL_STATUS.');
            }

            $status = FamilyStatus::tryFrom((string) $row['status_code']);
            if ($status === null) {
                throw new RuntimeException('Family has an unsupported GENERAL_STATUS value.');
            }

            $familyId = new FamilyId((int) $row['id']);
            $representatives = array_map(
                fn (array $membership): FamilyRepresentative => $this->mapRepresentative($membership),
                $this->representativeRows($familyId),
            );
            $students = array_map(
                fn (array $membership): FamilyStudent => $this->mapStudent($membership),
                $this->studentRows($familyId),
            );

            return Family::reconstitute(
                $familyId,
                new DisplayName((string) $row['display_name']),
                $status,
                $representatives,
                $students,
            );
        } catch (InvalidFamilyState $exception) {
            throw new RuntimeException('Persisted Family violates Domain invariants.', previous: $exception);
        }
    }

    /** @param array<string, mixed> $row */
    private function mapRepresentative(array $row): FamilyRepresentative
    {
        return new FamilyRepresentative(
            new FamilyRepresentativeId((int) $row['id']),
            new RepresentativeId((int) $row['representative_id']),
            new RelationshipTypeId((int) $row['relationship_type_id']),
            (bool) $row['is_primary'],
            $this->parseTimestamp((string) $row['started_at'], 'FamilyRepresentative started_at'),
            $row['ended_at'] === null
                ? null
                : $this->parseTimestamp((string) $row['ended_at'], 'FamilyRepresentative ended_at'),
        );
    }

    /** @param array<string, mixed> $row */
    private function mapStudent(array $row): FamilyStudent
    {
        return new FamilyStudent(
            new FamilyStudentId((int) $row['id']),
            new StudentId((int) $row['student_id']),
            $this->parseTimestamp((string) $row['started_at'], 'FamilyStudent started_at'),
            $row['ended_at'] === null
                ? null
                : $this->parseTimestamp((string) $row['ended_at'], 'FamilyStudent ended_at'),
        );
    }

    private function parseTimestamp(string $value, string $field): DateTimeImmutable
    {
        $timezone = new DateTimeZone('UTC');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $timezone);
        $errors = DateTimeImmutable::getLastErrors();

        if ($date === false
            || $date->format('Y-m-d H:i:s') !== $value
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        ) {
            throw new RuntimeException($field . ' has an invalid persisted UTC timestamp.');
        }

        return $date;
    }

    /** @param array<string, mixed> $row */
    private function sameFamilyRow(array $row, Family $family): bool
    {
        return $family->id() !== null
            && (int) $row['id'] === $family->id()?->value()
            && (string) $row['display_name'] === $family->displayName()->value()
            && (string) $row['status_type_code'] === self::STATUS_TYPE
            && (string) $row['status_code'] === $family->status()->value;
    }

    /** @param array<string, mixed> $row */
    private function sameRepresentativeRow(array $row, FamilyRepresentative $membership): bool
    {
        return $membership->id() !== null
            && (int) $row['id'] === $membership->id()?->value()
            && (int) $row['representative_id'] === $membership->representativeId()->value()
            && (int) $row['relationship_type_id'] === $membership->relationshipTypeId()->value()
            && (bool) $row['is_primary'] === $membership->isPrimary()
            && (string) $row['started_at'] === $this->formatTimestamp($membership->startedAt())
            && ($row['ended_at'] === null ? null : (string) $row['ended_at'])
                === $this->formatNullableTimestamp($membership->endedAt());
    }

    /** @param array<string, mixed> $row */
    private function sameStudentRow(array $row, FamilyStudent $membership): bool
    {
        return $membership->id() !== null
            && (int) $row['id'] === $membership->id()?->value()
            && (int) $row['student_id'] === $membership->studentId()->value()
            && (string) $row['started_at'] === $this->formatTimestamp($membership->startedAt())
            && ($row['ended_at'] === null ? null : (string) $row['ended_at'])
                === $this->formatNullableTimestamp($membership->endedAt());
    }

    private function formatTimestamp(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function formatNullableTimestamp(?DateTimeImmutable $value): ?string
    {
        return $value === null ? null : $this->formatTimestamp($value);
    }

    private function familySelectSql(): string
    {
        return 'SELECT f.id, f.display_name, f.status_id, s.code AS status_code, '
            . 'st.code AS status_type_code FROM families f '
            . 'INNER JOIN statuses s ON s.id = f.status_id '
            . 'INNER JOIN status_types st ON st.id = s.status_type_id';
    }

    private function generatedId(string $entity): int
    {
        $id = (int) $this->connection->lastInsertId();
        if ($id <= 0) {
            throw new RuntimeException($entity . ' insert did not produce a positive database identity.');
        }

        return $id;
    }

    private function requireSingleInsertedRow(int $affectedRows, string $entity): void
    {
        if ($affectedRows !== 1) {
            throw new RuntimeException($entity . ' insert did not affect exactly one row.');
        }
    }

    private function requireZeroOrOneUpdatedRow(int $affectedRows, string $entity): void
    {
        if ($affectedRows !== 0 && $affectedRows !== 1) {
            throw new RuntimeException($entity . ' update did not affect zero or one row.');
        }
    }

    private function requirePersistedFamily(FamilyId $id, string $message): Family
    {
        $family = $this->findById($id);
        if ($family === null) {
            throw new RuntimeException($message);
        }

        return $family;
    }
}
