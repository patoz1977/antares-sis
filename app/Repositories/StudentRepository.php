<?php

declare(strict_types=1);

namespace App\Repositories;

class StudentRepository extends BaseRepository implements StudentRepositoryInterface
{
    public function listActiveStudents(): array
    {
        $sql = 'SELECT id, person_id, status_id, student_code, admission_date, created_at, updated_at, deleted_at, created_by, updated_by, deleted_by FROM students WHERE deleted_at IS NULL ORDER BY student_code ASC, id ASC';

        return $this->fetchAll($sql);
    }

    public function findById(int $id): ?array
    {
        $sql = 'SELECT id, person_id, status_id, student_code, admission_date, created_at, updated_at, deleted_at, created_by, updated_by, deleted_by FROM students WHERE id = :id';

        return $this->fetchOne($sql, [':id' => $id]);
    }

    public function findByPersonId(int $personId): ?array
    {
        $sql = 'SELECT id, person_id, status_id, student_code, admission_date, created_at, updated_at, deleted_at, created_by, updated_by, deleted_by FROM students WHERE person_id = :personId';

        return $this->fetchOne($sql, [':personId' => $personId]);
    }

    public function findByStudentCode(string $studentCode): ?array
    {
        $sql = 'SELECT id, person_id, status_id, student_code, admission_date, created_at, updated_at, deleted_at, created_by, updated_by, deleted_by FROM students WHERE student_code = :studentCode';

        return $this->fetchOne($sql, [':studentCode' => $studentCode]);
    }

    public function create(array $payload): int
    {
        $sql = 'INSERT INTO students (person_id, status_id, student_code, admission_date, created_at, updated_at, deleted_at, created_by, updated_by, deleted_by) VALUES (:personId, :statusId, :studentCode, :admissionDate, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, NULL, :createdBy, :updatedBy, NULL)';

        $this->execute($sql, [
            ':personId' => $payload['person_id'],
            ':statusId' => $payload['status_id'],
            ':studentCode' => $payload['student_code'],
            ':admissionDate' => $payload['admission_date'],
            ':createdBy' => $payload['created_by'],
            ':updatedBy' => $payload['updated_by'],
        ]);

        return (int) $this->lastInsertId();
    }

    public function updateById(int $id, array $payload): void
    {
        $sql = 'UPDATE students SET person_id = :personId, status_id = :statusId, student_code = :studentCode, admission_date = :admissionDate, updated_at = CURRENT_TIMESTAMP, updated_by = :updatedBy WHERE id = :id';

        $this->execute($sql, [
            ':id' => $id,
            ':personId' => $payload['person_id'],
            ':statusId' => $payload['status_id'],
            ':studentCode' => $payload['student_code'],
            ':admissionDate' => $payload['admission_date'],
            ':updatedBy' => $payload['updated_by'],
        ]);
    }

    public function markAsDeleted(int $id, ?int $deletedBy): void
    {
        $sql = 'UPDATE students SET deleted_at = CURRENT_TIMESTAMP, deleted_by = :deletedBy, updated_at = CURRENT_TIMESTAMP WHERE id = :id';

        $this->execute($sql, [
            ':id' => $id,
            ':deletedBy' => $deletedBy,
        ]);
    }
}
