<?php

declare(strict_types=1);

namespace App\Repositories;

class PersonRepository extends BaseRepository
{
    public function findById(int $id): ?array
    {
        $sql = 'SELECT id, status_id, document_type_id, document_number, first_name, middle_name, last_name, second_last_name, preferred_name, birth_date, gender_id, nationality_id, email, mobile_phone, home_phone, address, notes, created_at, updated_at, deleted_at, created_by, updated_by, deleted_by FROM persons WHERE id = :id';

        return $this->fetchOne($sql, [':id' => $id]);
    }

    public function findByIdentification(string $identification): ?array
    {
        $sql = 'SELECT id, status_id, document_type_id, document_number, first_name, middle_name, last_name, second_last_name, preferred_name, birth_date, gender_id, nationality_id, email, mobile_phone, home_phone, address, notes, created_at, updated_at, deleted_at, created_by, updated_by, deleted_by FROM persons WHERE document_number = :documentNumber';

        return $this->fetchOne($sql, [':documentNumber' => $identification]);
    }

    public function existsIdentification(string $identification): bool
    {
        $sql = 'SELECT id FROM persons WHERE document_number = :documentNumber';
        $result = $this->fetchOne($sql, [':documentNumber' => $identification]);

        return $result !== null;
    }
}
