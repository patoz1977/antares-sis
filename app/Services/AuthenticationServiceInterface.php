<?php

declare(strict_types=1);

namespace App\Services;

use Core\Security\AuthenticatedUserProviderInterface;

interface AuthenticationServiceInterface extends AuthenticatedUserProviderInterface
{
    public function attempt(string $username, string $password): bool;

    public function logout(): void;

    public function check(): bool;

    public function user(): ?array;

    public function id(): ?int;
}
