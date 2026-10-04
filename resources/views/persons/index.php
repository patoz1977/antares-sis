<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$searchErrors = is_array($searchErrors ?? null) ? $searchErrors : [];
?>
<header class="app-page-header d-flex flex-column flex-md-row justify-content-between gap-3 align-items-md-start">
    <div>
        <p class="text-uppercase fw-semibold text-primary mb-2">Administración</p>
        <h1 class="display-6 fw-bold mb-2">Personas</h1>
        <p class="text-body-secondary mb-0">Busca una persona por sus datos actuales sin utilizar identificadores internos.</p>
    </div>
    <a class="btn btn-primary" href="/persons/create"><i class="bi bi-person-plus me-2" aria-hidden="true"></i>Crear persona</a>
</header>

<section class="app-form-section" aria-labelledby="person-search-heading">
    <h2 class="h4" id="person-search-heading">Buscar persona</h2>
    <p class="text-body-secondary">Elige un único criterio. Nombres y apellidos admiten prefijos de palabras desde tres caracteres.</p>
    <?php if ($searchErrors !== []): ?>
        <div class="alert alert-danger" role="alert"><ul class="mb-0">
            <?php foreach ($searchErrors as $error): ?><li><?= $escape($error) ?></li><?php endforeach; ?>
        </ul></div>
    <?php endif; ?>
    <form class="row g-3 align-items-end" method="post" action="/persons/search">
        <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken ?? '') ?>">
        <div class="col-lg-4">
            <label class="form-label" for="person-search-criterion">Criterio</label>
            <select class="form-select" id="person-search-criterion" name="criterion" required>
                <?php foreach (['first_name' => 'Primer nombre', 'middle_name' => 'Segundo nombre', 'first_surname' => 'Primer apellido', 'second_surname' => 'Segundo apellido', 'identification_number' => 'Número de identificación exacto', 'personal_email' => 'Correo personal exacto'] as $value => $label): ?>
                    <option value="<?= $escape($value) ?>" <?= ($criterion ?? '') === $value ? 'selected' : '' ?>><?= $escape($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-5">
            <label class="form-label" for="person-search-value">Valor</label>
            <input class="form-control" id="person-search-value" name="value" type="text" maxlength="254" value="<?= $escape($searchValue ?? '') ?>" required autocomplete="off">
        </div>
        <div class="col-lg-3"><button class="btn btn-outline-primary w-100" type="submit">Buscar persona</button></div>
    </form>
</section>

<?php if (($searchResult ?? null) instanceof \App\Person\Application\Discovery\Dto\PersonDiscoveryResult): ?>
<section class="mt-4" aria-labelledby="person-results-heading">
    <h2 class="h4" id="person-results-heading">Resultados</h2>
    <?php if ($searchResult->rows === []): ?>
        <p class="alert alert-info">No se encontraron personas con ese criterio.</p>
    <?php else: ?>
        <?php if ($searchResult->hasMore): ?><p class="alert alert-warning">Existen más coincidencias. Refina la búsqueda para ver un resultado más específico.</p><?php endif; ?>
        <div class="table-responsive"><table class="table table-striped align-middle">
            <caption>Personas que coinciden con el criterio seleccionado.</caption>
            <thead><tr><th scope="col">Nombre actual</th><th scope="col">Identificación</th><th scope="col">Correo personal</th><th scope="col"><span class="visually-hidden">Acción</span></th></tr></thead>
            <tbody><?php foreach ($searchResult->rows as $row): ?><tr>
                <td><?= $escape($row->fullName()) ?></td>
                <td><?= $row->identificationNumber === null ? '<span class="text-body-secondary">No registrada</span>' : $escape($row->identificationNumber) ?></td>
                <td><?= $row->personalEmail === null ? '<span class="text-body-secondary">No registrado</span>' : $escape($row->personalEmail) ?></td>
                <td class="text-nowrap"><a class="btn btn-sm btn-outline-primary" href="/persons/show?id=<?= $escape($row->personId) ?>">Abrir detalle</a></td>
            </tr><?php endforeach; ?></tbody>
        </table></div>
    <?php endif; ?>
</section>
<?php endif; ?>
