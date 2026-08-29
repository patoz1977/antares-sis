<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<header class="app-page-header">
    <h1>Acceso no autorizado</h1>
    <p class="text-body-secondary">No puedes acceder al contexto solicitado del Portal de representantes.</p>
</header>
<?php
$emptyStateTitle = 'Contexto no disponible';
$emptyStateText = 'Regresa al portal y selecciona únicamente una familia autorizada.';
require dirname(__DIR__) . '/components/empty-state.php';
?>
