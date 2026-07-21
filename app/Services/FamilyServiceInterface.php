<?php

declare(strict_types=1);

namespace App\Services;

interface FamilyServiceInterface
{
    public function list(): array;

    public function find(int $id): ?array;

    public function create(array $data): int;

    public function update(int $id, array $data): void;

    public function deactivate(int $id): void;
}
