<?php

declare(strict_types=1);

namespace Tests;

use App\IdentityAccess\Domain\User;
use App\IdentityAccess\Domain\UserRepository;
use App\IdentityAccess\Domain\ValueObject\LoginIdentifier;
use App\IdentityAccess\Domain\ValueObject\PersonId;
use App\IdentityAccess\Domain\ValueObject\UserId;
use RuntimeException;
use Throwable;

final class InMemoryRepresentativeUserRepository implements UserRepository
{
    /** @var array<int, User> */
    private array $users = [];

    private int $saveCalls = 0;

    private ?Throwable $nextSaveFailure = null;

    private bool $returnWithoutId = false;

    public function __construct(private int $nextId = 900)
    {
    }

    public function seed(User $user): void
    {
        $id = $user->id();
        if ($id === null) {
            throw new RuntimeException('Seeded User must have an identity.');
        }

        $this->users[$id->value()] = clone $user;
        $this->nextId = max($this->nextId, $id->value() + 1);
    }

    public function findByLoginIdentifier(LoginIdentifier $identifier): ?User
    {
        foreach ($this->users as $user) {
            if ($user->loginIdentifier()->value() === $identifier->value()) {
                return clone $user;
            }
        }

        return null;
    }

    public function findByLoginIdentifierForUpdate(LoginIdentifier $identifier): ?User
    {
        return $this->findByLoginIdentifier($identifier);
    }

    public function findById(UserId $id): ?User
    {
        return isset($this->users[$id->value()]) ? clone $this->users[$id->value()] : null;
    }

    public function findByPersonId(PersonId $personId): ?User
    {
        foreach ($this->users as $user) {
            if ($user->personId()->value() === $personId->value()) {
                return clone $user;
            }
        }

        return null;
    }

    public function save(User $user): User
    {
        $this->saveCalls++;
        if ($this->nextSaveFailure !== null) {
            $failure = $this->nextSaveFailure;
            $this->nextSaveFailure = null;

            throw $failure;
        }

        if ($this->returnWithoutId) {
            return $this->copy($user, null);
        }

        $persisted = $user->id() === null
            ? $this->copy($user, new UserId($this->nextId++))
            : clone $user;
        $id = $persisted->id();
        if ($id === null) {
            throw new RuntimeException('Persisted User must have an identity.');
        }

        $this->users[$id->value()] = clone $persisted;

        return clone $persisted;
    }

    public function saveCalls(): int
    {
        return $this->saveCalls;
    }

    public function failNextSave(Throwable $failure): void
    {
        $this->nextSaveFailure = $failure;
    }

    public function returnWithoutId(): void
    {
        $this->returnWithoutId = true;
    }

    private function copy(User $user, ?UserId $id): User
    {
        return new User(
            $id,
            $user->personId(),
            $user->loginIdentifier(),
            $user->passwordHash(),
            $user->status(),
            $user->failedLoginAttempts(),
            $user->lockedAt(),
            $user->lastAccessAt(),
        );
    }
}
