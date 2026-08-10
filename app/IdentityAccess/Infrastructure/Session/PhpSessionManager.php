<?php

declare(strict_types=1);

namespace App\IdentityAccess\Infrastructure\Session;

use App\IdentityAccess\Application\Contract\SessionManager;
use Core\Session\SessionInterface;

final readonly class PhpSessionManager implements SessionManager
{
    private const USER_ID_KEY = 'user_id';
    private const REPRESENTATIVE_FAMILY_CONTEXT_ID_KEY = 'representative_family_context_id';

    public function __construct(private SessionInterface $session)
    {
    }

    public function regenerateForUser(int $userId): void
    {
        $this->session->remove(self::REPRESENTATIVE_FAMILY_CONTEXT_ID_KEY);
        $this->session->regenerate();
        $this->session->set(self::USER_ID_KEY, $userId);
    }

    public function authenticatedUserId(): ?int
    {
        $userId = $this->session->get(self::USER_ID_KEY);

        return is_int($userId) && $userId > 0 ? $userId : null;
    }

    public function put(string $key, mixed $value): void
    {
        $this->session->set($key, $value);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->session->get($key, $default);
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->session->get($key, $default);
        $this->session->remove($key);

        return $value;
    }

    public function remove(string $key): void
    {
        $this->session->remove($key);
    }

    public function destroy(): void
    {
        $this->session->destroy();
    }
}
