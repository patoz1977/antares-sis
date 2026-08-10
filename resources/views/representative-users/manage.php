<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars(
    (string) $value,
    ENT_QUOTES,
    'UTF-8',
);
$name = trim(implode(' ', array_filter([
    $person->firstName,
    $person->middleName,
    $person->firstSurname,
    $person->secondSurname,
], static fn (?string $part): bool => is_string($part) && $part !== '')));
$derivedLoginIdentifier = $person->documentNumber ?? '';
?>
<h1>Manage Representative User</h1>

<?php if (is_string($successMessage ?? null) && $successMessage !== ''): ?>
<p><?= $escape($successMessage) ?></p>
<?php endif; ?>

<?php if (is_string($errorMessage ?? null) && $errorMessage !== ''): ?>
<p><?= $escape($errorMessage) ?></p>
<?php endif; ?>

<?php if (($errors ?? []) !== []): ?>
<ul>
<?php foreach ($errors as $error): ?>
    <li><?= $escape($error) ?></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>

<dl>
    <dt>Representative ID</dt><dd><?= $escape($representative->id) ?></dd>
    <dt>Person</dt><dd><?= $escape($name) ?></dd>
    <dt>Person ID</dt><dd><?= $escape($person->id) ?></dd>
</dl>

<?php if ($user === null): ?>
<h2>Create User</h2>
<p>Username: <strong><?= $derivedLoginIdentifier === '' ? 'Identification required' : $escape($derivedLoginIdentifier) ?></strong></p>
<form method="post" action="/representative-users/create" autocomplete="off">
    <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
    <input type="hidden" name="representative_id" value="<?= $escape($representative->id) ?>">

    <label for="representative-user-password">Initial password</label>
    <input id="representative-user-password" type="password" name="password" required minlength="5" autocomplete="new-password" value="">

    <label for="representative-user-password-confirmation">Confirm password</label>
    <input id="representative-user-password-confirmation" type="password" name="password_confirmation" required minlength="5" autocomplete="new-password" value="">

    <label for="representative-user-status">Status</label>
    <select id="representative-user-status" name="status" required>
        <option value="ACTIVE"<?= $selectedStatus->value === 'ACTIVE' ? ' selected' : '' ?>>Active</option>
        <option value="DISABLED"<?= $selectedStatus->value === 'DISABLED' ? ' selected' : '' ?>>Disabled</option>
    </select>

    <button type="submit"<?= $derivedLoginIdentifier === '' ? ' disabled' : '' ?>>Create Representative User</button>
</form>
<?php else: ?>
<h2>Representative User</h2>
<dl>
    <dt>User ID</dt><dd><?= $escape($user->userId) ?></dd>
    <dt>Username</dt><dd><?= $escape($user->loginIdentifier) ?></dd>
    <dt>Status</dt><dd><?= $escape($user->status->value) ?></dd>
</dl>

<h2>Change password</h2>
<form method="post" action="/representative-users/password" autocomplete="off">
    <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
    <input type="hidden" name="representative_id" value="<?= $escape($representative->id) ?>">

    <label for="representative-user-new-password">New password</label>
    <input id="representative-user-new-password" type="password" name="new_password" required minlength="5" autocomplete="new-password" value="">

    <label for="representative-user-new-password-confirmation">Confirm new password</label>
    <input id="representative-user-new-password-confirmation" type="password" name="new_password_confirmation" required minlength="5" autocomplete="new-password" value="">

    <button type="submit">Change password</button>
</form>
<?php endif; ?>

<p><a href="/families">Back to Families</a></p>
