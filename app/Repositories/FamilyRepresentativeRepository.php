<?php

declare(strict_types=1);

namespace App\Repositories;

class FamilyRepresentativeRepository extends BaseRepository implements FamilyRepresentativeRepositoryInterface
{
    public function listActiveByFamilyId(int $familyId): array
    {
        $sql = 'SELECT id, status_id, family_id, representative_id, relationship_type_id, is_primary, receives_notifications, created_at, updated_at, deleted_at, created_by, updated_by, deleted_by FROM family_representatives WHERE family_id = :familyId AND deleted_at IS NULL ORDER BY id ASC';

        return $this->fetchAll($sql, [':familyId' => $familyId]);
    }

    public function findById(int $id): ?array
    {
        $sql = 'SELECT id, status_id, family_id, representative_id, relationship_type_id, is_primary, receives_notifications, created_at, updated_at, deleted_at, created_by, updated_by, deleted_by FROM family_representatives WHERE id = :id';

        return $this->fetchOne($sql, [':id' => $id]);
    }

    public function findByFamilyAndRepresentative(int $familyId, int $representativeId): ?array
    {
        $sql = 'SELECT id, status_id, family_id, representative_id, relationship_type_id, is_primary, receives_notifications, created_at, updated_at, deleted_at, created_by, updated_by, deleted_by FROM family_representatives WHERE family_id = :familyId AND representative_id = :representativeId';

        return $this->fetchOne($sql, [
            ':familyId' => $familyId,
            ':representativeId' => $representativeId,
        ]);
    }

    public function create(array $payload): int
    {
        $sql = 'INSERT INTO family_representatives (status_id, family_id, representative_id, relationship_type_id, is_primary, receives_notifications, created_at, updated_at, deleted_at, created_by, updated_by, deleted_by) VALUES (:statusId, :familyId, :representativeId, :relationshipTypeId, :isPrimary, :receivesNotifications, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, NULL, :createdBy, :updatedBy, NULL)';

        $this->execute($sql, [
            ':statusId' => $payload['status_id'],
            ':familyId' => $payload['family_id'],
            ':representativeId' => $payload['representative_id'],
            ':relationshipTypeId' => $payload['relationship_type_id'],
            ':isPrimary' => $payload['is_primary'],
            ':receivesNotifications' => $payload['receives_notifications'],
            ':createdBy' => $payload['created_by'],
            ':updatedBy' => $payload['updated_by'],
        ]);

        return (int) $this->lastInsertId();
    }

    public function updateById(int $id, array $payload): void
    {
        $sql = 'UPDATE family_representatives SET status_id = :statusId, family_id = :familyId, representative_id = :representativeId, relationship_type_id = :relationshipTypeId, is_primary = :isPrimary, receives_notifications = :receivesNotifications, updated_at = CURRENT_TIMESTAMP, updated_by = :updatedBy WHERE id = :id';

        $this->execute($sql, [
            ':id' => $id,
            ':statusId' => $payload['status_id'],
            ':familyId' => $payload['family_id'],
            ':representativeId' => $payload['representative_id'],
            ':relationshipTypeId' => $payload['relationship_type_id'],
            ':isPrimary' => $payload['is_primary'],
            ':receivesNotifications' => $payload['receives_notifications'],
            ':updatedBy' => $payload['updated_by'],
        ]);
    }

    public function markAsDeleted(int $id, ?int $deletedBy): void
    {
        $sql = 'UPDATE family_representatives SET deleted_at = CURRENT_TIMESTAMP, deleted_by = :deletedBy, updated_at = CURRENT_TIMESTAMP WHERE id = :id';

        $this->execute($sql, [
            ':id' => $id,
            ':deletedBy' => $deletedBy,
        ]);
    }
}
