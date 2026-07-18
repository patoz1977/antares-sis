<?php

declare(strict_types=1);

$config = require dirname(__DIR__) . '/bootstrap/app.php';

$html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Antares SIS</title>
</head>
<body>
    <h1>Antares SIS</h1>
    <p>Bootstrap OK</p>
    <p>Environment: {$config['environment']}</p>
    <p>Timezone: {$config['timezone']}</p>
</body>
</html>
HTML;

printf('%s', $html);
