<?php

declare(strict_types=1);

$reportPageTitle = 'Lista de estudiantes';
$showAllStudents = $selectedStudentStatus === 'all';
$reportPageDescription = $showAllStudents
    ? 'Estudiantes activos e inactivos y su estado de matrícula en el período seleccionado.'
    : 'Estudiantes activos y su estado de matrícula en el período seleccionado.';
$reportCsvPath = '/reports/enrollments/students/csv';
include __DIR__ . '/_navigation.php';
?>
<?php if (!$selectionRequired): ?>
<?php if ($dataset === []): ?>
<?php $emptyStateTitle = $showAllStudents ? 'No hay estudiantes' : 'No hay estudiantes activos'; $emptyStateText = $showAllStudents ? 'No existen estudiantes para presentar en este reporte.' : 'No existen estudiantes activos para presentar en este reporte.'; require dirname(__DIR__, 2) . '/components/empty-state.php'; ?>
<?php else: ?>
<div class="report-table-wrap">
<table class="table table-striped table-hover">
    <caption><?= $escape($showAllStudents ? 'Estudiantes activos e inactivos y estado de matrícula' : 'Estudiantes activos y estado de matrícula') ?></caption>
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
