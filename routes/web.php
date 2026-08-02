<?php

declare(strict_types=1);

use App\IdentityAccess\Http\AuthenticationController;
use App\Person\Http\PersonAdministrationMiddleware;
use App\Person\Http\PersonController;
use Core\Middleware\AuthenticationMiddleware;
use Core\Foundation\Application;
use Core\Routing\Router;

/** @var Router $router */
/** @var Application $app */
$authenticationController = $app->container()->make(AuthenticationController::class);
$personController = $app->container()->make(PersonController::class);
$personMiddleware = [
    AuthenticationMiddleware::class,
    PersonAdministrationMiddleware::class,
];

$router->get('/login', [$authenticationController, 'showLogin']);
$router->post('/login', [$authenticationController, 'login']);
$router->post('/logout', [$authenticationController, 'logout']);
$router->get('/', [$authenticationController, 'dashboard'], AuthenticationMiddleware::class);
$router->get('/persons', [$personController, 'index'], $personMiddleware);
$router->get('/persons/create', [$personController, 'showCreate'], $personMiddleware);
$router->post('/persons/create', [$personController, 'create'], $personMiddleware);
$router->get('/persons/show', [$personController, 'show'], $personMiddleware);
$router->get('/persons/edit', [$personController, 'showEdit'], $personMiddleware);
$router->post('/persons/update', [$personController, 'update'], $personMiddleware);
