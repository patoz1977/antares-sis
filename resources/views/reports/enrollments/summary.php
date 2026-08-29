<?php

declare(strict_types=1);

$reportPageTitle = 'Resumen de matrículas';
$reportPageDescription = 'Totales por estado, grado y sección.';
$reportCsvPath = '/reports/enrollments/summary/csv';
include __DIR__ . '/_navigation.php';
?>
<?php if (!$selectionRequired): ?>
<p class="fs-5">Total: <strong><?= $escape($dataset->total) ?></strong></p>
<?php if ($dataset->rows === []): ?>
<?php $emptyStateTitle = 'No hay matrículas para este período'; $emptyStateText = 'El resumen no contiene registros para el período seleccionado.'; require dirname(__DIR__, 2) . '/components/empty-state.php'; ?>
<?php else: ?>
<div class="report-table-wrap">
<table class="table table-striped table-hover">
    <caption>Resumen de matrículas del período seleccionado</caption>
    <thead class="table-light"><tr><th scope="col">Estado</th><th scope="col">Grado</th><th scope="col">Sección</th><th class="text-end" scope="col">Cantidad</th></tr></thead>
    <tbody>
<?php foreach ($dataset->rows as $row): ?>
        <tr><td><?php $statusCode = $row->status->value; require dirname(__DIR__, 2) . '/components/status-badge.php'; ?></td><td><?= $display($row->gradeName) ?></td><td><?= $display($row->sectionName) ?></td><td class="text-end"><?= $escape($row->count) ?></td></tr>
<?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
<?php endif; ?>
