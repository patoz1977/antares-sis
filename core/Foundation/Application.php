<?php

declare(strict_types=1);

namespace Core\Foundation;

use Core\Routing\Router;

class Application
{
    private array $config;
    private Router $router;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->router = new Router();
    }

    public function config(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->config;
        }

        return $this->config[$key] ?? $default;
    }

    public function hasConfig(string $key): bool
    {
        return array_key_exists($key, $this->config);
    }

    public function router(): Router
    {
        return $this->router;
    }
}
