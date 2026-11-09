<?php

declare(strict_types=1);

use App\IdentityAccess\Application\AdministrativeUsers\Dto\AdministrativeUserSearchResult;
use App\IdentityAccess\Domain\UserStatus;

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$result = ($result ?? null) instanceof AdministrativeUserSearchResult
    ? $result
    : new AdministrativeUserSearchResult([], false);
$errors = is_array($errors ?? null) ? $errors : [];
?>
<header class="app-page-header">
    <p class="text-uppercase fw-semibold text-primary mb-2">Administración</p>
    <h1 class="display-6 fw-bold mb-2">Usuarios</h1>
    <p class="text-body-secondary mb-0">Consulta y administra únicamente cuentas existentes. Los tipos mostrados se derivan del estado real del SIS y no son roles nuevos.</p>
</header>

<?php if (is_string($successMessage ?? null) && $successMessage !== ''): ?>
<div class="alert alert-success" role="status"><?= $escape($successMessage) ?></div>
<?php endif; ?>
<?php if (is_string($errorMessage ?? null) && $errorMessage !== ''): ?>
<div class="alert alert-danger" role="alert"><?= $escape($errorMessage) ?></div>
<?php endif; ?>

<section class="app-form-section" aria-labelledby="user-search-heading">
    <h2 class="h4" id="user-search-heading">Buscar usuario</h2>
    <p class="text-body-secondary">Busca por nombre, identificación exacta o identificador de acceso exacto.</p>
    <?php if ($errors !== []): ?>
    <div class="alert alert-danger" role="alert"><ul class="mb-0">
        <?php foreach ($errors as $error): ?><li><?= $escape($error) ?></li><?php endforeach; ?>
    </ul></div>
    <?php endif; ?>
    <form class="row g-3 align-items-end" method="post" action="/admin/users/search">
        <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken ?? '') ?>">
        <div class="col-lg-4">
            <label class="form-label" for="user-search-criterion">Criterio</label>
            <select class="form-select" id="user-search-criterion" name="criterion" required>
                <?php foreach (['name' => 'Nombre', 'identification_number' => 'Identificación exacta', 'login_identifier' => 'Identificador de acceso exacto'] as $value => $label): ?>
                <option value="<?= $escape($value) ?>" <?= ($criterion ?? '') === $value ? 'selected' : '' ?>><?= $escape($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-5">
            <label class="form-label" for="user-search-value">Valor</label>
            <input class="form-control" id="user-search-value" name="value" type="text" maxlength="254" value="<?= $escape($searchValue ?? '') ?>" required autocomplete="off">
        </div>
        <div class="col-lg-3"><button class="btn btn-outline-primary w-100" type="submit">Buscar usuario</button></div>
    </form>
</section>

<section class="mt-4" aria-labelledby="user-results-heading">
    <h2 class="h4" id="user-results-heading">Usuarios existentes</h2>
    <?php if ($result->rows === []): ?>
    <p class="alert alert-info">No se encontraron usuarios.</p>
    <?php else: ?>
        <?php if ($result->hasMore): ?><p class="alert alert-warning">Existen más usuarios. Utiliza la búsqueda para acotar los resultados.</p><?php endif; ?>
        <div class="table-responsive">
            <table class="table table-striped align-middle">
                <caption>Usuarios existentes visibles según el criterio actual.</caption>
                <thead><tr>
                    <th scope="col">Persona</th>
                    <th scope="col">Identificador de acceso</th>
                    <th scope="col">Tipo de acceso</th>
                    <th scope="col">Estado</th>
                    <th scope="col">Último acceso</th>
                    <th scope="col"><span class="visually-hidden">Acción</span></th>
                </tr></thead>
                <tbody>
                <?php foreach ($result->rows as $user): ?>
                <tr>
                    <td>
                        <span class="fw-semibold"><?= $escape($user->fullName) ?></span><br>
                        <span class="small text-body-secondary"><?= $user->identificationNumber === null ? 'Identificación no registrada' : $escape($user->identificationNumber) ?></span>
                    </td>
                    <td><?= $escape($user->loginIdentifier) ?></td>
                    <td><?= $escape($user->accessType->label()) ?></td>
                    <td><span class="badge <?= $user->status === UserStatus::Active ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= $user->status === UserStatus::Active ? 'Activo' : 'Deshabilitado' ?></span></td>
                    <td><?= $user->lastAccessAt === null ? '<span class="text-body-secondary">Sin registro</span>' : $escape($user->lastAccessAt) . ' UTC' ?></td>
                    <td class="text-nowrap"><a class="btn btn-sm btn-outline-primary" href="/admin/users/manage?user_id=<?= $escape($user->userId) ?>">Administrar</a></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
