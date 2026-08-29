<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$formatInstant = static fn (DateTimeImmutable $value): string =>
    $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$items = is_array($items ?? null) ? $items : [];
?>
<header class="app-page-header">
    <p class="text-uppercase fw-semibold text-primary mb-2">Administración</p>
    <h1 class="display-6 fw-bold mb-2">Matrículas enviadas</h1>
    <p class="text-body-secondary mb-0">Cola operativa de matrículas enviadas que esperan revisión administrativa.</p>
</header>

<?php if ($items === []): ?>
<?php $emptyStateTitle = 'No hay matrículas pendientes de revisión'; $emptyStateText = 'La cola incluye únicamente matrículas en estado Enviada.'; require dirname(__DIR__) . '/components/empty-state.php'; ?>
<?php else: ?>
<div class="table-responsive border rounded">
    <table class="table table-striped table-hover align-middle mb-0">
        <caption class="px-3">Matrículas enviadas pendientes de revisión</caption>
        <thead class="table-light">
            <tr>
                <th scope="col">Estudiante</th>
                <th scope="col">Familia actual</th>
                <th scope="col">Período académico</th>
                <th scope="col">Grado</th>
                <th scope="col">Fecha de envío (UTC)</th>
                <th scope="col">Acción</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $item): ?>
            <tr>
                <td class="fw-semibold"><?= $escape($item->studentDisplayName) ?></td>
                <td><?= $escape($item->familyDisplayName) ?></td>
                <td><?= $escape($item->academicPeriodDisplayName) ?></td>
                <td><?= $escape($item->gradeDisplayName ?? 'Sin asignar') ?></td>
                <td><?= $escape($formatInstant($item->submittedAt)) ?></td>
                <td><a class="btn btn-sm btn-outline-primary" href="/enrollments/review?id=<?= $escape($item->enrollmentId) ?>">Revisar</a></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
