<?php

declare(strict_types=1);

namespace App\IdentityAccess\Infrastructure\Session;

use App\IdentityAccess\Application\Contract\SessionManager;
use Core\Session\SessionInterface;

final readonly class PhpSessionManager implements SessionManager
{
    private const USER_ID_KEY = 'user_id';

    public function __construct(private SessionInterface $session)
    {
    }

    public function regenerateForUser(int $userId): void
    {
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

    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->session->get($key, $default);
        $this->session->remove($key);

        return $value;
    }

    public function destroy(): void
    {
        $this->session->destroy();
    }
}
