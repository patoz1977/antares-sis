<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$searchErrors = is_array($searchErrors ?? null) ? $searchErrors : [];
?>
<header class="app-page-header d-flex flex-column flex-md-row justify-content-between gap-3 align-items-md-start">
    <div>
        <p class="text-uppercase fw-semibold text-primary mb-2">Administración</p>
        <h1 class="display-6 fw-bold mb-2">Familias</h1>
        <p class="text-body-secondary mb-0">Busca una familia por sus datos o por sus miembros actuales, sin utilizar identificadores internos.</p>
    </div>
    <a class="btn btn-primary" href="/families/create"><i class="bi bi-people-fill me-2" aria-hidden="true"></i>Crear representante y familia</a>
</header>

<section class="app-form-section" aria-labelledby="family-search-heading">
    <h2 class="h4" id="family-search-heading">Buscar familia</h2>
    <p class="text-body-secondary">Elige un estado y, si necesitas acotar los resultados, un único criterio. Los resultados por persona explican qué miembros actuales produjeron la coincidencia.</p>
    <?php if ($searchErrors !== []): ?>
        <div class="alert alert-danger" role="alert"><ul class="mb-0"><?php foreach ($searchErrors as $error): ?><li><?= $escape($error) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <form class="row g-3 align-items-end" method="post" action="/families/search">
        <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken ?? '') ?>">
        <div class="col-lg-4">
            <label class="form-label" for="family-search-criterion">Criterio</label>
            <select class="form-select" id="family-search-criterion" name="criterion" required>
                <?php foreach (['family_code' => 'Código de familia exacto', 'display_name' => 'Nombre visible', 'person_first_name' => 'Primer nombre de miembro', 'person_middle_name' => 'Segundo nombre de miembro', 'person_first_surname' => 'Primer apellido de miembro', 'person_second_surname' => 'Segundo apellido de miembro', 'person_identification_number' => 'Identificación exacta de miembro', 'person_personal_email' => 'Correo personal exacto de miembro'] as $value => $label): ?>
                    <option value="<?= $escape($value) ?>" <?= ($criterion ?? '') === $value ? 'selected' : '' ?>><?= $escape($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-5">
            <label class="form-label" for="family-search-value">Valor</label>
            <input class="form-control" id="family-search-value" name="value" type="text" maxlength="254" value="<?= $escape($searchValue ?? '') ?>" autocomplete="off" aria-describedby="family-search-value-help">
            <div class="form-text" id="family-search-value-help">Déjalo vacío para filtrar únicamente por estado.</div>
        </div>
        <div class="col-lg-3">
            <label class="form-label" for="family-status-filter">Estado</label>
            <select class="form-select" id="family-status-filter" name="status" required>
                <?php foreach (['active' => 'Activas', 'inactive' => 'Inactivas', 'all' => 'Todas'] as $value => $label): ?>
                    <option value="<?= $escape($value) ?>" <?= ($directoryStatus ?? 'active') === $value ? 'selected' : '' ?>><?= $escape($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12 col-lg-3 ms-lg-auto"><button class="btn btn-outline-primary w-100" type="submit">Aplicar filtros</button></div>
    </form>
</section>

<?php if (($searchResult ?? null) instanceof \App\Family\Application\Discovery\Dto\FamilyDiscoveryResult): ?>
<section class="mt-4" aria-labelledby="family-results-heading">
    <h2 class="h4" id="family-results-heading">Resultados</h2>
    <?php if ($searchResult->rows === []): ?>
        <p class="alert alert-info">No se encontraron familias con ese criterio.</p>
    <?php else: ?>
        <?php if ($searchResult->hasMore): ?><p class="alert alert-warning">Existen más coincidencias. Refina la búsqueda para ver un resultado más específico.</p><?php endif; ?>
        <div class="table-responsive"><table class="table table-striped align-middle">
            <caption>Directorio administrativo de familias y sus integrantes vigentes.</caption>
            <thead><tr><th scope="col">Código</th><th scope="col">Familia</th><th scope="col">Representante principal</th><th scope="col">Teléfono</th><th scope="col">Correo</th><th scope="col">Estudiantes</th><th scope="col">Grado / paralelo</th><th scope="col">Estado</th><th scope="col"><span class="visually-hidden">Acción</span></th></tr></thead>
            <tbody><?php foreach ($searchResult->rows as $row): ?><tr>
                <td class="text-nowrap"><?= $escape($row->familyCode) ?></td>
                <td><?= $escape($row->displayName) ?><?php if ($row->matchedMembers !== []): ?><small class="d-block text-body-secondary">Coincidencia: <?php foreach ($row->matchedMembers as $index => $member): ?><?= $index > 0 ? '; ' : '' ?><?= $escape($member->fullName) ?> — <?= $escape($member->role) ?><?php endforeach; ?></small><?php endif; ?></td>
                <td><?= $row->primaryRepresentative === null ? '<span class="text-body-secondary">Sin representante principal</span>' : $escape($row->primaryRepresentative->fullName) ?></td>
                <td><?= $row->primaryRepresentative?->mobilePhone === null ? '<span class="text-body-secondary">Sin teléfono</span>' : $escape($row->primaryRepresentative->mobilePhone) ?></td>
                <td><?= $row->primaryRepresentative?->personalEmail === null ? '<span class="text-body-secondary">Sin correo</span>' : $escape($row->primaryRepresentative->personalEmail) ?></td>
                <td><?php if ($row->students === []): ?><span class="text-body-secondary">Sin estudiantes vigentes</span><?php else: ?><ul class="list-unstyled mb-0"><?php foreach ($row->students as $student): ?><li><?= $escape($student->fullName) ?></li><?php endforeach; ?></ul><?php endif; ?></td>
                <td><?php if ($row->students === []): ?><span class="text-body-secondary">No aplica</span><?php else: ?><ul class="list-unstyled mb-0"><?php foreach ($row->students as $student): ?><li><?= $escape($student->academicPlacement()) ?></li><?php endforeach; ?></ul><?php endif; ?></td>
                <td><?php $statusCode = $row->status; require dirname(__DIR__) . '/components/status-badge.php'; ?></td>
                <td class="text-nowrap"><a class="btn btn-sm btn-outline-primary" href="/families/show?id=<?= $escape($row->familyId) ?>">Abrir detalle</a></td>
            </tr><?php endforeach; ?></tbody>
        </table></div>
    <?php endif; ?>
</section>
<?php endif; ?>
