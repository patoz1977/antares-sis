<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Contract;

interface SessionManager
{
    public function regenerateForUser(int $userId): void;

    public function authenticatedUserId(): ?int;

    public function put(string $key, mixed $value): void;

    public function get(string $key, mixed $default = null): mixed;

    public function pull(string $key, mixed $default = null): mixed;

    public function remove(string $key): void;

    public function destroy(): void;
}
