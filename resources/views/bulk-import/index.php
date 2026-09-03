<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$counts = is_array($preview['counts'] ?? null) ? $preview['counts'] : null;
$items = is_array($preview['items'] ?? null) ? $preview['items'] : [];
$issues = is_array($preview['issues'] ?? null) ? $preview['issues'] : [];
$classificationLabel = static fn (string $classification): string => match ($classification) {
    'NEW' => 'Nuevo',
    'ALREADY_EXISTS' => 'Ya existe',
    default => 'Conflicto',
};
?>
<header class="app-page-header">
    <p class="text-uppercase fw-semibold text-primary mb-2">Administración</p>
    <h1 class="display-6 fw-bold mb-2">Importación masiva</h1>
    <p class="lead text-body-secondary mb-0">
        Valida la plantilla oficial antes de crear familias, accesos de representantes y estudiantes.
    </p>
</header>

<?php if (is_string($errorMessage ?? null) && $errorMessage !== ''): ?>
<div class="alert alert-danger" role="alert"><?= $escape($errorMessage) ?></div>
<?php endif; ?>

<section class="card shadow-sm mb-4" aria-labelledby="bulk-import-instructions">
    <div class="card-body">
        <h2 class="h4" id="bulk-import-instructions">Preparar el archivo</h2>
        <ul class="mb-3">
            <li>Utiliza la <a href="/admin/bulk-import/template">plantilla XLSX oficial</a>.</li>
            <li>No cambies los nombres de las hojas ni de las columnas.</li>
            <li><code>family_code</code> usa el formato <code>F</code> seguido de ocho dígitos.</li>
            <li>Escribe identificadores como texto, fechas como <code>YYYY-MM-DD</code> y valores booleanos como <code>SI</code> o <code>NO</code>.</li>
            <li>El tamaño máximo permitido es 5 MiB.</li>
        </ul>

        <div class="row g-3">
            <div class="col-12 col-lg-4">
                <h3 class="h6">Tipos de documento</h3>
                <ul class="small mb-0">
                    <?php foreach ($documentTypes as $option): ?>
                    <li><code><?= $escape($option['code']) ?></code> — <?= $escape($option['name']) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div class="col-12 col-lg-4">
                <h3 class="h6">Sexos</h3>
                <ul class="small mb-0">
                    <?php foreach ($sexes as $option): ?>
                    <li><code><?= $escape($option['code']) ?></code> — <?= $escape($option['name']) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div class="col-12 col-lg-4">
                <h3 class="h6">Relaciones familiares</h3>
                <ul class="small mb-0">
                    <?php foreach ($relationshipTypes as $option): ?>
                    <li><code><?= $escape($option['code']) ?></code> — <?= $escape($option['name']) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
</section>

<section class="card shadow-sm mb-4" aria-labelledby="bulk-import-upload">
    <div class="card-body">
        <h2 class="h4" id="bulk-import-upload">Validar archivo</h2>
        <form method="post" action="/admin/bulk-import/preview" enctype="multipart/form-data">
            <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
            <div class="mb-3">
                <label class="form-label" for="bulk-import-workbook">Archivo XLSX <span aria-hidden="true">*</span></label>
                <input class="form-control" id="bulk-import-workbook" name="workbook" type="file"
                    accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                    required aria-describedby="bulk-import-workbook-help">
                <div class="form-text" id="bulk-import-workbook-help">Máximo 5 MiB. El archivo se elimina al terminar esta solicitud.</div>
            </div>
            <button class="btn btn-primary" type="submit">Validar archivo</button>
        </form>
    </div>
</section>

<?php if ($counts !== null): ?>
<section class="card shadow-sm mb-4" aria-labelledby="bulk-import-preview">
    <div class="card-body">
        <h2 class="h4" id="bulk-import-preview">Vista previa segura</h2>
        <div class="row g-3 mb-4" aria-label="Resumen de la vista previa">
            <?php foreach ([
                'families' => 'Familias',
                'new' => 'Nuevos',
                'already_exists' => 'Ya existentes',
                'conflicts' => 'Conflictos',
                'issues' => 'Observaciones',
            ] as $key => $label): ?>
            <div class="col-6 col-lg">
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
                <caption>Clasificación actual del archivo validado.</caption>
                <thead>
                    <tr>
                        <th scope="col">Familia</th>
                        <th scope="col">Nombre familiar</th>
                        <th scope="col">Persona</th>
                        <th scope="col">Documento</th>
                        <th scope="col">Código estudiantil</th>
                        <th scope="col">Clasificación</th>
                        <th scope="col">Detalle</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                    <tr>
                        <td><code><?= $escape($item['family_code'] ?? '') ?></code></td>
                        <td><?= $escape($item['display_name'] ?? '') ?></td>
                        <td><?= $escape($item['person_display_name'] ?? '') ?></td>
                        <td><?= $escape($item['masked_document_number'] ?? '') ?></td>
                        <td><?= $escape($item['institutional_code'] ?? '') ?></td>
                        <td><?= $escape($classificationLabel((string) ($item['classification'] ?? 'CONFLICT'))) ?></td>
                        <td><?= $escape($item['message'] ?? '') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php if ($issues !== []): ?>
        <div class="alert alert-warning" role="status">
            El archivo contiene observaciones. Las familias con conflicto no serán corregidas automáticamente.
        </div>
        <div class="table-responsive">
            <table class="table table-sm">
                <caption>Observaciones seguras de validación.</caption>
                <thead><tr><th scope="col">Categoría</th><th scope="col">Hoja</th><th scope="col">Fila</th><th scope="col">Campo</th><th scope="col">Mensaje</th></tr></thead>
                <tbody>
                    <?php foreach ($issues as $issue): ?>
                    <tr>
                        <td><?= $escape($issue['category'] ?? '') ?></td>
                        <td><?= $escape($issue['sheet'] ?? '') ?></td>
                        <td><?= $escape($issue['row'] ?? '') ?></td>
                        <td><?= $escape($issue['field'] ?? '') ?></td>
                        <td><?= $escape($issue['message'] ?? '') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <a class="btn btn-outline-secondary" href="/admin/bulk-import/errors.csv">Descargar errores CSV</a>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<?php if (is_string($previewToken ?? null) && $previewToken !== ''): ?>
<section class="card shadow-sm border-primary" aria-labelledby="bulk-import-apply">
    <div class="card-body">
        <h2 class="h4" id="bulk-import-apply">Aplicar archivo validado</h2>
        <p>Vuelve a seleccionar exactamente el mismo archivo. La vista previa vence en 15 minutos y solo puede utilizarse una vez.</p>
        <form method="post" action="/admin/bulk-import/apply" enctype="multipart/form-data">
            <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
            <input type="hidden" name="preview_token" value="<?= $escape($previewToken) ?>">
            <div class="mb-3">
                <label class="form-label" for="bulk-import-reupload">Archivo XLSX validado <span aria-hidden="true">*</span></label>
                <input class="form-control" id="bulk-import-reupload" name="workbook" type="file"
                    accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
            </div>
            <button class="btn btn-primary" type="submit">Aplicar importación</button>
        </form>
    </div>
</section>
<?php endif; ?>
