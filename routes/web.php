<?php

declare(strict_types=1);

use App\IdentityAccess\Http\AuthenticationController;
use App\Family\Http\FamilyAdministrationMiddleware;
use App\Family\Http\FamilyController;
use App\Person\Http\PersonAdministrationMiddleware;
use App\Person\Http\PersonController;
use Core\Middleware\AuthenticationMiddleware;
use Core\Foundation\Application;
use Core\Routing\Router;

/** @var Router $router */
/** @var Application $app */
$authenticationController = $app->container()->make(AuthenticationController::class);
$personController = $app->container()->make(PersonController::class);
$familyController = $app->container()->make(FamilyController::class);
$personMiddleware = [
    AuthenticationMiddleware::class,
    PersonAdministrationMiddleware::class,
];
$familyMiddleware = [
    AuthenticationMiddleware::class,
    FamilyAdministrationMiddleware::class,
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
$router->get('/families', [$familyController, 'index'], $familyMiddleware);
$router->get('/families/create', [$familyController, 'showCreateRepresentativeFamily'], $familyMiddleware);
$router->post('/families/create', [$familyController, 'createRepresentativeFamily'], $familyMiddleware);
$router->get('/families/show', [$familyController, 'show'], $familyMiddleware);
$router->get('/families/students/create', [$familyController, 'showCreateStudent'], $familyMiddleware);
$router->post('/families/students/create', [$familyController, 'createStudent'], $familyMiddleware);
