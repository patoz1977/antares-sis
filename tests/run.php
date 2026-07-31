<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Tests\Support\TestRunner;

$runner = new TestRunner();

require __DIR__ . '/IdentityAccessTest.php';

\Tests\registerIdentityAccessTests($runner);
$runner->run();
