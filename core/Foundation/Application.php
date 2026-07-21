<?php

declare(strict_types=1);

namespace Core\Foundation;

use Core\Container\ContainerInterface;
use Core\Http\Request;
use Core\Routing\Router;

class Application
{
    private array $config;
    private ContainerInterface $container;
    private Router $router;
    private Request $request;
    private Kernel $kernel;

    public function __construct(array $config, ContainerInterface $container)
    {
        $this->config = $config;
        $this->container = $container;
        $this->router = new Router();
        $this->request = new Request();
        $this->kernel = new Kernel($this->request, $this->router);
    }

    public function container(): ContainerInterface
    {
        return $this->container;
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

    public function request(): Request
    {
        return $this->request;
    }

    public function kernel(): Kernel
    {
        return $this->kernel;
    }
}
