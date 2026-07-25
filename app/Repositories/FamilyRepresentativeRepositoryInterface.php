<?php

declare(strict_types=1);

namespace App\Repositories;

interface FamilyRepresentativeRepositoryInterface
{
    public function listActiveByFamilyId(int $familyId): array;

    public function findById(int $id): ?array;

    public function findByFamilyAndRepresentative(int $familyId, int $representativeId): ?array;

    public function create(array $payload): int;

    public function updateById(int $id, array $payload): void;

    public function markAsDeleted(int $id, ?int $deletedBy): void;
}
