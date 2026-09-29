<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$counts = is_array($preview['counts'] ?? null) ? $preview['counts'] : null;
$items = is_array($preview['items'] ?? null) ? $preview['items'] : [];
$issues = is_array($preview['issues'] ?? null) ? $preview['issues'] : [];
$classificationLabel = static fn (string $classification): string => match ($classification) {
    'CREATE_DRAFT' => 'Crear DRAFT',
    'SET_PLACEMENT' => 'Asignar ubicación',
    'ALREADY_CORRECT' => 'Sin cambios',
    default => 'Conflicto',
};
?>
<header class="app-page-header">
    <p class="text-uppercase fw-semibold text-primary mb-2">Administración</p>
    <h1 class="display-6 fw-bold mb-2">Inicialización académica</h1>
    <p class="lead text-body-secondary mb-0">
        Inicializa matrículas DRAFT y su ubicación académica para Students existentes mediante un manifest controlado.
    </p>
</header>

<?php if (is_string($errorMessage ?? null) && $errorMessage !== ''): ?>
<div class="alert alert-danger" role="alert"><?= $escape($errorMessage) ?></div>
<?php endif; ?>

<section class="card shadow-sm mb-4" aria-labelledby="academic-initialization-contract">
    <div class="card-body">
        <h2 class="h4" id="academic-initialization-contract">Contrato del manifest</h2>
        <ul class="mb-0">
            <li>Archivo CSV UTF-8, con máximo 1000 filas y 1 MiB.</li>
            <li>Encabezados exactos: <code>institutional_code,grade_code,section_code</code>.</li>
            <li>No incluyas nombres, documentos, IDs físicos ni otros datos personales.</li>
            <li>El preflight valida el lote completo; Apply es todo-o-nada y no completa matrículas.</li>
            <li>Un Enrollment no DRAFT o un placement diferente bloquea todo el lote.</li>
        </ul>
    </div>
</section>

<section class="card shadow-sm mb-4" aria-labelledby="academic-initialization-upload">
    <div class="card-body">
        <h2 class="h4" id="academic-initialization-upload">Ejecutar preflight</h2>
        <form method="post" action="/admin/academic-initialization/preview" enctype="multipart/form-data">
            <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
            <div class="row g-3">
                <div class="col-12 col-lg-4">
                    <label class="form-label" for="academic-period-code">
                        Código del período académico <span aria-hidden="true">*</span>
                    </label>
                    <input class="form-control" id="academic-period-code" name="academic_period_code"
                        value="<?= $escape($academicPeriodCode ?? '') ?>" maxlength="100" required>
                </div>
                <div class="col-12 col-lg-8">
                    <label class="form-label" for="academic-initialization-manifest">
                        Manifest CSV <span aria-hidden="true">*</span>
                    </label>
                    <input class="form-control" id="academic-initialization-manifest" name="manifest"
                        type="file" accept=".csv,text/csv" required>
                </div>
            </div>
            <button class="btn btn-primary mt-3" type="submit">Validar lote completo</button>
        </form>
    </div>
</section>

<?php if ($counts !== null): ?>
<section class="card shadow-sm mb-4" aria-labelledby="academic-initialization-preview">
    <div class="card-body">
        <h2 class="h4" id="academic-initialization-preview">Resultado del preflight</h2>
        <p>Período académico: <strong><?= $escape($preview['academic_period_code'] ?? '') ?></strong></p>
        <div class="row g-3 mb-4" aria-label="Resumen del preflight">
            <?php foreach ([
                'rows' => 'Filas',
                'create_draft' => 'Crear DRAFT',
                'set_placement' => 'Asignar ubicación',
                'already_correct' => 'Sin cambios',
                'conflicts' => 'Conflictos',
                'issues' => 'Observaciones',
            ] as $key => $label): ?>
            <div class="col-6 col-lg-2">
                <div class="border rounded p-3 h-100">
                    <span class="d-block text-body-secondary small"><?= $escape($label) ?></span>
                    <strong class="fs-4"><?= $escape($counts[$key] ?? 0) ?></strong>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if ($items !== []): ?>
        <div class="table-responsive">
            <table class="table table-striped align-middle">
                <caption>Clasificación autoritativa por código institucional.</caption>
                <thead><tr><th>Fila</th><th>Student</th><th>Grade</th><th>Section</th><th>Acción</th><th>Detalle</th></tr></thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                    <tr>
                        <td><?= $escape($item['row'] ?? '') ?></td>
                        <td><code><?= $escape($item['institutional_code'] ?? '') ?></code></td>
                        <td><code><?= $escape($item['grade_code'] ?? '') ?></code></td>
                        <td><code><?= $escape($item['section_code'] ?? '') ?></code></td>
                        <td><?= $escape($classificationLabel((string) ($item['classification'] ?? 'CONFLICT'))) ?></td>
                        <td><?= $escape($item['message'] ?? '') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php if ($issues !== []): ?>
        <div class="alert alert-warning" role="alert">
            El lote no puede aplicarse. Corrige todas las observaciones y ejecuta un nuevo preflight.
        </div>
        <div class="table-responsive">
            <table class="table table-sm">
                <caption>Observaciones seguras del preflight.</caption>
                <thead><tr><th>Categoría</th><th>Fila</th><th>Campo</th><th>Mensaje</th></tr></thead>
                <tbody>
                    <?php foreach ($issues as $issue): ?>
                    <tr>
                        <td><?= $escape($issue['category'] ?? '') ?></td>
                        <td><?= $escape($issue['row'] ?? '') ?></td>
                        <td><?= $escape($issue['field'] ?? '') ?></td>
                        <td><?= $escape($issue['message'] ?? '') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<?php if (is_string($previewToken ?? null) && $previewToken !== ''): ?>
<section class="card shadow-sm border-primary" aria-labelledby="academic-initialization-apply">
    <div class="card-body">
        <h2 class="h4" id="academic-initialization-apply">Aplicar lote validado</h2>
        <p>
            Vuelve a seleccionar exactamente el mismo CSV. La autorización vence en 15 minutos, es de un solo uso
            y queda invalidada si cambia el archivo o el estado autoritativo.
        </p>
        <form method="post" action="/admin/academic-initialization/apply" enctype="multipart/form-data">
            <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
            <input type="hidden" name="preview_token" value="<?= $escape($previewToken) ?>">
            <label class="form-label" for="academic-initialization-apply-manifest">
                Mismo manifest CSV <span aria-hidden="true">*</span>
            </label>
            <input class="form-control" id="academic-initialization-apply-manifest" name="manifest"
                type="file" accept=".csv,text/csv" required>
            <button class="btn btn-danger mt-3" type="submit">Aplicar inicialización</button>
        </form>
    </div>
</section>
<?php endif; ?>
