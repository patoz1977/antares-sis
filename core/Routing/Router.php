<?php

declare(strict_types=1);

namespace Core\Routing;

class Router
{
    private array $routes = [];

    public function get(string $uri, callable $handler): void
    {
        $this->routes['GET'][$uri] = $handler;
    }

    public function dispatch(string $method, string $uri): void
    {
        $method = strtoupper($method);

        if (isset($this->routes[$method][$uri])) {
            $handler = $this->routes[$method][$uri];
            $handler();

            return;
        }

        http_response_code(404);
        echo 'Route not found';
    }
}
