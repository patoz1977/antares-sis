<?php

declare(strict_types=1);

namespace App\Person\Http;

use App\IdentityAccess\Application\GetAuthenticatedUser;
use Closure;
use Core\Http\Request;
use Core\Http\Response;
use Core\Middleware\MiddlewareInterface;

final readonly class PersonAdministrationMiddleware implements MiddlewareInterface
{
    public function __construct(private GetAuthenticatedUser $getAuthenticatedUser)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $this->getAuthenticatedUser->handle();

        if ($user === null) {
            header('Location: /login');

            return (new Response())->status(302);
        }

        if ($user->loginIdentifier !== 'admin') {
            return (new Response())->status(403)->content('Forbidden');
        }

        return $next($request);
    }
}
