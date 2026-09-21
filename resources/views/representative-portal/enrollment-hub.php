<?php

declare(strict_types=1);

use App\Enrollment\Application\RepresentativePortal\Dto\RepresentativeEnrollmentPortalState;
use App\InstitutionalDocuments\Application\RepresentativePortal\Dto\RepresentativeAcknowledgementPortalState;

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$portal = ($state ?? null) instanceof RepresentativeEnrollmentPortalState ? $state : null;
if ($portal === null) {
    throw new RuntimeException('Authorized Enrollment context is required.');
}
$period = $portal->context->academicPeriod;
$acknowledgements = ($acknowledgementState ?? null) instanceof RepresentativeAcknowledgementPortalState
    ? $acknowledgementState
    : null;
$studentEnrollments = is_array($enrollments ?? null) ? $enrollments : [];
$breadcrumbItems = [
    ['label' => 'Inicio', 'url' => '/representative'],
    ['label' => 'Matrícula'],
];
require dirname(__DIR__) . '/components/breadcrumb.php';
?>
<header class="app-page-header">
    <h1><?= $period === null ? 'Matrícula' : 'Matrícula ' . $escape($period->name) ?></h1>
    <p class="text-body-secondary">Familia actual: <?= $escape($portal->context->familyDisplayName) ?></p>
</header>
<?php if ($period === null): ?>
<p class="alert alert-info" role="status">No existe un período académico activo. No se puede iniciar ni mantener una matrícula anual.</p>
<?php endif; ?>
<section class="app-data-card" aria-labelledby="acknowledgements-heading">
    <h2 class="h4" id="acknowledgements-heading">Aceptaciones institucionales</h2>
    <?php if ($period === null): ?>
    <p>No disponibles hasta que exista un período académico activo.</p>
    <?php elseif ($acknowledgements?->status === 'pending'): ?>
    <p role="status">Pendientes para este período.</p>
    <a class="btn btn-primary" href="/representative/acknowledgements">Completar aceptaciones</a>
    <?php elseif ($acknowledgements?->status === 'completed'): ?>
    <p role="status">Completadas para este período.</p>
    <a href="/representative/acknowledgements">Revisar aceptaciones</a>
    <?php else: ?>
    <p role="status">No se requieren aceptaciones para este período.</p>
    <a href="/representative/acknowledgements">Revisar</a>
    <?php endif; ?>
</section>
<section class="mt-4" aria-labelledby="students-heading">
    <h2 class="h4" id="students-heading">Matrículas de tus estudiantes</h2>
    <?php if ($portal->context->students === []): ?>
    <p>No hay estudiantes activos en esta familia.</p>
    <?php else: ?>
    <div class="row g-3">
        <?php foreach ($portal->context->students as $student): ?>
        <?php
        $enrollment = $studentEnrollments[$student->student->id] ?? null;
        $status = $enrollment?->status;
        $description = match ($status) {
            'DRAFT' => 'Matrícula en proceso',
            'SUBMITTED' => 'Matrícula enviada',
            'COMPLETED' => 'Matrícula completada',
            'CANCELLED' => 'Matrícula cancelada',
            default => 'Pendiente de iniciar',
        };
        $action = match ($status) {
            'DRAFT' => 'Continuar matrícula',
            'SUBMITTED', 'COMPLETED', 'CANCELLED' => 'Ver matrícula',
            default => 'Comenzar matrícula',
        };
        ?>
        <div class="col-12 col-md-6">
            <article class="app-data-card h-100">
                <h3 class="h5"><?= $escape($student->displayName) ?></h3>
                <p><?= $escape($description) ?></p>
                <?php if ($period !== null): ?>
                <a href="/representative/enrollment?student_id=<?= $escape($student->student->id) ?>"><?= $escape($action) ?></a>
                <?php endif; ?>
            </article>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>
