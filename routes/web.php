<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Controllers/Controller.php';

use App\Controllers\HomeController;

/** @var \Core\Routing\Router $router */
$router->get('/', [new HomeController(), 'index']);
