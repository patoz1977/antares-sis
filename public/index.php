<?php

declare(strict_types=1);

use App\Shared\Http\SharedShellDataFactory;
use Core\View\View;

error_reporting(E_ALL);
ini_set('display_errors', '1');

$app = require dirname(__DIR__) . '/bootstrap/app.php';
$router = $app->router();

require dirname(__DIR__) . '/routes/web.php';

View::setSharedDataResolver(static fn (): array => [
    'shell' => $app->container()->make(SharedShellDataFactory::class)->forRequest($app->request()),
]);

$app->kernel()->handle();
