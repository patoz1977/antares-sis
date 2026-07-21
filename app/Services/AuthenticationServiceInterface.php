<?php

declare(strict_types=1);

namespace App\Services;

interface AuthenticationServiceInterface
{
    public function attempt(string $username, string $password): bool;

    public function logout(): void;

    public function check(): bool;

    public function user(): ?array;

    public function id(): ?int;
}
