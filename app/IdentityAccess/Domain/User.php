<?php

declare(strict_types=1);

namespace App\IdentityAccess\Domain;

use App\IdentityAccess\Domain\Exception\InvalidUserState;
use App\IdentityAccess\Domain\ValueObject\LoginIdentifier;
use App\IdentityAccess\Domain\ValueObject\PasswordHash;
use App\IdentityAccess\Domain\ValueObject\PersonId;
use App\IdentityAccess\Domain\ValueObject\UserId;
use DateTimeImmutable;

final class User
{
    public function __construct(
        private readonly ?UserId $id,
        private readonly PersonId $personId,
        private LoginIdentifier $loginIdentifier,
        private PasswordHash $passwordHash,
        private UserStatus $status,
        private int $failedLoginAttempts = 0,
        private ?DateTimeImmutable $lockedAt = null,
        private ?DateTimeImmutable $lastAccessAt = null,
    ) {
        if ($failedLoginAttempts < 0) {
            throw new InvalidUserState('Failed login attempts cannot be negative.');
        }
    }

    public function id(): ?UserId
    {
        return $this->id;
    }

    public function personId(): PersonId
    {
        return $this->personId;
    }

    public function loginIdentifier(): LoginIdentifier
    {
        return $this->loginIdentifier;
    }

    public function passwordHash(): PasswordHash
    {
        return $this->passwordHash;
    }

    public function changeLoginIdentifier(LoginIdentifier $loginIdentifier): void
    {
        $this->loginIdentifier = $loginIdentifier;
    }

    public function changePasswordHash(PasswordHash $passwordHash): void
    {
        $this->passwordHash = $passwordHash;
    }

    public function status(): UserStatus
    {
        return $this->status;
    }

    public function failedLoginAttempts(): int
    {
        return $this->failedLoginAttempts;
    }

    public function lockedAt(): ?DateTimeImmutable
    {
        return $this->lockedAt;
    }

    public function lastAccessAt(): ?DateTimeImmutable
    {
        return $this->lastAccessAt;
    }

    public function isDisabled(): bool
    {
        return $this->status === UserStatus::Disabled;
    }

    public function isTemporarilyLocked(DateTimeImmutable $now, int $durationSeconds): bool
    {
        if ($durationSeconds <= 0) {
            throw new InvalidUserState('Lockout duration must be positive.');
        }

        return $this->lockedAt !== null
            && $now->getTimestamp() < $this->lockedAt->getTimestamp() + $durationSeconds;
    }

    public function clearExpiredLock(DateTimeImmutable $now, int $durationSeconds): bool
    {
        if ($this->lockedAt === null || $this->isTemporarilyLocked($now, $durationSeconds)) {
            return false;
        }

        $this->failedLoginAttempts = 0;
        $this->lockedAt = null;

        return true;
    }

    public function recordFailedLogin(DateTimeImmutable $now, int $maximumAttempts): bool
    {
        if ($maximumAttempts <= 0) {
            throw new InvalidUserState('Maximum failed attempts must be positive.');
        }

        $this->failedLoginAttempts++;

        if ($this->failedLoginAttempts < $maximumAttempts) {
            return false;
        }

        $this->failedLoginAttempts = $maximumAttempts;
        $this->lockedAt = $now;

        return true;
    }

    public function recordSuccessfulAuthentication(DateTimeImmutable $now): void
    {
        $this->failedLoginAttempts = 0;
        $this->lockedAt = null;
        $this->lastAccessAt = $now;
    }
}
