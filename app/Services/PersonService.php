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
        $persons = $this->personRepository->listActivePersons();

        return array_map(fn (array $person): array => $this->normalizePersonRow($person), $persons);
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

        return $this->normalizePersonRow($person);
    }

    public function create(array $data): int
    {
        $payload = $this->validateAndNormalize($data);

        if ($this->personRepository->existsIdentification($payload['document_number'])) {
            throw new InvalidArgumentException('The identification already exists.');
        }

        return $this->personRepository->create($payload);
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

        $this->personRepository->updateById($id, $payload);
    }

    public function deactivate(int $id): void
    {
        $existing = $this->find($id);

        if ($existing === null) {
            throw new InvalidArgumentException('Person not found.');
        }

        $deletedBy = $this->normalizeNullablePositiveInt($existing['updated_by'] ?? null);

        $this->personRepository->markAsDeleted($id, $deletedBy);
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

    private function normalizePersonRow(array $person): array
    {
        $person['id'] = $this->normalizeNullableNumericForView($person['id'] ?? null);
        $person['status_id'] = $this->normalizeNullableNumericForView($person['status_id'] ?? null);
        $person['document_type_id'] = $this->normalizeNullableNumericForView($person['document_type_id'] ?? null);
        $person['gender_id'] = $this->normalizeNullableNumericForView($person['gender_id'] ?? null);
        $person['nationality_id'] = $this->normalizeNullableNumericForView($person['nationality_id'] ?? null);

        return $person;
    }

    private function normalizeNullableNumericForView(mixed $value): ?int
    {
        if (!is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }
}
