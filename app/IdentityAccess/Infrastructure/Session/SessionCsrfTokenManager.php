<?php

declare(strict_types=1);

namespace App\IdentityAccess\Infrastructure\Session;

use App\IdentityAccess\Application\Contract\CsrfTokenManager;
use App\IdentityAccess\Application\Contract\SessionManager;

final readonly class SessionCsrfTokenManager implements CsrfTokenManager
{
    private const TOKEN_KEY = '_csrf_token';

    public function __construct(private SessionManager $session)
    {
    }

    public function token(): string
    {
        $token = $this->session->pull(self::TOKEN_KEY);

        if (!is_string($token) || strlen($token) !== 64) {
            $token = bin2hex(random_bytes(32));
        }

        $this->session->put(self::TOKEN_KEY, $token);

        return $token;
    }

    public function isValid(string $token): bool
    {
        $stored = $this->session->pull(self::TOKEN_KEY);

        return is_string($stored)
            && $stored !== ''
            && $token !== ''
            && hash_equals($stored, $token);
    }
}
