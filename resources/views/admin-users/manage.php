<?php

declare(strict_types=1);

use App\IdentityAccess\Application\AdministrativeUsers\AdministrativeUserAccessType;
use App\IdentityAccess\Application\AdministrativeUsers\Dto\AdministrativeUserRow;
use App\IdentityAccess\Domain\UserStatus;

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$errors = is_array($errors ?? null) ? $errors : [];
if (!($user ?? null) instanceof AdministrativeUserRow) {
    throw new RuntimeException('Administrative User View requires a User.');
}
?>
<nav aria-label="Migas de pan"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="/admin/users">Usuarios</a></li>
    <li class="breadcrumb-item active" aria-current="page">Administrar</li>
</ol></nav>

<header class="app-page-header">
    <p class="text-uppercase fw-semibold text-primary mb-2">Administración de usuarios</p>
    <h1 class="display-6 fw-bold mb-2"><?= $escape($user->fullName) ?></h1>
    <p class="text-body-secondary mb-0">La información personal se modifica únicamente desde el módulo Personas.</p>
</header>

<?php if (is_string($successMessage ?? null) && $successMessage !== ''): ?>
<div class="alert alert-success" role="status"><?= $escape($successMessage) ?></div>
<?php endif; ?>
<?php if (is_string($errorMessage ?? null) && $errorMessage !== ''): ?>
<div class="alert alert-danger" role="alert"><?= $escape($errorMessage) ?></div>
<?php endif; ?>

<?php if ($errors !== []): ?>
<div class="alert alert-danger" role="alert"><ul class="mb-0">
    <?php foreach ($errors as $error): ?><li><?= $escape($error) ?></li><?php endforeach; ?>
</ul></div>
<?php endif; ?>

<section class="app-form-section" aria-labelledby="user-detail-heading">
    <h2 class="h4" id="user-detail-heading">Información de la cuenta</h2>
    <dl class="row mb-0">
        <dt class="col-sm-4">Persona</dt><dd class="col-sm-8"><?= $escape($user->fullName) ?></dd>
        <dt class="col-sm-4">Identificación</dt><dd class="col-sm-8"><?= $user->identificationNumber === null ? 'No registrada' : $escape($user->identificationNumber) ?></dd>
        <dt class="col-sm-4">Identificador de acceso</dt><dd class="col-sm-8"><?= $escape($user->loginIdentifier) ?></dd>
        <dt class="col-sm-4">Tipo de acceso</dt><dd class="col-sm-8"><?= $escape($user->accessType->label()) ?></dd>
        <dt class="col-sm-4">Estado</dt><dd class="col-sm-8"><?= $user->status === UserStatus::Active ? 'Activo' : 'Deshabilitado' ?></dd>
        <dt class="col-sm-4">Último acceso</dt><dd class="col-sm-8"><?= $user->lastAccessAt === null ? 'Sin registro' : $escape($user->lastAccessAt) . ' UTC' ?></dd>
    </dl>
    <div class="mt-3"><a class="btn btn-outline-secondary" href="/persons/show?id=<?= $escape($user->personId) ?>">Abrir persona</a></div>
</section>

<?php if ($user->accessType === AdministrativeUserAccessType::Administrator): ?>
<section class="app-form-section" aria-labelledby="admin-protection-heading">
    <h2 class="h4" id="admin-protection-heading">Cuenta administradora protegida</h2>
    <p class="mb-0">Este módulo no permite deshabilitar ni cambiar el identificador de la cuenta administradora. Su credencial continúa bajo el procedimiento operativo autorizado.</p>
</section>
<?php elseif ($user->accessType === AdministrativeUserAccessType::Incoherent || $user->accessType === AdministrativeUserAccessType::Unsupported): ?>
<section class="app-form-section" aria-labelledby="unsupported-user-heading">
    <h2 class="h4" id="unsupported-user-heading">Cuenta de solo consulta</h2>
    <p class="mb-0">El estado actual no corresponde a un perfil administrable aprobado. No se aplicará ninguna mutación desde esta pantalla.</p>
</section>
<?php else: ?>
<section class="app-form-section" aria-labelledby="user-status-heading">
    <h2 class="h4" id="user-status-heading">Estado de acceso</h2>
    <p class="text-body-secondary">Deshabilitar conserva la cuenta y sus relaciones; no elimina información.</p>
    <form method="post" action="/admin/users/status">
        <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken ?? '') ?>">
        <input type="hidden" name="user_id" value="<?= $escape($user->userId) ?>">
        <input type="hidden" name="action" value="<?= $user->status === UserStatus::Active ? 'disable' : 'activate' ?>">
        <button class="btn <?= $user->status === UserStatus::Active ? 'btn-outline-danger' : 'btn-outline-success' ?>" type="submit"><?= $user->status === UserStatus::Active ? 'Deshabilitar acceso' : 'Activar acceso' ?></button>
    </form>
</section>

<section class="app-form-section" aria-labelledby="user-password-heading">
    <h2 class="h4" id="user-password-heading">Reemplazar contraseña</h2>
    <p class="text-body-secondary">La nueva contraseña debe tener al menos cinco caracteres y se entrega externamente. Nunca se mostrará ni almacenará como texto.</p>
    <form class="row g-3" method="post" action="/admin/users/password">
        <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken ?? '') ?>">
        <input type="hidden" name="user_id" value="<?= $escape($user->userId) ?>">
        <div class="col-md-6"><label class="form-label" for="new-password">Nueva contraseña</label><input class="form-control" id="new-password" name="new_password" type="password" minlength="5" required autocomplete="new-password"></div>
        <div class="col-md-6"><label class="form-label" for="new-password-confirmation">Confirmar contraseña</label><input class="form-control" id="new-password-confirmation" name="new_password_confirmation" type="password" minlength="5" required autocomplete="new-password"></div>
        <div class="col-12"><button class="btn btn-primary" type="submit">Reemplazar contraseña</button></div>
    </form>
</section>

<?php if ($user->accessType === AdministrativeUserAccessType::Representative): ?>
<section class="app-form-section" aria-labelledby="representative-login-heading">
    <h2 class="h4" id="representative-login-heading">Identificador sincronizado</h2>
    <p class="mb-0">El identificador del Representative corresponde a su identificación personal. Para modificarlo, actualiza la identificación desde Personas; ambos valores se guardan en una sola operación.</p>
</section>
<?php elseif ($user->canChangeLoginIdentifier()): ?>
<section class="app-form-section" aria-labelledby="user-login-heading">
    <h2 class="h4" id="user-login-heading">Identificador de acceso</h2>
    <form class="row g-3 align-items-end" method="post" action="/admin/users/login-identifier">
        <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken ?? '') ?>">
        <input type="hidden" name="user_id" value="<?= $escape($user->userId) ?>">
        <div class="col-md-8"><label class="form-label" for="login-identifier">Nuevo identificador</label><input class="form-control" id="login-identifier" name="login_identifier" type="text" maxlength="254" value="<?= $escape(($loginIdentifier ?? '') !== '' ? $loginIdentifier : $user->loginIdentifier) ?>" required autocomplete="off"></div>
        <div class="col-md-4"><button class="btn btn-outline-primary w-100" type="submit">Actualizar identificador</button></div>
    </form>
</section>
<?php endif; ?>
<?php endif; ?>
