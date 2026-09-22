<?php

declare(strict_types=1);

use App\Shared\Http\SharedShellDataFactory;
use App\Shared\Http\SafeErrorPage;
use Core\Foundation\Kernel;
use Core\View\View;

error_reporting(E_ALL);
ini_set('display_errors', '0');

$app = require dirname(__DIR__) . '/bootstrap/app.php';
$environment = $app->config('environment', 'production');
$debug = $app->config('debug', false);
$app->kernel()->configureDiagnostics($environment, $debug);
ini_set(
    'display_errors',
    Kernel::shouldDisplayDiagnostics($environment, $debug) ? '1' : '0',
);
$router = $app->router();

require dirname(__DIR__) . '/routes/web.php';

View::setSharedDataResolver(static fn (): array => [
    'shell' => $app->container()->make(SharedShellDataFactory::class)->forRequest($app->request()),
]);
$router->setNotFoundRenderer(static fn (): string => SafeErrorPage::render(
    404,
    'La página solicitada no está disponible.',
));
$app->kernel()->setServerErrorRenderer(static fn (): string => SafeErrorPage::render(
    500,
    'No se pudo completar la solicitud. Inténtelo nuevamente.',
));

$app->kernel()->handle();
