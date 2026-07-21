<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\UserRepository;
use Core\Session\SessionInterface;

class AuthenticationService implements AuthenticationServiceInterface
{
    public function __construct(
        private UserRepository $userRepository,
        private SessionInterface $session
    ) {
    }

    public function attempt(string $username, string $password): bool
    {
        $user = $this->userRepository->findByUsername($username);

        if ($user === null) {
            return false;
        }

        $statusId = $user['status_id'] ?? null;

        if (!is_numeric($statusId) || (int) $statusId <= 0) {
            return false;
        }

        $passwordHash = $user['password_hash'] ?? '';

        if (!is_string($passwordHash) || $passwordHash === '' || !password_verify($password, $passwordHash)) {
            return false;
        }

        $userId = $user['id'] ?? null;

        if (!is_numeric($userId) || (int) $userId <= 0) {
            return false;
        }

        $userId = (int) $userId;

        $this->session->regenerate();
        $this->session->set('user_id', $userId);

        $this->userRepository->updateLastLogin($userId);

        return true;
    }

    public function logout(): void
    {
        $this->session->destroy();
    }

    public function check(): bool
    {
        return $this->session->has('user_id');
    }

    public function user(): ?array
    {
        $userId = $this->id();

        if ($userId === null) {
            return null;
        }

        return $this->userRepository->findById($userId);
    }

    public function id(): ?int
    {
        $userId = $this->session->get('user_id');

        if (!is_numeric($userId) || (int) $userId <= 0) {
            return null;
        }

        return (int) $userId;
    }
}
