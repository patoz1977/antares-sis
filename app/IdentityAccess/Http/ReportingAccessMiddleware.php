<?php

declare(strict_types=1);

namespace App\IdentityAccess\Http;

use App\IdentityAccess\Application\CanAccessAllReports;
use App\IdentityAccess\Application\GetAuthenticatedUser;
use App\Shared\Http\SafeErrorPage;
use Closure;
use Core\Http\Request;
use Core\Http\Response;
use Core\Middleware\MiddlewareInterface;

final readonly class ReportingAccessMiddleware implements MiddlewareInterface
{
    public function __construct(
        private GetAuthenticatedUser $getAuthenticatedUser,
        private CanAccessAllReports $canAccessAllReports,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $this->getAuthenticatedUser->handle();
        if ($user === null) {
            header('Location: /login');

            return (new Response())->status(302);
        }

        if (!$this->canAccessAllReports->handle($user->id)) {
            header('Cache-Control: no-store');

            return (new Response())->status(403)->content(
                SafeErrorPage::render(403, 'No tienes permiso para acceder a esta página.')
            );
        }

        return $next($request);
    }
}
