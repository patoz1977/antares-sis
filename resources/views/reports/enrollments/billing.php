<?php

declare(strict_types=1);

$reportPageTitle = 'Reporte de facturación';
$reportPageDescription = 'Información de facturación anual del período seleccionado.';
$reportCsvPath = '/reports/enrollments/billing/csv';
include __DIR__ . '/_navigation.php';
?>
<?php if (!$selectionRequired): ?>
<?php if ($dataset === []): ?>
<?php $emptyStateTitle = 'No hay estudiantes activos'; $emptyStateText = 'No existen datos de facturación para presentar en este reporte.'; require dirname(__DIR__, 2) . '/components/empty-state.php'; ?>
<?php else: ?>
<div class="report-table-wrap">
<table class="table table-striped table-hover">
    <caption>Información de facturación por estudiante</caption>
    <thead class="table-light"><tr><th scope="col">Grado</th><th scope="col">Sección</th><th scope="col">Estudiante</th><th scope="col">Estado</th><th scope="col">Tipo de identificación</th><th scope="col">Número de identificación</th><th scope="col">Nombre legal</th><th scope="col">Dirección de facturación</th><th scope="col">Correo de facturación</th><th scope="col">Teléfono</th></tr></thead>
    <tbody>
<?php foreach ($dataset as $row): ?>
        <tr><td><?= $display($row->gradeName) ?></td><td><?= $display($row->sectionName) ?></td><td><?= $studentName($row->studentSurnames, $row->studentNames) ?></td><td><?php $statusCode = $row->status->value; require dirname(__DIR__, 2) . '/components/status-badge.php'; ?></td><td><?= $display($row->identificationType) ?></td><td><?= $display($row->identificationNumber) ?></td><td><?= $display($row->legalName) ?></td><td><?= $display($row->billingAddress) ?></td><td><?= $display($row->billingEmail) ?></td><td><?= $display($row->phone) ?></td></tr>
<?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
<?php endif; ?>
