<?php

declare(strict_types=1);

$app = require dirname(__DIR__) . '/bootstrap/app.php';

$html = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{$app->config('app_name')}</title>
</head>
<body>
    <h1>Application initialized</h1>
    <p>App Name: {$app->config('app_name')}</p>
    <p>Environment: {$app->config('environment')}</p>
    <p>Timezone: {$app->config('timezone')}</p>
    <p>Locale: {$app->config('locale')}</p>
</body>
</html>
HTML;

echo $html;
