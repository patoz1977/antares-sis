<?php

declare(strict_types=1);

namespace App\Repositories;

class FamilyStudentRepository extends BaseRepository implements FamilyStudentRepositoryInterface
{
    public function listActiveByFamilyId(int $familyId): array
    {
        $sql = 'SELECT id, status_id, family_id, student_id, created_at, updated_at, deleted_at, created_by, updated_by, deleted_by FROM family_students WHERE family_id = :familyId AND deleted_at IS NULL ORDER BY id ASC';

        return $this->fetchAll($sql, [':familyId' => $familyId]);
    }

    public function findById(int $id): ?array
    {
        $sql = 'SELECT id, status_id, family_id, student_id, created_at, updated_at, deleted_at, created_by, updated_by, deleted_by FROM family_students WHERE id = :id';

        return $this->fetchOne($sql, [':id' => $id]);
    }

    public function findByFamilyAndStudent(int $familyId, int $studentId): ?array
    {
        $sql = 'SELECT id, status_id, family_id, student_id, created_at, updated_at, deleted_at, created_by, updated_by, deleted_by FROM family_students WHERE family_id = :familyId AND student_id = :studentId';

        return $this->fetchOne($sql, [
            ':familyId' => $familyId,
            ':studentId' => $studentId,
        ]);
    }

    public function create(array $payload): int
    {
        $sql = 'INSERT INTO family_students (status_id, family_id, student_id, created_at, updated_at, deleted_at, created_by, updated_by, deleted_by) VALUES (:statusId, :familyId, :studentId, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, NULL, :createdBy, :updatedBy, NULL)';

        $this->execute($sql, [
            ':statusId' => $payload['status_id'],
            ':familyId' => $payload['family_id'],
            ':studentId' => $payload['student_id'],
            ':createdBy' => $payload['created_by'],
            ':updatedBy' => $payload['updated_by'],
        ]);

        return (int) $this->lastInsertId();
    }

    public function updateById(int $id, array $payload): void
    {
        $sql = 'UPDATE family_students SET status_id = :statusId, family_id = :familyId, student_id = :studentId, updated_at = CURRENT_TIMESTAMP, updated_by = :updatedBy WHERE id = :id';

        $this->execute($sql, [
            ':id' => $id,
            ':statusId' => $payload['status_id'],
            ':familyId' => $payload['family_id'],
            ':studentId' => $payload['student_id'],
            ':updatedBy' => $payload['updated_by'],
        ]);
    }

    public function markAsDeleted(int $id, ?int $deletedBy): void
    {
        $sql = 'UPDATE family_students SET deleted_at = CURRENT_TIMESTAMP, deleted_by = :deletedBy, updated_at = CURRENT_TIMESTAMP WHERE id = :id';

        $this->execute($sql, [
            ':id' => $id,
            ':deletedBy' => $deletedBy,
        ]);
    }
}
