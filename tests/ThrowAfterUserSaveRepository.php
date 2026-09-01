<?php

declare(strict_types=1);

namespace Tests;

use App\IdentityAccess\Domain\User;
use App\IdentityAccess\Domain\UserRepository;
use App\IdentityAccess\Domain\ValueObject\LoginIdentifier;
use App\IdentityAccess\Domain\ValueObject\PersonId;
use App\IdentityAccess\Domain\ValueObject\UserId;
use Throwable;

final readonly class ThrowAfterUserSaveRepository implements UserRepository
{
    public function __construct(
        private UserRepository $delegate,
        private Throwable $failure,
    ) {
    }

    public function findByLoginIdentifier(LoginIdentifier $identifier): ?User
    {
        return $this->delegate->findByLoginIdentifier($identifier);
    }

    public function findByLoginIdentifierForUpdate(LoginIdentifier $identifier): ?User
    {
        return $this->delegate->findByLoginIdentifierForUpdate($identifier);
    }

    public function findById(UserId $id): ?User
    {
        return $this->delegate->findById($id);
    }

    public function findByPersonId(PersonId $personId): ?User
    {
        return $this->delegate->findByPersonId($personId);
    }

    public function save(User $user): User
    {
        $this->delegate->save($user);
        throw $this->failure;
    }
}
