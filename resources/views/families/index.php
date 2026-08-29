<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<h1>Families</h1>

<p><a href="/families/create">Create Representative and Family</a></p>

<form method="get" action="/families/show">
    <label for="family-id">Family ID</label>
    <input id="family-id" name="id" type="number" min="1" required>
    <button type="submit">View Family</button>
</form>

<p><a href="/">Back to dashboard</a></p>
