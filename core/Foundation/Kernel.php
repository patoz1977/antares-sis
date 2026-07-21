<?php

declare(strict_types=1);

namespace Core\Foundation;

use Closure;
use Core\Http\Request;
use Core\Http\Response;
use Core\Middleware\MiddlewareInterface;
use Core\Routing\Router;
use Throwable;

class Kernel
{
    private Request $request;
    private Router $router;
    private array $globalMiddleware = [];
    private array $routeMiddleware = [];
    private ?Closure $middlewareResolver = null;

    public function __construct(Request $request, Router $router)
    {
        $this->request = $request;
        $this->router = $router;
    }

    public function setMiddlewareResolver(Closure $resolver): void
    {
        $this->middlewareResolver = $resolver;
    }

    public function registerGlobalMiddleware(string|MiddlewareInterface $middleware): void
    {
        $this->globalMiddleware[] = $middleware;
    }

    public function registerRouteMiddleware(string $method, string $uri, string|MiddlewareInterface $middleware): void
    {
        $method = strtoupper($method);
        $this->routeMiddleware[$method][$uri][] = $middleware;
    }

    public function handle(): void
    {
        try {
            $response = $this->buildPipeline()($this->request);
        } catch (Throwable) {
            $response = (new Response())
                ->status(500)
                ->content('Internal Server Error');
        }

        $response->send();
    }

    private function buildPipeline(): Closure
    {
        $middlewareStack = $this->globalMiddleware;
        $method = $this->request->method();
        $uri = $this->request->uri();

        if (isset($this->routeMiddleware[$method][$uri])) {
            $middlewareStack = array_merge(
                $middlewareStack,
                $this->routeMiddleware[$method][$uri]
            );
        }

        $destination = function (Request $request): Response {
            return $this->dispatchToRouter($request);
        };

        return array_reduce(
            array_reverse($middlewareStack),
            function (Closure $next, string|MiddlewareInterface $middleware): Closure {
                return function (Request $request) use ($next, $middleware): Response {
                    $resolved = $this->resolveMiddleware($middleware);

                    return $resolved->handle($request, $next);
                };
            },
            $destination
        );
    }

    private function resolveMiddleware(string|MiddlewareInterface $middleware): MiddlewareInterface
    {
        if ($middleware instanceof MiddlewareInterface) {
            return $middleware;
        }

        if ($this->middlewareResolver !== null) {
            $resolved = ($this->middlewareResolver)($middleware);

            if ($resolved instanceof MiddlewareInterface) {
                return $resolved;
            }
        }

        if (class_exists($middleware)) {
            $resolved = new $middleware();

            if ($resolved instanceof MiddlewareInterface) {
                return $resolved;
            }
        }

        throw new \RuntimeException(sprintf('Invalid middleware: %s', $middleware));
    }

    private function dispatchToRouter(Request $request): Response
    {
        $originalStatusCode = http_response_code();

        ob_start();

        $this->router->dispatch($request->method(), $request->uri());

        $content = ob_get_clean();
        $statusCode = http_response_code();

        if ($originalStatusCode !== false) {
            http_response_code($originalStatusCode);
        }

        return (new Response())
            ->status(is_int($statusCode) ? $statusCode : 200)
            ->content((string) $content);
    }
}
