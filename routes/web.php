<?php

declare(strict_types=1);

use App\IdentityAccess\Http\AuthenticationController;
use Core\Middleware\AuthenticationMiddleware;
use Core\Foundation\Application;
use Core\Routing\Router;

/** @var Router $router */
/** @var Application $app */
$authenticationController = $app->container()->make(AuthenticationController::class);

$router->get('/login', [$authenticationController, 'showLogin']);
$router->post('/login', [$authenticationController, 'login']);
$router->post('/logout', [$authenticationController, 'logout']);
$router->get('/', [$authenticationController, 'dashboard'], AuthenticationMiddleware::class);
