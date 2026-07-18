<?php

declare(strict_types=1);

$app = require dirname(__DIR__) . '/bootstrap/app.php';

$request = $app->request();
$router = $app->router();

require dirname(__DIR__) . '/routes/web.php';

$router->dispatch($request->method(), $request->uri());
