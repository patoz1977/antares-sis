<?php

declare(strict_types=1);

namespace App\Repositories;

class RepresentativeRepository extends BaseRepository implements RepresentativeRepositoryInterface
{
    public function listActiveRepresentatives(): array
    {
        $sql = 'SELECT id, person_id, status_id, occupation, company, work_phone, created_at, updated_at, deleted_at, created_by, updated_by, deleted_by FROM representatives WHERE deleted_at IS NULL ORDER BY id ASC';

        return $this->fetchAll($sql);
    }

    public function findById(int $id): ?array
    {
        $sql = 'SELECT id, person_id, status_id, occupation, company, work_phone, created_at, updated_at, deleted_at, created_by, updated_by, deleted_by FROM representatives WHERE id = :id';

        return $this->fetchOne($sql, [':id' => $id]);
    }

    public function findByPersonId(int $personId): ?array
    {
        $sql = 'SELECT id, person_id, status_id, occupation, company, work_phone, created_at, updated_at, deleted_at, created_by, updated_by, deleted_by FROM representatives WHERE person_id = :personId';

        return $this->fetchOne($sql, [':personId' => $personId]);
    }

    public function create(array $payload): int
    {
        $sql = 'INSERT INTO representatives (person_id, status_id, occupation, company, work_phone, created_at, updated_at, deleted_at, created_by, updated_by, deleted_by) VALUES (:personId, :statusId, :occupation, :company, :workPhone, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, NULL, :createdBy, :updatedBy, NULL)';

        $this->execute($sql, [
            ':personId' => $payload['person_id'],
            ':statusId' => $payload['status_id'],
            ':occupation' => $payload['occupation'],
            ':company' => $payload['company'],
            ':workPhone' => $payload['work_phone'],
            ':createdBy' => $payload['created_by'],
            ':updatedBy' => $payload['updated_by'],
        ]);

        return (int) $this->lastInsertId();
    }

    public function updateById(int $id, array $payload): void
    {
        $sql = 'UPDATE representatives SET person_id = :personId, status_id = :statusId, occupation = :occupation, company = :company, work_phone = :workPhone, updated_at = CURRENT_TIMESTAMP, updated_by = :updatedBy WHERE id = :id';

        $this->execute($sql, [
            ':id' => $id,
            ':personId' => $payload['person_id'],
            ':statusId' => $payload['status_id'],
            ':occupation' => $payload['occupation'],
            ':company' => $payload['company'],
            ':workPhone' => $payload['work_phone'],
            ':updatedBy' => $payload['updated_by'],
        ]);
    }

    public function markAsDeleted(int $id, ?int $deletedBy): void
    {
        $sql = 'UPDATE representatives SET deleted_at = CURRENT_TIMESTAMP, deleted_by = :deletedBy, updated_at = CURRENT_TIMESTAMP WHERE id = :id';

        $this->execute($sql, [
            ':id' => $id,
            ':deletedBy' => $deletedBy,
        ]);
    }
}
