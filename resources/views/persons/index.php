<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<h1>Persons</h1>

<p><a href="/persons/create">Create Person</a></p>

<form method="get" action="/persons/show">
    <label for="person-id">Person ID</label>
    <input id="person-id" name="id" type="number" min="1" required>
    <button type="submit">View Person</button>
</form>

<p><a href="/">Back to dashboard</a></p>
