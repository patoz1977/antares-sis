<?php

declare(strict_types=1);

namespace Core\Middleware;

use App\Services\AuthenticationServiceInterface;
use Closure;
use Core\Http\Request;
use Core\Http\Response;

final class AuthenticationMiddleware implements MiddlewareInterface
{
    public function __construct(
        private AuthenticationServiceInterface $authenticationService
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->authenticationService->check()) {
            return $next($request);
        }

        header('Location: /login');

        return (new Response())->status(302);
    }
}
