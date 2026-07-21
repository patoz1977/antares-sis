<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\FamilyRepository;
use InvalidArgumentException;

class FamilyService implements FamilyServiceInterface
{
    public function __construct(
        private FamilyRepository $familyRepository
    ) {
    }

    public function list(): array
    {
        $families = $this->familyRepository->listActiveFamilies();

        return array_map(fn (array $family): array => $this->normalizeFamilyRow($family), $families);
    }

    public function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $family = $this->familyRepository->findById($id);

        if ($family === null) {
            return null;
        }

        if (($family['deleted_at'] ?? null) !== null) {
            return null;
        }

        return $this->normalizeFamilyRow($family);
    }

    public function create(array $data): int
    {
        $payload = $this->validateAndNormalize($data);

        if ($this->familyRepository->existsFamilyCode($payload['family_code'])) {
            throw new InvalidArgumentException('The family code already exists.');
        }

        return $this->familyRepository->create($payload);
    }

    public function update(int $id, array $data): void
    {
        $existing = $this->find($id);

        if ($existing === null) {
            throw new InvalidArgumentException('Family not found.');
        }

        $payload = $this->validateAndNormalize($data);

        $familyWithSameCode = $this->familyRepository->findByFamilyCode($payload['family_code']);

        if (
            $familyWithSameCode !== null
            && (int) ($familyWithSameCode['id'] ?? 0) !== $id
        ) {
            throw new InvalidArgumentException('The family code already exists.');
        }

        $this->familyRepository->updateById($id, $payload);
    }

    public function deactivate(int $id): void
    {
        $existing = $this->find($id);

        if ($existing === null) {
            throw new InvalidArgumentException('Family not found.');
        }

        $deletedBy = $this->normalizeNullablePositiveInt($existing['updated_by'] ?? null);

        $this->familyRepository->markAsDeleted($id, $deletedBy);
    }

    private function validateAndNormalize(array $data): array
    {
        $statusId = $this->requirePositiveInt($data, 'status_id');
        $familyCode = $this->requireNonEmptyString($data, 'family_code', 30);

        return [
            'status_id' => $statusId,
            'family_code' => $familyCode,
            'name' => $this->normalizeNullableString($data['name'] ?? null, 150),
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

    private function requireNonEmptyString(array $data, string $field, ?int $maxLength = null): string
    {
        $value = $data[$field] ?? null;

        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('The field %s is required.', $field));
        }

        $normalized = trim($value);

        if ($normalized === '') {
            throw new InvalidArgumentException(sprintf('The field %s is required.', $field));
        }

        if ($maxLength !== null && mb_strlen($normalized) > $maxLength) {
            throw new InvalidArgumentException(sprintf('The field %s exceeds the allowed length.', $field));
        }

        return $normalized;
    }

    private function normalizeNullableString(mixed $value, ?int $maxLength = null): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        if ($normalized === '') {
            return null;
        }

        if ($maxLength !== null && mb_strlen($normalized) > $maxLength) {
            throw new InvalidArgumentException('Invalid string value.');
        }

        return $normalized;
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

    private function normalizeFamilyRow(array $family): array
    {
        $family['id'] = $this->normalizeNullableNumericForView($family['id'] ?? null);
        $family['status_id'] = $this->normalizeNullableNumericForView($family['status_id'] ?? null);

        return $family;
    }

    private function normalizeNullableNumericForView(mixed $value): ?int
    {
        if (!is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }
}
