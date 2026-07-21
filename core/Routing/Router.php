<?php

declare(strict_types=1);

namespace Core\Routing;

use Core\Http\Response;

class Router
{
    private array $routes = [];

    public function get(string $uri, callable $handler, string|array $middleware = []): void
    {
        $this->addRoute('GET', $uri, $handler, $middleware);
    }

    public function post(string $uri, callable $handler, string|array $middleware = []): void
    {
        $this->addRoute('POST', $uri, $handler, $middleware);
    }

    public function middlewareFor(string $method, string $uri): array
    {
        $method = strtoupper($method);

        if (!isset($this->routes[$method][$uri])) {
            return [];
        }

        return $this->routes[$method][$uri]['middleware'];
    }

    public function dispatch(string $method, string $uri): void
    {
        $method = strtoupper($method);

        if (isset($this->routes[$method][$uri])) {
            $handler = $this->routes[$method][$uri]['handler'];
            $result = $handler();
            $statusCode = http_response_code();

            $response = new Response();
            $response->status(is_int($statusCode) ? $statusCode : 200);

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

    private function addRoute(string $method, string $uri, callable $handler, string|array $middleware = []): void
    {
        $middlewareList = is_array($middleware) ? $middleware : [$middleware];

        $this->routes[$method][$uri] = [
            'handler' => $handler,
            'middleware' => $middlewareList,
        ];
    }
}
