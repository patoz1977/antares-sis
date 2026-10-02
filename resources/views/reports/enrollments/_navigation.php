<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$display = static fn (mixed $value): string => $value === null || $value === ''
    ? '—'
    : htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$studentName = static fn (?string $surnames, ?string $names): string => htmlspecialchars(
    trim((string) $surnames . ' ' . (string) $names),
    ENT_QUOTES,
    'UTF-8',
);
$boolean = static fn (?bool $value): string => $value === null ? '—' : ($value ? 'Sí' : 'No');
$reports = [
    '/reports/enrollments/summary' => 'Resumen de matrículas',
    '/reports/enrollments/students' => 'Lista de estudiantes',
    '/reports/enrollments/directory' => 'Directorio',
    '/reports/enrollments/billing' => 'Facturación',
    '/reports/enrollments/medical' => 'Información médica',
];
$reportPageTitle = is_string($reportPageTitle ?? null) ? $reportPageTitle : 'Reportes de matrículas';
$reportPageDescription = is_string($reportPageDescription ?? null) ? $reportPageDescription : '';
$reportCsvPath = is_string($reportCsvPath ?? null) ? $reportCsvPath : null;
?>
<header class="app-page-header d-flex flex-column flex-md-row justify-content-between gap-3 align-items-md-start">
    <div>
        <p class="text-uppercase fw-semibold text-primary mb-2">Administración</p>
        <h1 class="display-6 fw-bold mb-2"><?= $escape($reportPageTitle) ?></h1>
        <?php if ($reportPageDescription !== ''): ?><p class="text-body-secondary mb-0"><?= $escape($reportPageDescription) ?></p><?php endif; ?>
    </div>
    <?php if (!$selectionRequired && $reportCsvPath !== null): ?>
    <a class="btn btn-outline-primary" href="<?= $escape($reportCsvPath . $periodQuery) ?>"><i class="bi bi-download me-2" aria-hidden="true"></i>Exportar CSV</a>
    <?php endif; ?>
</header>

<?php $breadcrumbItems = [['label' => 'Inicio', 'url' => '/'], ['label' => 'Reportes de matrículas']]; require dirname(__DIR__, 2) . '/components/breadcrumb.php'; ?>

<nav class="report-nav mb-4" aria-label="Reportes de matrículas">
<?php foreach ($reports as $url => $label): ?>
    <a class="btn btn-sm btn-outline-primary" href="<?= $escape($url . $periodQuery) ?>"><?= $escape($label) ?></a>
<?php endforeach; ?>
</nav>

<section class="app-form-section" aria-labelledby="report-period-heading">
    <h2 class="h4" id="report-period-heading">Período académico</h2>
    <form class="row g-3 align-items-end" method="get" action="<?= $escape($reportPath) ?>">
        <div class="col-lg-9">
            <label class="form-label" for="academic_period_id">Período académico</label>
            <select class="form-select" id="academic_period_id" name="academic_period_id" required>
                <option value="">Selecciona un período académico</option>
<?php foreach ($periods as $period): ?>
                <option value="<?= $escape($period->id) ?>"<?= $selectedPeriodId === $period->id ? ' selected' : '' ?>><?= $escape($period->code . ' — ' . $period->name . ($period->status->value === 'ACTIVE' ? ' (Activo)' : '')) ?></option>
<?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-3"><button class="btn btn-outline-primary w-100" type="submit">Ver reporte</button></div>
    </form>
</section>

<?php if ($selectionRequired): ?>
<p class="report-notice" role="status">Selecciona un período académico para generar este reporte.</p>
<?php else: ?>
<p>Período seleccionado: <strong><?= $escape($selectedPeriod->code . ' — ' . $selectedPeriod->name) ?></strong> (<?= $escape($selectedPeriod->status->value === 'ACTIVE' ? 'Activo' : 'Inactivo') ?>)</p>

<section class="app-form-section" aria-labelledby="report-grade-section-heading">
    <div class="d-flex flex-column flex-md-row justify-content-between gap-2 align-items-md-start">
        <div>
            <h2 class="h4" id="report-grade-section-heading">Grados y paralelos</h2>
            <p class="text-body-secondary mb-0">Selecciona una o varias combinaciones de grado y paralelo presentes en este período. Sin selección se muestran todos.</p>
        </div>
        <?php if ($gradeSectionFilterActive): ?>
        <a class="btn btn-sm btn-outline-secondary" href="<?= $escape($reportPath . $allGradeSectionsQuery) ?>">Mostrar todos</a>
        <?php else: ?>
        <span class="badge text-bg-primary">Todos</span>
        <?php endif; ?>
    </div>

    <?php if ($gradeSectionOptions === []): ?>
    <p class="report-notice mt-3 mb-0" role="status">Este período no tiene combinaciones completas de grado y paralelo disponibles para filtrar.</p>
    <?php else: ?>
    <form class="mt-3" method="get" action="<?= $escape($reportPath) ?>">
        <input type="hidden" name="academic_period_id" value="<?= $escape($selectedPeriodId) ?>">
        <fieldset>
            <legend class="visually-hidden">Combinaciones de grado y paralelo</legend>
            <div class="row g-2">
<?php foreach ($gradeSectionOptions as $option): ?>
                <div class="col-md-6 col-xl-4">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="grade_section[]" id="grade_section_<?= $escape($option->gradeId . '_' . $option->sectionId) ?>" value="<?= $escape($option->key()) ?>"<?= in_array($option->key(), $selectedGradeSections, true) ? ' checked' : '' ?>>
                        <label class="form-check-label" for="grade_section_<?= $escape($option->gradeId . '_' . $option->sectionId) ?>"><?= $escape($option->label()) ?></label>
                    </div>
                </div>
<?php endforeach; ?>
            </div>
        </fieldset>
        <button class="btn btn-outline-primary mt-3" type="submit">Aplicar filtro</button>
    </form>
    <?php endif; ?>
</section>
<?php endif; ?>
