<?php

declare(strict_types=1);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard</title>
</head>
<body>
    <h1>Dashboard</h1>
    <p>Authentication successful.</p>

    <?php if (($canAccessPersons ?? false) === true): ?>
    <p><a href="/persons">Manage Persons</a></p>
    <p><a href="/families">Manage Families</a></p>
    <p><a href="/institutional-acknowledgements">Manage Institutional Acknowledgements</a></p>
    <?php endif; ?>

    <form method="post" action="/logout">
        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars((string) ($csrfToken ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit">Sign out</button>
    </form>
</body>
</html>
