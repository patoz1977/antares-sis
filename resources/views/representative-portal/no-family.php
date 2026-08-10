<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<h1>Representative Portal unavailable</h1>
<p>No family context is currently available.</p>

<form method="post" action="/logout">
    <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken ?? '') ?>">
    <button type="submit">Sign out</button>
</form>
