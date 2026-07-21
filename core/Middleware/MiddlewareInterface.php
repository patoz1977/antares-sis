<?php

declare(strict_types=1);

namespace Core\Middleware;

use Closure;
use Core\Http\Request;
use Core\Http\Response;

interface MiddlewareInterface
{
    public function handle(Request $request, Closure $next): Response;
}
