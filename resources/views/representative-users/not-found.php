<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$emptyStateTitle = 'Representante no encontrado';
$emptyStateText = 'El contexto solicitado para administrar el usuario del representante no está disponible.';
?>
<header class="app-page-header"><h1 class="display-6 fw-bold">Usuario de representante no disponible</h1></header>
<?php require dirname(__DIR__) . '/components/empty-state.php'; ?>
<p class="mt-4"><a class="btn btn-outline-primary" href="/families">Volver a Familias</a></p>
