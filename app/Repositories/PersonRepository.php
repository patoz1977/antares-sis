<?php

declare(strict_types=1);

namespace App\Repositories;

class PersonRepository extends BaseRepository
{
    public function listActivePersons(): array
    {
        $sql = 'SELECT id, status_id, document_type_id, document_number, first_name, middle_name, last_name, second_last_name, preferred_name, birth_date, gender_id, nationality_id, email, mobile_phone, home_phone, address, notes, created_at, updated_at, deleted_at, created_by, updated_by, deleted_by FROM persons WHERE deleted_at IS NULL ORDER BY last_name ASC, first_name ASC, id ASC';

        return $this->fetchAll($sql);
    }

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

    public function create(array $payload): int
    {
        $sql = 'INSERT INTO persons (status_id, document_type_id, document_number, first_name, middle_name, last_name, second_last_name, preferred_name, birth_date, gender_id, nationality_id, email, mobile_phone, home_phone, address, notes, created_at, updated_at, deleted_at, created_by, updated_by, deleted_by) VALUES (:statusId, :documentTypeId, :documentNumber, :firstName, :middleName, :lastName, :secondLastName, :preferredName, :birthDate, :genderId, :nationalityId, :email, :mobilePhone, :homePhone, :address, :notes, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, NULL, :createdBy, :updatedBy, NULL)';

        $this->execute($sql, [
            ':statusId' => $payload['status_id'],
            ':documentTypeId' => $payload['document_type_id'],
            ':documentNumber' => $payload['document_number'],
            ':firstName' => $payload['first_name'],
            ':middleName' => $payload['middle_name'],
            ':lastName' => $payload['last_name'],
            ':secondLastName' => $payload['second_last_name'],
            ':preferredName' => $payload['preferred_name'],
            ':birthDate' => $payload['birth_date'],
            ':genderId' => $payload['gender_id'],
            ':nationalityId' => $payload['nationality_id'],
            ':email' => $payload['email'],
            ':mobilePhone' => $payload['mobile_phone'],
            ':homePhone' => $payload['home_phone'],
            ':address' => $payload['address'],
            ':notes' => $payload['notes'],
            ':createdBy' => $payload['created_by'],
            ':updatedBy' => $payload['updated_by'],
        ]);

        return (int) $this->lastInsertId();
    }

    public function updateById(int $id, array $payload): void
    {
        $sql = 'UPDATE persons SET status_id = :statusId, document_type_id = :documentTypeId, document_number = :documentNumber, first_name = :firstName, middle_name = :middleName, last_name = :lastName, second_last_name = :secondLastName, preferred_name = :preferredName, birth_date = :birthDate, gender_id = :genderId, nationality_id = :nationalityId, email = :email, mobile_phone = :mobilePhone, home_phone = :homePhone, address = :address, notes = :notes, updated_at = CURRENT_TIMESTAMP, updated_by = :updatedBy WHERE id = :id';

        $this->execute($sql, [
            ':id' => $id,
            ':statusId' => $payload['status_id'],
            ':documentTypeId' => $payload['document_type_id'],
            ':documentNumber' => $payload['document_number'],
            ':firstName' => $payload['first_name'],
            ':middleName' => $payload['middle_name'],
            ':lastName' => $payload['last_name'],
            ':secondLastName' => $payload['second_last_name'],
            ':preferredName' => $payload['preferred_name'],
            ':birthDate' => $payload['birth_date'],
            ':genderId' => $payload['gender_id'],
            ':nationalityId' => $payload['nationality_id'],
            ':email' => $payload['email'],
            ':mobilePhone' => $payload['mobile_phone'],
            ':homePhone' => $payload['home_phone'],
            ':address' => $payload['address'],
            ':notes' => $payload['notes'],
            ':updatedBy' => $payload['updated_by'],
        ]);
    }

    public function markAsDeleted(int $id, ?int $deletedBy): void
    {
        $sql = 'UPDATE persons SET deleted_at = CURRENT_TIMESTAMP, deleted_by = :deletedBy, updated_at = CURRENT_TIMESTAMP WHERE id = :id';

        $this->execute($sql, [
            ':id' => $id,
            ':deletedBy' => $deletedBy,
        ]);
    }
}
