<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<h1>Representative Portal unavailable</h1>
<p>You cannot access the requested Representative Portal context.</p>
