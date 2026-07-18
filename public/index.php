<?php

declare(strict_types=1);

$app = require dirname(__DIR__) . '/bootstrap/app.php';
$router = $app->router();

require dirname(__DIR__) . '/routes/web.php';

$app->kernel()->handle();
