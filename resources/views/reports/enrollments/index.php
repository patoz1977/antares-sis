<?php

declare(strict_types=1);

$reportPageTitle = 'Reportes de matrículas';
$reportPageDescription = 'Selecciona un período académico y abre uno de los cinco reportes de solo lectura.';
include __DIR__ . '/_navigation.php';
?>
<div class="row g-4">
<?php foreach ([
    '/reports/enrollments/summary' => ['Resumen de matrículas', 'Totales por estado, grado y sección.', 'bi-bar-chart'],
    '/reports/enrollments/students' => ['Lista de estudiantes', 'Estudiantes activos y su situación de matrícula.', 'bi-people'],
    '/reports/enrollments/directory' => ['Directorio de estudiantes y representantes', 'Información actual de contacto y ubicación anual.', 'bi-person-lines-fill'],
    '/reports/enrollments/billing' => ['Reporte de facturación', 'Datos anuales de facturación registrados.', 'bi-receipt'],
    '/reports/enrollments/medical' => ['Reporte médico', 'Información médica anual de acceso restringido.', 'bi-heart-pulse'],
] as $url => [$label, $description, $icon]): ?>
    <div class="col-md-6 col-xl-4">
        <article class="card app-module-card">
            <div class="card-body">
                <span class="app-module-icon mb-3" aria-hidden="true"><i class="bi <?= $escape($icon) ?>"></i></span>
                <h2 class="h5"><?= $escape($label) ?></h2>
                <p class="text-body-secondary"><?= $escape($description) ?></p>
                <a class="stretched-link" href="<?= $escape($url . $periodQuery) ?>">Abrir reporte</a>
            </div>
        </article>
    </div>
<?php endforeach; ?>
</div>
