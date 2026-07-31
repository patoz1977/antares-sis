<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application;

use App\IdentityAccess\Application\Contract\Clock;
use App\IdentityAccess\Application\Contract\PasswordHasher;
use App\IdentityAccess\Application\Contract\SecurityEventLogger;
use App\IdentityAccess\Application\Contract\SessionManager;
use App\IdentityAccess\Application\Contract\TransactionManager;
use App\IdentityAccess\Domain\Exception\InvalidUserState;
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

        $events = [];

        $authenticatedUserId = $this->transactions->transactional(
            function () use ($identifier, $password, &$events): ?int {
                return $this->authenticateWithinTransaction($identifier, $password, $events);
            }
        );

        foreach ($events as $event) {
            $this->securityEvents->record($event);
        }

        if ($authenticatedUserId === null) {
            return AuthenticationResult::failure();
        }

        $this->session->regenerateForUser($authenticatedUserId);
        $this->securityEvents->record('authentication.succeeded');

        return AuthenticationResult::success($authenticatedUserId);
    }

    private function authenticateWithinTransaction(
        LoginIdentifier $identifier,
        string $password,
        array &$events
    ): ?int {
        $user = $this->users->findByLoginIdentifierForUpdate($identifier);
        $passwordMatches = $this->passwordHasher->verify(
            $password,
            $user?->passwordHash()->value() ?? self::DUMMY_PASSWORD_HASH
        );

        if ($user === null) {
            $events[] = 'authentication.failed';

            return null;
        }

        $now = $this->clock->now();

        if ($user->isDisabled()) {
            $events[] = 'authentication.failed';

            return null;
        }

        if ($user->isTemporarilyLocked($now, $this->policy->lockoutDurationSeconds())) {
            $events[] = 'authentication.blocked';

            return null;
        }

        if ($user->clearExpiredLock($now, $this->policy->lockoutDurationSeconds())) {
            $this->users->save($user);
            $events[] = 'authentication.lock_expired';
        }

        if (!$passwordMatches) {
            $lockActivated = $user->recordFailedLogin(
                $now,
                $this->policy->maximumFailedAttempts()
            );
            $this->users->save($user);
            $events[] = 'authentication.failed';

            if ($lockActivated) {
                $events[] = 'authentication.lock_activated';
            }

            return null;
        }

        $user->recordSuccessfulAuthentication($now);
        $this->users->save($user);

        return $user->id()->value();
    }
}
