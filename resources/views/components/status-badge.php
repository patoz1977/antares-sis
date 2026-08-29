<?php

declare(strict_types=1);

$statusCode = is_string($statusCode ?? null) ? $statusCode : '';
$statusLabels = [
    'ACTIVE' => 'Activo',
    'INACTIVE' => 'Inactivo',
    'DRAFT' => 'Borrador',
    'SUBMITTED' => 'Enviada',
    'COMPLETED' => 'Completada',
    'CANCELLED' => 'Cancelada',
    'NOT STARTED' => 'No iniciada',
];
$statusClasses = [
    'ACTIVE' => 'text-bg-success',
    'INACTIVE' => 'text-bg-secondary',
    'DRAFT' => 'text-bg-info',
    'SUBMITTED' => 'text-bg-primary',
    'COMPLETED' => 'text-bg-success',
    'CANCELLED' => 'text-bg-dark',
    'NOT STARTED' => 'text-bg-light border text-dark',
];
$statusLabel = $statusLabels[$statusCode] ?? $statusCode;
$statusClass = $statusClasses[$statusCode] ?? 'text-bg-light border text-dark';
?>
<span class="badge rounded-pill <?= $escape($statusClass) ?>"><?= $escape($statusLabel) ?></span>
