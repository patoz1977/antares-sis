<?php

declare(strict_types=1);

namespace Tests;

use App\IdentityAccess\Application\Contract\SessionManager;

final class BulkImportDeliverySessionManager implements SessionManager
{
    /** @var array<string, mixed> */
    private array $values = [];

    public function __construct(public ?int $userId = null)
    {
    }

    public function regenerateForUser(int $userId): void
    {
        $this->values = [];
        $this->userId = $userId;
    }

    public function authenticatedUserId(): ?int
    {
        return $this->userId;
    }

    public function put(string $key, mixed $value): void
    {
        $this->values[$key] = $value;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->values[$key] ?? $default;
        unset($this->values[$key]);

        return $value;
    }

    public function remove(string $key): void
    {
        unset($this->values[$key]);
    }

    public function destroy(): void
    {
        $this->values = [];
        $this->userId = null;
    }
}
