<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application;

use App\IdentityAccess\Application\Exception\InvalidAuthenticationPolicy;

final readonly class AuthenticationPolicy
{
    public function __construct(
        private int $maximumFailedAttempts,
        private int $lockoutDurationSeconds,
    ) {
        if ($maximumFailedAttempts <= 0 || $lockoutDurationSeconds <= 0) {
            throw new InvalidAuthenticationPolicy(
                'Authentication lockout settings must be positive integers.'
            );
        }
    }

    public function maximumFailedAttempts(): int
    {
        return $this->maximumFailedAttempts;
    }

    public function lockoutDurationSeconds(): int
    {
        return $this->lockoutDurationSeconds;
    }
}
