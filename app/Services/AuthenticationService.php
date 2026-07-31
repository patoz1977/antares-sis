<?php

declare(strict_types=1);

namespace App\Services;

use App\IdentityAccess\Application\AuthenticateUser;
use App\IdentityAccess\Application\GetAuthenticatedUser;
use App\IdentityAccess\Application\LogoutUser;

/**
 * @deprecated Compatibility adapter. New authentication code belongs to IdentityAccess.
 */
final class AuthenticationService implements AuthenticationServiceInterface
{
    public function __construct(
        private readonly AuthenticateUser $authenticateUser,
        private readonly LogoutUser $logoutUser,
        private readonly GetAuthenticatedUser $getAuthenticatedUser,
    ) {
    }

    public function attempt(string $username, string $password): bool
    {
        return $this->authenticateUser->handle($username, $password)->isSuccessful();
    }

    public function logout(): void
    {
        $this->logoutUser->handle();
    }

    public function check(): bool
    {
        return $this->getAuthenticatedUser->handle() !== null;
    }

    public function user(): ?array
    {
        $user = $this->getAuthenticatedUser->handle();
        if ($user === null) {
            return null;
        }

        return [
            'id' => $user->id,
            'person_id' => $user->personId,
            'login_identifier' => $user->loginIdentifier,
        ];
    }

    public function id(): ?int
    {
        return $this->getAuthenticatedUser->handle()?->id;
    }
}
