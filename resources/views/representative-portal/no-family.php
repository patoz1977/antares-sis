<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<header class="app-page-header">
    <h1>Portal de representantes no disponible</h1>
    <p class="text-body-secondary">No existe una familia autorizada disponible para tu cuenta.</p>
</header>
<?php
$emptyStateTitle = 'Sin contexto familiar';
$emptyStateText = 'Comunícate con la institución si consideras que deberías tener acceso a una familia.';
require dirname(__DIR__) . '/components/empty-state.php';
?>
