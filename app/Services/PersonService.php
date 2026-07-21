<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\PersonRepository;
use InvalidArgumentException;

class PersonService implements PersonServiceInterface
{
    public function __construct(
        private PersonRepository $personRepository
    ) {
    }

    public function list(): array
    {
        $sql = 'SELECT id, status_id, document_type_id, document_number, first_name, middle_name, last_name, second_last_name, preferred_name, birth_date, gender_id, nationality_id, email, mobile_phone, home_phone, address, notes, created_at, updated_at, deleted_at, created_by, updated_by, deleted_by FROM persons WHERE deleted_at IS NULL ORDER BY last_name ASC, first_name ASC, id ASC';

        return $this->repositoryFetchAll($sql);
    }

    public function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $person = $this->personRepository->findById($id);

        if ($person === null) {
            return null;
        }

        if (($person['deleted_at'] ?? null) !== null) {
            return null;
        }

        return $person;
    }

    public function create(array $data): int
    {
        $payload = $this->validateAndNormalize($data);

        if ($this->personRepository->existsIdentification($payload['document_number'])) {
            throw new InvalidArgumentException('The identification already exists.');
        }

        $sql = 'INSERT INTO persons (status_id, document_type_id, document_number, first_name, middle_name, last_name, second_last_name, preferred_name, birth_date, gender_id, nationality_id, email, mobile_phone, home_phone, address, notes, created_at, updated_at, deleted_at, created_by, updated_by, deleted_by) VALUES (:statusId, :documentTypeId, :documentNumber, :firstName, :middleName, :lastName, :secondLastName, :preferredName, :birthDate, :genderId, :nationalityId, :email, :mobilePhone, :homePhone, :address, :notes, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, NULL, :createdBy, :updatedBy, NULL)';

        $this->repositoryExecute($sql, [
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

        return (int) $this->repositoryLastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $existing = $this->find($id);

        if ($existing === null) {
            throw new InvalidArgumentException('Person not found.');
        }

        $payload = $this->validateAndNormalize($data);

        $personWithSameIdentification = $this->personRepository->findByIdentification($payload['document_number']);

        if (
            $personWithSameIdentification !== null
            && (int) ($personWithSameIdentification['id'] ?? 0) !== $id
        ) {
            throw new InvalidArgumentException('The identification already exists.');
        }

        $sql = 'UPDATE persons SET status_id = :statusId, document_type_id = :documentTypeId, document_number = :documentNumber, first_name = :firstName, middle_name = :middleName, last_name = :lastName, second_last_name = :secondLastName, preferred_name = :preferredName, birth_date = :birthDate, gender_id = :genderId, nationality_id = :nationalityId, email = :email, mobile_phone = :mobilePhone, home_phone = :homePhone, address = :address, notes = :notes, updated_at = CURRENT_TIMESTAMP, updated_by = :updatedBy WHERE id = :id';

        $this->repositoryExecute($sql, [
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

    public function deactivate(int $id): void
    {
        $existing = $this->find($id);

        if ($existing === null) {
            throw new InvalidArgumentException('Person not found.');
        }

        $deletedBy = $this->normalizeNullablePositiveInt($existing['updated_by'] ?? null);

        $sql = 'UPDATE persons SET deleted_at = CURRENT_TIMESTAMP, deleted_by = :deletedBy, updated_at = CURRENT_TIMESTAMP WHERE id = :id';

        $this->repositoryExecute($sql, [
            ':id' => $id,
            ':deletedBy' => $deletedBy,
        ]);
    }

    private function validateAndNormalize(array $data): array
    {
        $statusId = $this->requirePositiveInt($data, 'status_id');
        $documentTypeId = $this->requirePositiveInt($data, 'document_type_id');
        $documentNumber = $this->requireNonEmptyString($data, 'document_number');
        $firstName = $this->requireNonEmptyString($data, 'first_name');
        $lastName = $this->requireNonEmptyString($data, 'last_name');

        return [
            'status_id' => $statusId,
            'document_type_id' => $documentTypeId,
            'document_number' => $documentNumber,
            'first_name' => $firstName,
            'middle_name' => $this->normalizeNullableString($data['middle_name'] ?? null),
            'last_name' => $lastName,
            'second_last_name' => $this->normalizeNullableString($data['second_last_name'] ?? null),
            'preferred_name' => $this->normalizeNullableString($data['preferred_name'] ?? null),
            'birth_date' => $this->normalizeBirthDate($data['birth_date'] ?? null),
            'gender_id' => $this->normalizeNullablePositiveInt($data['gender_id'] ?? null),
            'nationality_id' => $this->normalizeNullablePositiveInt($data['nationality_id'] ?? null),
            'email' => $this->normalizeNullableString($data['email'] ?? null),
            'mobile_phone' => $this->normalizeNullableString($data['mobile_phone'] ?? null),
            'home_phone' => $this->normalizeNullableString($data['home_phone'] ?? null),
            'address' => $this->normalizeNullableString($data['address'] ?? null),
            'notes' => $this->normalizeNullableString($data['notes'] ?? null),
            'created_by' => $this->normalizeNullablePositiveInt($data['created_by'] ?? null),
            'updated_by' => $this->normalizeNullablePositiveInt($data['updated_by'] ?? null),
        ];
    }

    private function requirePositiveInt(array $data, string $field): int
    {
        $value = $data[$field] ?? null;

        if (!is_numeric($value) || (int) $value <= 0) {
            throw new InvalidArgumentException(sprintf('The field %s is required.', $field));
        }

        return (int) $value;
    }

    private function requireNonEmptyString(array $data, string $field): string
    {
        $value = $data[$field] ?? null;

        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('The field %s is required.', $field));
        }

        $normalized = trim($value);

        if ($normalized === '') {
            throw new InvalidArgumentException(sprintf('The field %s is required.', $field));
        }

        return $normalized;
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }

    private function normalizeNullablePositiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value) || (int) $value <= 0) {
            throw new InvalidArgumentException('Invalid numeric value.');
        }

        return (int) $value;
    }

    private function normalizeBirthDate(mixed $value): ?string
    {
        $birthDate = $this->normalizeNullableString($value);

        if ($birthDate === null) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $birthDate);

        if ($date === false || $date->format('Y-m-d') !== $birthDate) {
            throw new InvalidArgumentException('Invalid birth_date format. Use Y-m-d.');
        }

        return $birthDate;
    }

    private function repositoryFetchAll(string $sql, array $params = []): array
    {
        $fetchAll = \Closure::bind(
            function (string $sql, array $params = []): array {
                return $this->fetchAll($sql, $params);
            },
            $this->personRepository,
            PersonRepository::class
        );

        return $fetchAll($sql, $params);
    }

    private function repositoryExecute(string $sql, array $params = []): bool
    {
        $execute = \Closure::bind(
            function (string $sql, array $params = []): bool {
                return $this->execute($sql, $params);
            },
            $this->personRepository,
            PersonRepository::class
        );

        return $execute($sql, $params);
    }

    private function repositoryLastInsertId(): string
    {
        $lastInsertId = \Closure::bind(
            function (): string {
                return $this->lastInsertId();
            },
            $this->personRepository,
            PersonRepository::class
        );

        return $lastInsertId();
    }
}
