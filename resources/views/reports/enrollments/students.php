<?php

declare(strict_types=1);

$reportPageTitle = 'Lista de estudiantes';
$reportPageDescription = 'Estudiantes activos y su estado de matrícula en el período seleccionado.';
$reportCsvPath = '/reports/enrollments/students/csv';
include __DIR__ . '/_navigation.php';
?>
<?php if (!$selectionRequired): ?>
<?php if ($dataset === []): ?>
<?php $emptyStateTitle = 'No hay estudiantes activos'; $emptyStateText = 'No existen estudiantes activos para presentar en este reporte.'; require dirname(__DIR__, 2) . '/components/empty-state.php'; ?>
<?php else: ?>
<div class="report-table-wrap">
<table class="table table-striped table-hover">
    <caption>Estudiantes activos y estado de matrícula</caption>
    <thead class="table-light"><tr><th scope="col">Grado</th><th scope="col">Sección</th><th scope="col">Estudiante</th><th scope="col">Estado</th></tr></thead>
    <tbody>
<?php foreach ($dataset as $row): ?>
        <tr><td><?= $display($row->gradeName) ?></td><td><?= $display($row->sectionName) ?></td><td><?= $studentName($row->studentSurnames, $row->studentNames) ?></td><td><?php $statusCode = $row->status->value; require dirname(__DIR__, 2) . '/components/status-badge.php'; ?></td></tr>
<?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
<?php endif; ?>
