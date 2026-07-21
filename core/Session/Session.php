<?php

declare(strict_types=1);

namespace Core\Session;

final class Session implements SessionInterface
{
    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $this->configureCookieParams();

        session_start();
    }

    public function regenerate(): void
    {
        $this->start();

        session_regenerate_id(true);
    }

    public function has(string $key): bool
    {
        $this->start();

        return array_key_exists($key, $_SESSION);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->start();

        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->start();

        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        $this->start();

        unset($_SESSION[$key]);
    }

    public function clear(): void
    {
        $this->start();

        $_SESSION = [];
    }

    public function destroy(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $_SESSION = [];

            return;
        }

        $_SESSION = [];
        session_unset();

        $cookieParams = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            [
                'expires' => time() - 42000,
                'path' => $cookieParams['path'] ?? '/',
                'domain' => $cookieParams['domain'] ?? '',
                'secure' => (bool) ($cookieParams['secure'] ?? false),
                'httponly' => (bool) ($cookieParams['httponly'] ?? true),
                'samesite' => $cookieParams['samesite'] ?? 'Lax',
            ]
        );

        session_destroy();

        $_SESSION = [];
    }

    private function configureCookieParams(): void
    {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $this->isHttpsEnabled(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private function isHttpsEnabled(): bool
    {
        $https = filter_input(INPUT_SERVER, 'HTTPS');
        if (is_string($https) && strtolower($https) !== 'off' && $https !== '') {
            return true;
        }

        $requestScheme = filter_input(INPUT_SERVER, 'REQUEST_SCHEME');
        if (is_string($requestScheme) && strtolower($requestScheme) === 'https') {
            return true;
        }

        $forwardedProto = filter_input(INPUT_SERVER, 'HTTP_X_FORWARDED_PROTO');

        return is_string($forwardedProto) && strtolower($forwardedProto) === 'https';
    }
}
