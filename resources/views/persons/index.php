<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<header class="app-page-header d-flex flex-column flex-md-row justify-content-between gap-3 align-items-md-start">
    <div>
        <p class="text-uppercase fw-semibold text-primary mb-2">Administración</p>
        <h1 class="display-6 fw-bold mb-2">Personas</h1>
        <p class="text-body-secondary mb-0">Crea una persona o consulta un registro existente mediante su identificador interno.</p>
    </div>
    <a class="btn btn-primary" href="/persons/create"><i class="bi bi-person-plus me-2" aria-hidden="true"></i>Crear persona</a>
</header>

<section class="app-form-section app-content-narrow" aria-labelledby="person-lookup-heading">
    <h2 class="h4" id="person-lookup-heading">Consultar persona por ID</h2>
    <p class="text-body-secondary">Ingresa el ID interno positivo de la persona que deseas consultar.</p>
    <form class="row g-3 align-items-end" method="get" action="/persons/show">
        <div class="col-sm-8">
            <label class="form-label" for="person-id">ID de persona</label>
            <input class="form-control" id="person-id" name="id" type="number" min="1" required>
        </div>
        <div class="col-sm-4">
            <button class="btn btn-outline-primary w-100" type="submit">Consultar persona</button>
        </div>
    </form>
</section>
