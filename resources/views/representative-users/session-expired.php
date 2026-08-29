<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<header class="app-page-header">
    <p class="text-uppercase fw-semibold text-primary mb-2">Credenciales</p>
    <h1 class="display-6 fw-bold mb-2">La sesión del formulario expiró</h1>
    <p class="text-body-secondary mb-0">Abre nuevamente la administración del usuario de representante antes de enviar cambios.</p>
</header>
<div class="alert alert-warning" role="alert">No se realizó ningún cambio de credenciales.</div>
<p><a class="btn btn-outline-primary" href="/families">Volver a Familias</a></p>
