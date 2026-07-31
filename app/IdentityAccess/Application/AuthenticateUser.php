<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application;

use App\IdentityAccess\Application\Contract\Clock;
use App\IdentityAccess\Application\Contract\PasswordHasher;
use App\IdentityAccess\Application\Contract\SecurityEventLogger;
use App\IdentityAccess\Application\Contract\SessionManager;
use App\IdentityAccess\Application\Contract\TransactionManager;
use App\IdentityAccess\Domain\Exception\InvalidUserState;
use App\IdentityAccess\Domain\User;
use App\IdentityAccess\Domain\UserRepository;
use App\IdentityAccess\Domain\ValueObject\LoginIdentifier;

final class AuthenticateUser
{
    private const DUMMY_PASSWORD_HASH = '$2y$10$8K1p/a0dL1LXMIgoEDFrwOfwP3zD4jDNTcBzJj1R9gQ5KqRfB9x8W';

    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordHasher $passwordHasher,
        private readonly SessionManager $session,
        private readonly TransactionManager $transactions,
        private readonly Clock $clock,
        private readonly SecurityEventLogger $securityEvents,
        private readonly AuthenticationPolicy $policy,
    ) {
    }

    public function handle(string $loginIdentifier, string $password): AuthenticationResult
    {
        try {
            $identifier = new LoginIdentifier($loginIdentifier);
        } catch (InvalidUserState) {
            $this->passwordHasher->verify($password, self::DUMMY_PASSWORD_HASH);
            $this->securityEvents->record('authentication.failed');

            return AuthenticationResult::failure();
        }

        $user = $this->users->findByLoginIdentifier($identifier);

        if ($user === null) {
            $this->passwordHasher->verify($password, self::DUMMY_PASSWORD_HASH);
            $this->securityEvents->record('authentication.failed');

            return AuthenticationResult::failure();
        }

        $events = [];

        $successful = $this->transactions->transactional(
            function () use ($user, $password, &$events): bool {
                return $this->authenticateWithinTransaction($user, $password, $events);
            }
        );

        foreach ($events as $event) {
            $this->securityEvents->record($event);
        }

        if (!$successful) {
            return AuthenticationResult::failure();
        }

        $userId = $user->id()->value();
        $this->session->regenerateForUser($userId);
        $this->securityEvents->record('authentication.succeeded');

        return AuthenticationResult::success($userId);
    }

    private function authenticateWithinTransaction(User $user, string $password, array &$events): bool
    {
        $now = $this->clock->now();

        if ($user->isDisabled()) {
            $events[] = 'authentication.failed';

            return false;
        }

        if ($user->isTemporarilyLocked($now, $this->policy->lockoutDurationSeconds())) {
            $events[] = 'authentication.blocked';

            return false;
        }

        if ($user->clearExpiredLock($now, $this->policy->lockoutDurationSeconds())) {
            $this->users->save($user);
            $events[] = 'authentication.lock_expired';
        }

        if (!$this->passwordHasher->verify($password, $user->passwordHash()->value())) {
            $lockActivated = $user->recordFailedLogin(
                $now,
                $this->policy->maximumFailedAttempts()
            );
            $this->users->save($user);
            $events[] = 'authentication.failed';

            if ($lockActivated) {
                $events[] = 'authentication.lock_activated';
            }

            return false;
        }

        $user->recordSuccessfulAuthentication($now);
        $this->users->save($user);

        return true;
    }
}
