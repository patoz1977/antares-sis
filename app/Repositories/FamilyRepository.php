<?php

declare(strict_types=1);

namespace App\Repositories;

class FamilyRepository extends BaseRepository
{
    public function listActiveFamilies(): array
    {
        $sql = 'SELECT id, status_id, family_code, name, notes, created_at, updated_at, deleted_at, created_by, updated_by, deleted_by FROM families WHERE deleted_at IS NULL ORDER BY family_code ASC, id ASC';

        return $this->fetchAll($sql);
    }

    public function findById(int $id): ?array
    {
        $sql = 'SELECT id, status_id, family_code, name, notes, created_at, updated_at, deleted_at, created_by, updated_by, deleted_by FROM families WHERE id = :id';

        return $this->fetchOne($sql, [':id' => $id]);
    }

    public function findByFamilyCode(string $familyCode): ?array
    {
        $sql = 'SELECT id, status_id, family_code, name, notes, created_at, updated_at, deleted_at, created_by, updated_by, deleted_by FROM families WHERE family_code = :familyCode';

        return $this->fetchOne($sql, [':familyCode' => $familyCode]);
    }

    public function existsFamilyCode(string $familyCode): bool
    {
        $sql = 'SELECT id FROM families WHERE family_code = :familyCode';
        $result = $this->fetchOne($sql, [':familyCode' => $familyCode]);

        return $result !== null;
    }

    public function create(array $payload): int
    {
        $sql = 'INSERT INTO families (status_id, family_code, name, notes, created_at, updated_at, deleted_at, created_by, updated_by, deleted_by) VALUES (:statusId, :familyCode, :name, :notes, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, NULL, :createdBy, :updatedBy, NULL)';

        $this->execute($sql, [
            ':statusId' => $payload['status_id'],
            ':familyCode' => $payload['family_code'],
            ':name' => $payload['name'],
            ':notes' => $payload['notes'],
            ':createdBy' => $payload['created_by'],
            ':updatedBy' => $payload['updated_by'],
        ]);

        return (int) $this->lastInsertId();
    }

    public function updateById(int $id, array $payload): void
    {
        $sql = 'UPDATE families SET status_id = :statusId, family_code = :familyCode, name = :name, notes = :notes, updated_at = CURRENT_TIMESTAMP, updated_by = :updatedBy WHERE id = :id';

        $this->execute($sql, [
            ':id' => $id,
            ':statusId' => $payload['status_id'],
            ':familyCode' => $payload['family_code'],
            ':name' => $payload['name'],
            ':notes' => $payload['notes'],
            ':updatedBy' => $payload['updated_by'],
        ]);
    }

    public function markAsDeleted(int $id, ?int $deletedBy): void
    {
        $sql = 'UPDATE families SET deleted_at = CURRENT_TIMESTAMP, deleted_by = :deletedBy, updated_at = CURRENT_TIMESTAMP WHERE id = :id';

        $this->execute($sql, [
            ':id' => $id,
            ':deletedBy' => $deletedBy,
        ]);
    }
}
