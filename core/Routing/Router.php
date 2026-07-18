<?php

declare(strict_types=1);

namespace Core\Routing;

use Core\Http\Response;

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
            $result = $handler();

            $response = new Response();

            if (is_string($result)) {
                $response->content($result)->send();

                return;
            }

            $response->send();

            return;
        }

        $response = new Response();
        $response->status(404)->content('Route not found')->send();
    }
}
