<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$emptyStateTitle = 'Persona no encontrada';
$emptyStateText = 'La persona solicitada no existe o ya no está disponible.';
?>
<header class="app-page-header">
    <p class="text-uppercase fw-semibold text-primary mb-2">Personas</p>
    <h1 class="display-6 fw-bold mb-2">Persona no encontrada</h1>
</header>
<?php require dirname(__DIR__) . '/components/empty-state.php'; ?>
<div class="app-action-group mt-4">
    <a class="btn btn-outline-primary" href="/persons">Volver a Personas</a>
</div>
