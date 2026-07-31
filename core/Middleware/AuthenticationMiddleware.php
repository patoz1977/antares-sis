<?php

declare(strict_types=1);

namespace Core\Middleware;

use Closure;
use Core\Http\Request;
use Core\Http\Response;
use Core\Security\AuthenticatedUserProviderInterface;

final class AuthenticationMiddleware implements MiddlewareInterface
{
    public function __construct(
        private AuthenticatedUserProviderInterface $authenticatedUserProvider
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->authenticatedUserProvider->check()) {
            return $next($request);
        }

        header('Location: /login');

        return (new Response())->status(302);
    }
}
