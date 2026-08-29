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
<header class="app-page-header">
    <p class="text-uppercase fw-semibold text-primary mb-2">Acceso del representante</p>
    <h1 class="display-6 fw-bold mb-2">Administrar usuario de representante</h1>
    <p class="text-body-secondary mb-0">Provisiona el acceso inicial o reemplaza la contraseña sin exponer credenciales existentes.</p>
</header>

<?php require dirname(__DIR__) . '/components/validation-summary.php'; ?>

<section class="app-data-card app-content-narrow" aria-labelledby="representative-context-heading">
    <h2 class="h4" id="representative-context-heading">Contexto del representante</h2>
    <dl class="app-data-list">
        <dt>Persona</dt><dd><?= $escape($name) ?></dd>
        <dt>ID de representante</dt><dd><?= $escape($representative->id) ?></dd>
        <dt>ID de persona</dt><dd><?= $escape($person->id) ?></dd>
    </dl>
</section>

<?php if ($user === null): ?>
<section class="app-form-section app-content-narrow" aria-labelledby="provision-user-heading">
<h2 class="h4" id="provision-user-heading">Crear usuario</h2>
<p>Nombre de usuario: <strong><?= $derivedLoginIdentifier === '' ? 'Se requiere identificación' : $escape($derivedLoginIdentifier) ?></strong></p>
<form method="post" action="/representative-users/create" autocomplete="off">
    <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
    <input type="hidden" name="representative_id" value="<?= $escape($representative->id) ?>">

    <label class="form-label" for="representative-user-password">Contraseña inicial</label>
    <input class="form-control" id="representative-user-password" type="password" name="password" required minlength="5" autocomplete="new-password" value="">

    <label class="form-label" for="representative-user-password-confirmation">Confirmar contraseña</label>
    <input class="form-control" id="representative-user-password-confirmation" type="password" name="password_confirmation" required minlength="5" autocomplete="new-password" value="">

    <label class="form-label" for="representative-user-status">Estado</label>
    <select class="form-select" id="representative-user-status" name="status" required>
        <option value="ACTIVE"<?= $selectedStatus->value === 'ACTIVE' ? ' selected' : '' ?>>Activo</option>
        <option value="DISABLED"<?= $selectedStatus->value === 'DISABLED' ? ' selected' : '' ?>>Deshabilitado</option>
    </select>

    <button class="btn btn-primary mt-3" type="submit"<?= $derivedLoginIdentifier === '' ? ' disabled' : '' ?>>Crear usuario de representante</button>
</form>
</section>
<?php else: ?>
<section class="app-data-card app-content-narrow" aria-labelledby="current-user-heading">
<h2 class="h4" id="current-user-heading">Usuario actual</h2>
<dl class="app-data-list">
    <dt>ID de usuario</dt><dd><?= $escape($user->userId) ?></dd>
    <dt>Nombre de usuario</dt><dd><?= $escape($user->loginIdentifier) ?></dd>
    <dt>Estado</dt><dd><?= $escape($user->status->value === 'ACTIVE' ? 'Activo' : 'Deshabilitado') ?></dd>
</dl>
</section>

<section class="app-consequential-panel app-content-narrow" aria-labelledby="replace-password-heading">
<h2 class="h4" id="replace-password-heading">Reemplazar contraseña</h2>
<p class="text-body-secondary">Esta operación sustituye la contraseña actual. El sistema no muestra ni recupera la credencial anterior.</p>
<form method="post" action="/representative-users/password" autocomplete="off">
    <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
    <input type="hidden" name="representative_id" value="<?= $escape($representative->id) ?>">

    <label class="form-label" for="representative-user-new-password">Nueva contraseña</label>
    <input class="form-control" id="representative-user-new-password" type="password" name="new_password" required minlength="5" autocomplete="new-password" value="">

    <label class="form-label" for="representative-user-new-password-confirmation">Confirmar nueva contraseña</label>
    <input class="form-control" id="representative-user-new-password-confirmation" type="password" name="new_password_confirmation" required minlength="5" autocomplete="new-password" value="">

    <button class="btn btn-warning mt-3" type="submit">Reemplazar contraseña</button>
</form>
</section>
<?php endif; ?>

<p><a class="btn btn-outline-secondary" href="/families">Volver a Familias</a></p>
