<?php

declare(strict_types=1);

$reportPageTitle = 'Directorio de estudiantes y representantes';
$reportPageDescription = 'Contacto familiar actual y ubicación académica del período seleccionado.';
$reportCsvPath = '/reports/enrollments/directory/csv';
include __DIR__ . '/_navigation.php';
?>
<?php if (!$selectionRequired): ?>
<?php if ($selectedPeriod->status->value === 'INACTIVE'): ?>
<p class="report-notice">El estado y la ubicación de la matrícula corresponden al período seleccionado. El contacto y la dirección son datos actuales del SIS.</p>
<?php endif; ?>
<?php if ($dataset === []): ?>
<?php $emptyStateTitle = 'No hay estudiantes activos'; $emptyStateText = 'No existen estudiantes activos para presentar en este directorio.'; require dirname(__DIR__, 2) . '/components/empty-state.php'; ?>
<?php else: ?>
<div class="report-table-wrap">
<table class="table table-striped table-hover">
    <caption>Directorio de estudiantes y representantes</caption>
    <thead class="table-light"><tr><th scope="col">Grado</th><th scope="col">Sección</th><th scope="col">Estudiante</th><th scope="col">Identificación del estudiante</th><th scope="col">Representante principal</th><th scope="col">Identificación del representante</th><th scope="col">Teléfonos</th><th scope="col">Correos</th><th scope="col">Dirección</th><th scope="col">Estado</th></tr></thead>
    <tbody>
<?php foreach ($dataset as $row): ?>
        <tr>
            <td><?= $display($row->gradeName) ?></td><td><?= $display($row->sectionName) ?></td>
            <td><?= $studentName($row->studentSurnames, $row->studentNames) ?></td>
            <td><?= $display($row->studentIdentificationType) ?> / <?= $display($row->studentIdentificationNumber) ?></td>
            <td><?= $row->representativeSurnames === null && $row->representativeNames === null ? '—' : $studentName($row->representativeSurnames, $row->representativeNames) ?></td>
            <td><?= $display($row->representativeIdentificationType) ?> / <?= $display($row->representativeIdentificationNumber) ?></td>
            <td>Móvil: <?= $display($row->representativeMobilePhone) ?><br>Fijo: <?= $display($row->representativeLandlinePhone) ?><br>Laboral: <?= $display($row->representativeWorkPhone) ?></td>
            <td>Personal: <?= $display($row->representativePersonalEmail) ?><br>Laboral: <?= $display($row->representativeWorkEmail) ?></td>
            <td><?= $display($row->studentAddress) ?></td>
            <td><?php $statusCode = $row->status->value; require dirname(__DIR__, 2) . '/components/status-badge.php'; ?></td>
        </tr>
<?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
<?php endif; ?>
