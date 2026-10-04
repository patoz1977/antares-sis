<?php

declare(strict_types=1);

$reportPageTitle = 'Salida y retiro de estudiantes';
$reportPageDescription = 'Información operativa actual para Inspección.';
$reportCsvPath = '/reports/enrollments/inspection/csv';
include __DIR__ . '/_navigation.php';
$studentFullName = static fn ($row): string => trim(implode(' ', array_filter([
    $row->studentFirstName,
    $row->studentMiddleName,
    $row->studentFirstSurname,
    $row->studentSecondSurname,
], static fn (?string $part): bool => $part !== null && $part !== '')));
?>
<?php if ($dataset === []): ?>
<?php $emptyStateTitle = 'No hay estudiantes activos'; $emptyStateText = 'No existen estudiantes activos para presentar en este reporte.'; require dirname(__DIR__, 2) . '/components/empty-state.php'; ?>
<?php else: ?>
<div class="report-table-wrap">
<table class="table table-striped table-hover">
    <caption>Información operativa actual de salida y retiro por estudiante</caption>
    <thead class="table-light"><tr><th scope="col">Grado</th><th scope="col">Paralelo</th><th scope="col">Estudiante</th><th scope="col">Identificación</th><th scope="col">Estado de salida</th><th scope="col">Personas autorizadas</th></tr></thead>
    <tbody>
<?php foreach ($dataset as $row): ?>
        <tr>
            <td><?= $display($row->gradeName) ?></td>
            <td><?= $display($row->sectionName) ?></td>
            <td><?= $escape($studentFullName($row)) ?></td>
            <td><?= $display($row->studentIdentificationType) ?><?php if ($row->studentIdentificationNumber !== null): ?><br><?= $escape($row->studentIdentificationNumber) ?><?php endif; ?></td>
            <td><?php if ($row->departureState->value === 'Sin persona autorizada registrada'): ?><strong class="text-danger"><?= $escape($row->departureState->value) ?></strong><?php else: ?><strong><?= $escape($row->departureState->value) ?></strong><?php endif; ?></td>
            <td>
<?php if ($row->authorizedPickups === []): ?>
                <span>—</span>
<?php else: ?>
                <ul class="mb-0 ps-3">
<?php foreach ($row->authorizedPickups as $pickup): ?>
                    <li><strong><?= $escape($pickup->name) ?></strong> — <?= $escape($pickup->relationship) ?><br><span class="text-body-secondary"><?= $display($pickup->identificationType) ?><?php if ($pickup->identificationNumber !== null): ?> · <?= $escape($pickup->identificationNumber) ?><?php endif; ?> · <?= $escape($pickup->mobilePhone) ?></span></li>
<?php endforeach; ?>
                </ul>
<?php endif; ?>
            </td>
        </tr>
<?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
