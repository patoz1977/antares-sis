<?php

declare(strict_types=1);

use App\Enrollment\Application\RepresentativePortal\Dto\RepresentativeEnrollmentPortalState;

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$portal = ($state ?? null) instanceof RepresentativeEnrollmentPortalState ? $state : null;
if ($portal === null || !is_array($members ?? null)) {
    throw new RuntimeException('Authorized Representative data context is required.');
}
$context = $portal->context;
$breadcrumbItems = [
    ['label' => 'Inicio', 'url' => '/representative'],
    ['label' => 'Actualización de datos'],
];
require dirname(__DIR__) . '/components/breadcrumb.php';
?>
<header class="app-page-header">
    <h1>Actualización de datos</h1>
    <p>Consulta y mantén los datos actuales de tu familia. Puedes entrar a cualquier sección.</p>
    <p class="text-body-secondary">Familia actual: <?= $escape($context->familyDisplayName) ?></p>
</header>
<?php if (!$portal->liveDataMaintenanceEnabled): ?>
<p class="alert alert-info" role="status">La consulta está disponible, pero el mantenimiento requiere un período activo y las aceptaciones institucionales correspondientes.</p>
<?php endif; ?>
<div class="row g-4">
    <div class="col-12 col-lg-6">
        <section class="app-data-card h-100" aria-labelledby="my-data-heading">
            <h2 class="h4" id="my-data-heading">Mis datos</h2>
            <p><strong><?= $escape($members['self']['name']) ?></strong> — <?= $escape($members['self']['relationship']) ?></p>
            <p>Correo personal: <?= $escape($portal->representativePerson->email ?? 'No informado') ?></p>
            <a class="btn btn-outline-primary" href="/representative/data/me">Actualizar mis datos</a>
        </section>
    </div>
    <div class="col-12 col-lg-6">
        <section class="app-data-card h-100" aria-labelledby="students-heading">
            <h2 class="h4" id="students-heading">Mis estudiantes</h2>
            <?php if ($context->students === []): ?>
            <p>No hay estudiantes activos en esta familia.</p>
            <?php else: ?>
            <ul class="list-unstyled mb-0">
                <?php foreach ($context->students as $student): ?>
                <li class="mb-3"><strong><?= $escape($student->displayName) ?></strong><br>
                    <a href="/representative/data/students?student_id=<?= $escape($student->student->id) ?>">Actualizar datos</a></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </section>
    </div>
</div>
<section class="app-data-card mt-4" aria-labelledby="other-representatives-heading">
    <h2 class="h4" id="other-representatives-heading">Otros representantes</h2>
    <?php if ($members['others'] === []): ?>
    <p>No hay otros representantes activos en esta familia.</p>
    <?php else: ?>
    <ul><?php foreach ($members['others'] as $member): ?>
        <li><?= $escape($member['name']) ?> — <?= $escape($member['relationship']) ?></li>
    <?php endforeach; ?></ul>
    <?php endif; ?>
</section>
<section class="mt-4" aria-labelledby="family-resources-heading">
    <h2 class="h4" id="family-resources-heading">Recursos familiares</h2>
    <div class="row g-3">
        <div class="col-12 col-md-4"><a class="card app-module-card text-decoration-none h-100" href="/representative/resources/addresses"><span class="card-body">Revisar direcciones</span></a></div>
        <div class="col-12 col-md-4"><a class="card app-module-card text-decoration-none h-100" href="/representative/resources/emergency-contacts"><span class="card-body">Revisar contactos de emergencia</span></a></div>
        <div class="col-12 col-md-4"><a class="card app-module-card text-decoration-none h-100" href="/representative/resources/authorized-pickups"><span class="card-body">Revisar personas autorizadas para retirar</span></a></div>
    </div>
</section>
