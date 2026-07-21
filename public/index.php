<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

$app = require dirname(__DIR__) . '/bootstrap/app.php';
$router = $app->router();

require dirname(__DIR__) . '/routes/web.php';

$app->kernel()->handle();
