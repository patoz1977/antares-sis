<?php

declare(strict_types=1);

use App\Controllers\AuthenticationController;
use App\Controllers\FamilyController;
use App\Controllers\PersonController;
use Core\Middleware\AuthenticationMiddleware;
use Core\Foundation\Application;
use Core\Routing\Router;

/** @var Router $router */
/** @var Application $app */
$authenticationController = $app->container()->make(AuthenticationController::class);
$familyController = $app->container()->make(FamilyController::class);
$personController = $app->container()->make(PersonController::class);

$router->get('/login', [$authenticationController, 'showLogin']);
$router->post('/login', [$authenticationController, 'login']);
$router->post('/logout', [$authenticationController, 'logout']);
$router->get('/', [$authenticationController, 'dashboard'], AuthenticationMiddleware::class);

$router->get('/families', [$familyController, 'index'], AuthenticationMiddleware::class);
$router->get('/families/create', [$familyController, 'create'], AuthenticationMiddleware::class);
$router->post('/families', [$familyController, 'store'], AuthenticationMiddleware::class);

$router->get('/persons', [$personController, 'index'], AuthenticationMiddleware::class);
$router->get('/persons/create', [$personController, 'create'], AuthenticationMiddleware::class);
$router->post('/persons', [$personController, 'store'], AuthenticationMiddleware::class);

$requestUriPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');

if (preg_match('#^/persons/(\d+)$#', $requestUriPath, $matches) === 1) {
	$personId = (int) $matches[1];

	$router->get(
		$requestUriPath,
		static fn (): string => $personController->show($personId),
		AuthenticationMiddleware::class
	);

	$router->post(
		$requestUriPath,
		static fn (): string => $personController->update($personId),
		AuthenticationMiddleware::class
	);
}

if (preg_match('#^/persons/(\d+)/edit$#', $requestUriPath, $matches) === 1) {
	$personId = (int) $matches[1];

	$router->get(
		$requestUriPath,
		static fn (): string => $personController->edit($personId),
		AuthenticationMiddleware::class
	);
}

if (preg_match('#^/persons/(\d+)/deactivate$#', $requestUriPath, $matches) === 1) {
	$personId = (int) $matches[1];

	$router->post(
		$requestUriPath,
		static fn (): string => $personController->deactivate($personId),
		AuthenticationMiddleware::class
	);
}

if (preg_match('#^/families/(\d+)$#', $requestUriPath, $matches) === 1) {
	$familyId = (int) $matches[1];

	$router->get(
		$requestUriPath,
		static fn (): string => $familyController->show($familyId),
		AuthenticationMiddleware::class
	);

	$router->post(
		$requestUriPath,
		static fn (): string => $familyController->update($familyId),
		AuthenticationMiddleware::class
	);
}

if (preg_match('#^/families/(\d+)/edit$#', $requestUriPath, $matches) === 1) {
	$familyId = (int) $matches[1];

	$router->get(
		$requestUriPath,
		static fn (): string => $familyController->edit($familyId),
		AuthenticationMiddleware::class
	);
}

if (preg_match('#^/families/(\d+)/deactivate$#', $requestUriPath, $matches) === 1) {
	$familyId = (int) $matches[1];

	$router->post(
		$requestUriPath,
		static fn (): string => $familyController->deactivate($familyId),
		AuthenticationMiddleware::class
	);
}
