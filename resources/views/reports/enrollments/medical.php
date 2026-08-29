<?php

declare(strict_types=1);

$reportPageTitle = 'Reporte médico';
$reportPageDescription = 'Información médica anual sensible del período seleccionado.';
$reportCsvPath = '/reports/enrollments/medical/csv';
include __DIR__ . '/_navigation.php';
?>
<?php if (!$selectionRequired): ?>
<p class="report-notice app-sensitive-data">Acceso restringido: utiliza esta información únicamente para la finalidad institucional autorizada.</p>
<?php if ($dataset === []): ?>
<?php $emptyStateTitle = 'No hay estudiantes activos'; $emptyStateText = 'No existen datos médicos para presentar en este reporte.'; require dirname(__DIR__, 2) . '/components/empty-state.php'; ?>
<?php else: ?>
<div class="report-table-wrap">
<table class="table table-striped table-hover">
    <caption>Información médica anual por estudiante</caption>
    <thead class="table-light"><tr><th scope="col">Grado</th><th scope="col">Sección</th><th scope="col">Estudiante</th><th scope="col">Estado</th><th scope="col">Condición médica</th><th scope="col">Detalle</th><th scope="col">Alergias</th><th scope="col">Detalle de alergias</th><th scope="col">Medicación permanente</th><th scope="col">Medicación</th><th scope="col">Cuidado especial</th><th scope="col">Detalle del cuidado</th><th scope="col">Seguro médico</th><th scope="col">Aseguradora</th><th scope="col">Pediatra</th><th scope="col">Teléfono del pediatra</th><th scope="col">Observaciones</th></tr></thead>
    <tbody>
<?php foreach ($dataset as $row): ?>
        <tr><td><?= $display($row->gradeName) ?></td><td><?= $display($row->sectionName) ?></td><td><?= $studentName($row->studentSurnames, $row->studentNames) ?></td><td><?php $statusCode = $row->status->value; require dirname(__DIR__, 2) . '/components/status-badge.php'; ?></td><td><?= $boolean($row->hasMedicalCondition) ?></td><td><?= $display($row->medicalConditionDetail) ?></td><td><?= $boolean($row->hasAllergies) ?></td><td><?= $display($row->allergyDetail) ?></td><td><?= $boolean($row->takesPermanentMedication) ?></td><td><?= $display($row->medicationName) ?></td><td><?= $boolean($row->requiresSpecialCare) ?></td><td><?= $display($row->specialCareDetail) ?></td><td><?= $boolean($row->hasMedicalInsurance) ?></td><td><?= $display($row->insuranceProvider) ?></td><td><?= $display($row->pediatricianName) ?></td><td><?= $display($row->pediatricianPhone) ?></td><td><?= $display($row->observations) ?></td></tr>
<?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
<?php endif; ?>
