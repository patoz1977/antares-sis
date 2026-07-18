<?php

declare(strict_types=1);

$config = require dirname(__DIR__) . '/bootstrap/app.php';

$html = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{$config['app_name']}</title>
</head>
<body>
    <h1>{$config['app_name']}</h1>
    <p>Bootstrap OK</p>
    <p>Environment: {$config['environment']}</p>
    <p>Timezone: {$config['timezone']}</p>
    <p>Locale: {$config['locale']}</p>
</body>
</html>
HTML;

echo $html;
