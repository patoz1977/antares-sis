<?php

declare(strict_types=1);

use App\IdentityAccess\Application\Dto\InstitutionalReportingAccessState;

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$statusLabel = ($state->userStatus?->value ?? null) === 'ACTIVE' ? 'Activo' : 'Deshabilitado';
$ineligibleMessages = [
    InstitutionalReportingAccessState::INELIGIBLE_REPRESENTATIVE => 'La persona ya tiene acceso como representante.',
    InstitutionalReportingAccessState::INELIGIBLE_STUDENT => 'La persona ya tiene un rol de estudiante.',
    InstitutionalReportingAccessState::INELIGIBLE_MULTIPLE_ROLES => 'La persona tiene roles incompatibles con este acceso dedicado.',
    InstitutionalReportingAccessState::EXACT_ADMIN => 'La cuenta administrativa principal no se gestiona como acceso institucional dedicado.',
    InstitutionalReportingAccessState::INCOHERENT => 'El estado actual de la cuenta no permite gestionar este acceso.',
];
?>
<header class="app-page-header">
    <p class="text-uppercase fw-semibold text-primary mb-2">Personas</p>
    <h1 class="display-6 fw-bold mb-2">Acceso institucional a reportes</h1>
    <p class="text-body-secondary mb-0">Gestiona únicamente el acceso dedicado y de solo lectura a los reportes institucionales.</p>
</header>

<?php if (is_string($successMessage ?? null)): ?>
    <div class="alert alert-success" role="status"><?= $escape($successMessage) ?></div>
<?php endif; ?>
<?php if (is_string($errorMessage ?? null)): ?>
    <div class="alert alert-danger" role="alert"><?= $escape($errorMessage) ?></div>
<?php endif; ?>
<?php if (($errors ?? []) !== []): ?>
    <div class="alert alert-danger" role="alert">
        <p class="fw-semibold mb-2">Revisa la operación:</p>
        <ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= $escape($error) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<section class="app-data-card" aria-labelledby="reporting-person-heading">
    <h2 class="h4" id="reporting-person-heading">Persona seleccionada</h2>
    <dl class="app-data-list">
        <dt>Nombre</dt><dd><?= $escape($state->personName) ?></dd>
        <?php if ($state->loginIdentifier !== null): ?>
            <dt>Identificador de acceso</dt><dd><?= $escape($state->loginIdentifier) ?></dd>
            <dt>Estado del usuario</dt><dd><?= $escape($statusLabel) ?></dd>
        <?php endif; ?>
    </dl>
</section>

<?php if ($state->state === InstitutionalReportingAccessState::ELIGIBLE_NO_USER): ?>
<section class="app-form-section mt-4" aria-labelledby="create-reporting-access-heading">
    <h2 class="h4" id="create-reporting-access-heading">Crear acceso institucional a reportes</h2>
    <form method="post" action="/institutional-reporting-access/provision" class="row g-3">
        <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
        <input type="hidden" name="person_id" value="<?= $escape($state->personId) ?>">
        <div class="col-12">
            <label class="form-label" for="reporting-login-identifier">Identificador de acceso</label>
            <input class="form-control" id="reporting-login-identifier" name="login_identifier" type="text" maxlength="254" autocomplete="username" value="<?= $escape($loginIdentifier ?? '') ?>" required>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="reporting-password">Contraseña inicial</label>
            <input class="form-control" id="reporting-password" name="password" type="password" minlength="5" autocomplete="new-password" required>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="reporting-password-confirmation">Confirmar contraseña</label>
            <input class="form-control" id="reporting-password-confirmation" name="password_confirmation" type="password" minlength="5" autocomplete="new-password" required>
        </div>
        <div class="col-12"><button class="btn btn-primary" type="submit">Crear acceso institucional a reportes</button></div>
    </form>
</section>
<?php elseif ($state->state === InstitutionalReportingAccessState::ELIGIBLE_ACTIVE_USER): ?>
<section class="app-form-section mt-4" aria-labelledby="grant-reporting-access-heading">
    <h2 class="h4" id="grant-reporting-access-heading">Asignar acceso institucional</h2>
    <p>La cuenta activa conservará su identidad y credenciales actuales.</p>
    <form method="post" action="/institutional-reporting-access/provision">
        <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
        <input type="hidden" name="person_id" value="<?= $escape($state->personId) ?>">
        <button class="btn btn-primary" type="submit">Asignar acceso a reportes</button>
    </form>
</section>
<?php elseif ($state->state === InstitutionalReportingAccessState::ACTIVE_DEDICATED): ?>
<section class="app-form-section mt-4" aria-labelledby="disable-reporting-access-heading">
    <h2 class="h4" id="disable-reporting-access-heading">Acceso activo</h2>
    <p>La cuenta puede consultar todos los reportes y descargar sus CSV.</p>
    <form method="post" action="/institutional-reporting-access/disable">
        <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
        <input type="hidden" name="person_id" value="<?= $escape($state->personId) ?>">
        <button class="btn btn-danger" type="submit">Deshabilitar acceso</button>
    </form>
</section>
<?php elseif ($state->state === InstitutionalReportingAccessState::DISABLED_DEDICATED): ?>
<section class="app-form-section mt-4" aria-labelledby="reactivate-reporting-access-heading">
    <h2 class="h4" id="reactivate-reporting-access-heading">Acceso deshabilitado</h2>
    <p>La reactivación volverá a comprobar que la persona siga siendo elegible.</p>
    <form method="post" action="/institutional-reporting-access/reactivate">
        <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
        <input type="hidden" name="person_id" value="<?= $escape($state->personId) ?>">
        <button class="btn btn-primary" type="submit">Reactivar acceso</button>
    </form>
</section>
<?php else: ?>
<div class="alert alert-info mt-4" role="status"><?= $escape($ineligibleMessages[$state->state] ?? 'La persona no es elegible para este acceso.') ?></div>
<?php endif; ?>

<div class="app-action-group mt-4">
    <a class="btn btn-outline-secondary" href="/persons/show?id=<?= $escape($state->personId) ?>">Volver a la persona</a>
</div>
