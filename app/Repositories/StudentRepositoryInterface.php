<?php

declare(strict_types=1);

namespace App\Repositories;

interface StudentRepositoryInterface
{
    public function listActiveStudents(): array;

    public function findById(int $id): ?array;

    public function findByPersonId(int $personId): ?array;

    public function findByStudentCode(string $studentCode): ?array;

    public function create(array $payload): int;

    public function updateById(int $id, array $payload): void;

    public function markAsDeleted(int $id, ?int $deletedBy): void;
}
