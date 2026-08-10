<?php

declare(strict_types=1);
?>
<h1>Sign in</h1>

<?php if (isset($flashMessage) && is_string($flashMessage) && $flashMessage !== ''): ?>
<p><?= htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<form method="post" action="/login">
    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars((string) ($csrfToken ?? ''), ENT_QUOTES, 'UTF-8') ?>">
    <div>
        <label for="username">Username</label>
        <input id="username" name="username" type="text" required>
    </div>

    <div>
        <label for="password">Password</label>
        <input id="password" name="password" type="password" required>
    </div>

    <button type="submit">Sign in</button>
</form>

<p><a href="/forgot-password">Forgot password?</a></p>
