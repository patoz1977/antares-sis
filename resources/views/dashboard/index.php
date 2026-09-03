<?php

declare(strict_types=1);

$modules = [
    ['href' => '/persons', 'icon' => 'bi-person-vcard', 'title' => 'Personas', 'description' => 'Crear y mantener la información de personas.'],
    ['href' => '/families', 'icon' => 'bi-people', 'title' => 'Familias', 'description' => 'Gestionar familias y sus integrantes.'],
    ['href' => '/admin/bulk-import', 'icon' => 'bi-file-earmark-arrow-up', 'title' => 'Importación masiva', 'description' => 'Validar y aplicar la plantilla oficial de familias.'],
    ['href' => '/institutional-acknowledgements', 'icon' => 'bi-file-earmark-check', 'title' => 'Confirmaciones institucionales', 'description' => 'Configurar confirmaciones por período académico.'],
    ['href' => '/enrollments', 'icon' => 'bi-journal-check', 'title' => 'Matrículas', 'description' => 'Revisar y administrar matrículas enviadas.'],
    ['href' => '/reports/enrollments', 'icon' => 'bi-bar-chart', 'title' => 'Reportes', 'description' => 'Consultar los reportes básicos de matrícula.'],
];
?>
<header class="app-page-header">
    <p class="text-uppercase fw-semibold text-primary mb-2">Panel administrativo</p>
    <h1 class="display-6 fw-bold mb-2">Inicio</h1>
    <p class="lead text-body-secondary mb-0">Selecciona una opción para continuar.</p>
</header>

<?php if (($canAccessPersons ?? false) === true): ?>
<div class="row g-4">
    <?php foreach ($modules as $module): ?>
    <div class="col-12 col-md-6 col-xl-4">
        <article class="card app-module-card">
            <div class="card-body d-flex flex-column">
                <span class="app-module-icon mb-3" aria-hidden="true"><i class="bi <?= $module['icon'] ?>"></i></span>
                <h2 class="h5"><?= htmlspecialchars($module['title'], ENT_QUOTES, 'UTF-8') ?></h2>
                <p class="text-body-secondary flex-grow-1"><?= htmlspecialchars($module['description'], ENT_QUOTES, 'UTF-8') ?></p>
                <a class="btn btn-outline-primary align-self-start stretched-link" href="<?= htmlspecialchars($module['href'], ENT_QUOTES, 'UTF-8') ?>">
                    Abrir <span class="visually-hidden"><?= htmlspecialchars($module['title'], ENT_QUOTES, 'UTF-8') ?></span>
                </a>
            </div>
        </article>
    </div>
    <?php endforeach; ?>
</div>
<?php else: ?>
<div class="alert alert-info" role="status">La sesión se inició correctamente.</div>
<?php endif; ?>
