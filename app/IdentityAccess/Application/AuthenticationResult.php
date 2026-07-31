<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application;

final readonly class AuthenticationResult
{
    private const GENERIC_FAILURE_MESSAGE = 'Invalid credentials.';

    private function __construct(
        private bool $successful,
        private ?int $userId,
    ) {
    }

    public static function success(int $userId): self
    {
        return new self(true, $userId);
    }

    public static function failure(): self
    {
        return new self(false, null);
    }

    public function isSuccessful(): bool
    {
        return $this->successful;
    }

    public function userId(): ?int
    {
        return $this->userId;
    }

    public function externalMessage(): string
    {
        return self::GENERIC_FAILURE_MESSAGE;
    }
}
