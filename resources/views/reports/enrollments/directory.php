<?php

declare(strict_types=1);

$reportPageTitle = 'Directorio de estudiantes y representantes';
$reportPageDescription = 'Contacto familiar actual y ubicación académica del período seleccionado.';
$reportCsvPath = '/reports/enrollments/directory/csv';
include __DIR__ . '/_navigation.php';
$atomicName = static fn (
    string $firstName,
    ?string $middleName,
    string $firstSurname,
    ?string $secondSurname,
): string => htmlspecialchars(trim(implode(' ', array_filter([
    $firstName,
    $middleName,
    $firstSurname,
    $secondSurname,
], static fn (?string $part): bool => $part !== null && trim($part) !== ''))), ENT_QUOTES, 'UTF-8');
?>
<?php if (!$selectionRequired): ?>
<?php if ($selectedPeriod->status->value === 'INACTIVE'): ?>
<p class="report-notice">El estado y la ubicación de la matrícula corresponden al período seleccionado. El contacto y la dirección son datos actuales del SIS.</p>
<?php endif; ?>
<?php if ($dataset === []): ?>
<?php $emptyStateTitle = 'No hay estudiantes activos'; $emptyStateText = 'No existen estudiantes activos para presentar en este directorio.'; require dirname(__DIR__, 2) . '/components/empty-state.php'; ?>
<?php else: ?>
<div class="report-table-wrap">
<table class="table table-striped table-hover align-top">
    <caption>Directorio de estudiantes y representantes</caption>
    <thead class="table-light"><tr><th scope="col">Grado</th><th scope="col">Paralelo</th><th scope="col">Estudiante</th><th scope="col">Estado</th><th scope="col">Representante 1</th><th scope="col">Representante 2</th><th scope="col">Dirección actual</th></tr></thead>
    <tbody>
<?php foreach ($dataset as $row): ?>
        <tr>
            <td><?= $display($row->gradeName) ?></td>
            <td><?= $display($row->sectionName) ?></td>
            <td>
                <strong><?= $atomicName($row->studentFirstName, $row->studentMiddleName, $row->studentFirstSurname, $row->studentSecondSurname) ?></strong><br>
                <span class="text-body-secondary">Identificación: <?= $display($row->studentIdentificationType) ?> / <?= $display($row->studentIdentificationNumber) ?></span>
            </td>
            <td><?php $statusCode = $row->status->value; require dirname(__DIR__, 2) . '/components/status-badge.php'; ?></td>
<?php foreach ([$row->representative1, $row->representative2] as $representative): ?>
            <td>
<?php if ($representative === null): ?>
                —
<?php else: ?>
                <strong><?= $atomicName($representative->firstName, $representative->middleName, $representative->firstSurname, $representative->secondSurname) ?></strong><br>
                <span>Relación con la familia: <?= $display($representative->relationship) ?></span><br>
                <span>Identificación: <?= $display($representative->identificationType) ?> / <?= $display($representative->identificationNumber) ?></span><br>
                <span>Móvil: <?= $display($representative->mobilePhone) ?></span><br>
                <span>Fijo: <?= $display($representative->landlinePhone) ?></span><br>
                <span>Correo personal: <?= $display($representative->personalEmail) ?></span>
<?php endif; ?>
            </td>
<?php endforeach; ?>
            <td><?= $display($row->studentAddress) ?></td>
        </tr>
<?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
<?php endif; ?>
