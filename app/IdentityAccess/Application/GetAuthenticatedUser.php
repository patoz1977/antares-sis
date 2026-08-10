<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application;

use App\IdentityAccess\Application\Contract\SessionManager;
use App\IdentityAccess\Domain\UserRepository;
use App\IdentityAccess\Domain\ValueObject\UserId;

final readonly class GetAuthenticatedUser
{
    public function __construct(
        private SessionManager $session,
        private UserRepository $users,
    ) {
    }

    public function handle(): ?AuthenticatedUser
    {
        $userId = $this->session->authenticatedUserId();
        if ($userId === null) {
            return null;
        }

        $user = $this->users->findById(new UserId($userId));
        if ($user === null || $user->isDisabled()) {
            return null;
        }

        $persistedUserId = $user->id();
        if ($persistedUserId === null) {
            return null;
        }

        return new AuthenticatedUser(
            $persistedUserId->value(),
            $user->personId()->value(),
            $user->loginIdentifier()->value(),
        );
    }
}
