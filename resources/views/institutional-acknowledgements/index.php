<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$field = static fn (string $key, mixed $fallback = ''): string => $escape($values[$key] ?? $fallback);
?>
<header class="app-page-header">
    <p class="text-uppercase fw-semibold text-primary mb-2">Administración</p>
    <h1 class="display-6 fw-bold mb-2">Confirmaciones institucionales</h1>
    <p class="text-body-secondary mb-0">Gestiona por separado el ciclo de vida de los períodos académicos y sus requisitos de confirmación.</p>
</header>

<?php require dirname(__DIR__) . '/components/validation-summary.php'; ?>

<section class="app-form-section" aria-labelledby="period-selection-heading">
    <h2 class="h4" id="period-selection-heading">Seleccionar período académico</h2>
    <form class="row g-3 align-items-end" method="get" action="/institutional-acknowledgements">
        <div class="col-lg-9">
            <label class="form-label" for="academic-period">Período académico</label>
            <select class="form-select" id="academic-period" name="academic_period_id" required>
                <option value="">Selecciona un período académico</option>
                <?php foreach ($periods as $period): ?>
                <option value="<?= $escape($period->id) ?>"<?= ($selectedPeriod?->id ?? null) === $period->id ? ' selected' : '' ?>>
                    <?= $escape($period->code . ' — ' . $period->name . ' (' . $period->startsOn . ' a ' . $period->endsOn . ') — ' . $period->status) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-3"><button class="btn btn-outline-primary w-100" type="submit">Abrir período</button></div>
    </form>
</section>

<section class="mb-5" aria-labelledby="academic-period-lifecycle-heading">
    <h2 class="h3" id="academic-period-lifecycle-heading">Ciclo de vida de períodos académicos</h2>
    <p class="text-body-secondary">La activación del período es independiente del ciclo de vida de cada requisito.</p>
    <?php if ($periods === []): ?>
    <?php $emptyStateTitle = 'No hay períodos académicos configurados'; $emptyStateText = 'No existen períodos disponibles para administrar.'; require dirname(__DIR__) . '/components/empty-state.php'; ?>
    <?php else: ?>
    <div class="row g-4">
        <?php foreach ($periods as $period): ?>
        <div class="col-md-6 col-xl-4">
            <article class="app-data-card h-100">
                <h3 class="h5"><?= $escape($period->code . ' — ' . $period->name) ?></h3>
                <p><?php $statusCode = $period->status; require dirname(__DIR__) . '/components/status-badge.php'; ?></p>
                <p class="text-body-secondary"><?= $escape($period->startsOn . ' a ' . $period->endsOn) ?></p>
                <form method="post" action="/institutional-acknowledgements/academic-period/<?= $period->status === 'ACTIVE' ? 'deactivate' : 'activate' ?>">
                    <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken ?? '') ?>">
                    <input type="hidden" name="academic_period_id" value="<?= $escape($period->id) ?>">
                    <button class="btn <?= $period->status === 'ACTIVE' ? 'btn-outline-secondary' : 'btn-outline-primary' ?>" type="submit"><?= $period->status === 'ACTIVE' ? 'Desactivar' : 'Activar' ?> período</button>
                </form>
            </article>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>

<?php if (($selectedPeriod ?? null) !== null): ?>
<section class="app-data-card" aria-labelledby="selected-period-heading">
    <h2 class="h3" id="selected-period-heading"><?= $escape($selectedPeriod->code . ' — ' . $selectedPeriod->name) ?></h2>
    <dl class="app-data-list">
        <dt>Vigencia</dt><dd><?= $escape($selectedPeriod->startsOn . ' a ' . $selectedPeriod->endsOn) ?></dd>
        <dt>Estado</dt><dd><?php $statusCode = $selectedPeriod->status; require dirname(__DIR__) . '/components/status-badge.php'; ?></dd>
    </dl>
</section>

<section class="app-form-section" aria-labelledby="create-requirement-heading">
    <h2 class="h4" id="create-requirement-heading">Crear requisito</h2>
    <form method="post" action="/institutional-acknowledgements/requirements/create">
        <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken ?? '') ?>">
        <input type="hidden" name="academic_period_id" value="<?= $escape($selectedPeriod->id) ?>">
        <div class="app-form-grid">
            <div class="app-field-wide"><label class="form-label">Título<input class="form-control" name="title" maxlength="200" required value="<?= $field('title') ?>"></label></div>
            <div class="app-field-wide"><label class="form-label">URL<input class="form-control" name="url" type="url" maxlength="500" required value="<?= $field('url') ?>"></label></div>
            <div><label class="form-label">Referencia oficial<input class="form-control" name="official_reference" maxlength="255" value="<?= $field('official_reference') ?>"></label></div>
            <div><label class="form-label">Estado<select class="form-select" name="status" required><option value="">Selecciona un estado</option><option value="ACTIVE"<?= ($values['status'] ?? '') === 'ACTIVE' ? ' selected' : '' ?>>Activo</option><option value="INACTIVE"<?= ($values['status'] ?? '') === 'INACTIVE' ? ' selected' : '' ?>>Inactivo</option></select></label></div>
        </div>
        <button class="btn btn-primary mt-3" type="submit">Crear requisito</button>
    </form>
</section>

<section aria-labelledby="requirements-heading">
    <h2 class="h3" id="requirements-heading">Requisitos configurados</h2>
    <?php if ($requirements === []): ?>
    <?php $emptyStateTitle = 'No hay requisitos configurados'; $emptyStateText = 'El período académico seleccionado todavía no tiene requisitos.'; require dirname(__DIR__) . '/components/empty-state.php'; ?>
    <?php endif; ?>

    <?php foreach ($requirements as $requirement): ?>
    <article class="app-data-card">
        <div class="d-flex flex-column flex-md-row justify-content-between gap-2">
            <h3 class="h5"><?= $escape($requirement->title) ?></h3>
            <p><?php $statusCode = $requirement->status; require dirname(__DIR__) . '/components/status-badge.php'; ?></p>
        </div>
        <dl class="app-data-list mb-4">
            <dt>URL</dt><dd><?= $escape($requirement->url) ?></dd>
            <dt>Referencia oficial</dt><dd><?= $escape($requirement->officialReference ?? 'No registrada') ?></dd>
        </dl>
        <form method="post" action="/institutional-acknowledgements/requirements/update">
            <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken ?? '') ?>">
            <input type="hidden" name="academic_period_id" value="<?= $escape($selectedPeriod->id) ?>">
            <input type="hidden" name="requirement_id" value="<?= $escape($requirement->id) ?>">
            <div class="app-form-grid">
                <div class="app-field-wide"><label class="form-label">Título<input class="form-control" name="title" maxlength="200" required value="<?= $escape($requirement->title) ?>"></label></div>
                <div class="app-field-wide"><label class="form-label">URL<input class="form-control" name="url" type="url" maxlength="500" required value="<?= $escape($requirement->url) ?>"></label></div>
                <div><label class="form-label">Referencia oficial<input class="form-control" name="official_reference" maxlength="255" value="<?= $escape($requirement->officialReference ?? '') ?>"></label></div>
            </div>
            <button class="btn btn-outline-primary mt-3" type="submit">Guardar requisito</button>
        </form>
        <form class="mt-3" method="post" action="/institutional-acknowledgements/requirements/<?= $requirement->status === 'ACTIVE' ? 'deactivate' : 'activate' ?>">
            <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken ?? '') ?>">
            <input type="hidden" name="academic_period_id" value="<?= $escape($selectedPeriod->id) ?>">
            <input type="hidden" name="requirement_id" value="<?= $escape($requirement->id) ?>">
            <button class="btn <?= $requirement->status === 'ACTIVE' ? 'btn-outline-secondary' : 'btn-outline-success' ?>" type="submit"><?= $requirement->status === 'ACTIVE' ? 'Desactivar' : 'Activar' ?> requisito</button>
        </form>
    </article>
    <?php endforeach; ?>
</section>
<?php endif; ?>
