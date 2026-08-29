<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<header class="app-page-header d-flex flex-column flex-md-row justify-content-between gap-3 align-items-md-start">
    <div>
        <p class="text-uppercase fw-semibold text-primary mb-2">Administración</p>
        <h1 class="display-6 fw-bold mb-2">Familias</h1>
        <p class="text-body-secondary mb-0">Crea una familia con su representante o consulta un contexto familiar existente.</p>
    </div>
    <a class="btn btn-primary" href="/families/create"><i class="bi bi-people-fill me-2" aria-hidden="true"></i>Crear representante y familia</a>
</header>

<section class="app-form-section app-content-narrow" aria-labelledby="family-lookup-heading">
    <h2 class="h4" id="family-lookup-heading">Consultar familia por ID</h2>
    <p class="text-body-secondary">Ingresa el ID interno positivo de la familia.</p>
    <form class="row g-3 align-items-end" method="get" action="/families/show">
        <div class="col-sm-8">
            <label class="form-label" for="family-id">ID de familia</label>
            <input class="form-control" id="family-id" name="id" type="number" min="1" required>
        </div>
        <div class="col-sm-4">
            <button class="btn btn-outline-primary w-100" type="submit">Consultar familia</button>
        </div>
    </form>
</section>
