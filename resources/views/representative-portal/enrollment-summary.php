<?php

declare(strict_types=1);

use App\Enrollment\Application\RepresentativePortal\Dto\RepresentativeEnrollmentPortalState;
use App\Enrollment\Application\RepresentativePortal\RepresentativeEnrollmentSectionStatus;

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$portal = ($state ?? null) instanceof RepresentativeEnrollmentPortalState ? $state : null;
$student = $portal?->selectedStudent;
if ($portal === null || $student === null) {
    throw new RuntimeException('Authorized Student Enrollment context is required.');
}
$period = $portal->context->academicPeriod;
$enrollment = $portal->enrollment;
$studentId = $student->student->id;
$suffix = '?student_id=' . $studentId;
$complete = static fn (RepresentativeEnrollmentSectionStatus $status): string =>
    $status === RepresentativeEnrollmentSectionStatus::Complete ? 'Completa' : 'Pendiente';
$breadcrumbItems = [
    ['label' => 'Inicio', 'url' => '/representative'],
    ['label' => 'Matrícula', 'url' => '/representative/enrollment'],
    ['label' => $student->displayName],
];
require dirname(__DIR__) . '/components/breadcrumb.php';
?>
<header class="app-page-header">
    <h1>Matrícula de <?= $escape($student->displayName) ?></h1>
    <p class="text-body-secondary"><?= $period === null ? 'Sin período académico activo' : $escape($period->name) ?></p>
    <?php if ($academicPlacement === null): ?>
    <p><strong>Grado:</strong> No asignado<br><strong>Sección:</strong> No asignada</p>
    <?php else: ?>
    <p><strong>Grado:</strong> <?= $escape($academicPlacement['grade']->name) ?><br><strong>Sección:</strong> <?= $escape($academicPlacement['section']?->name ?? 'No asignada') ?></p>
    <?php endif; ?>
</header>
<?php if ($enrollment === null): ?>
<section class="app-data-card">
    <h2 class="h4">Pendiente de iniciar</h2>
    <?php if ($portal->enrollmentDraftMaintenanceEnabled): ?>
    <form method="post" action="/representative/enrollment/open">
        <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken ?? '') ?>">
        <input type="hidden" name="expected_family_id" value="<?= $escape($portal->context->familyId) ?>">
        <input type="hidden" name="expected_academic_period_id" value="<?= $escape($period?->id ?? '') ?>">
        <input type="hidden" name="student_id" value="<?= $escape($studentId) ?>">
        <button type="submit" class="btn btn-primary">Iniciar matrícula</button>
    </form>
    <?php else: ?>
    <p>La matrícula no puede iniciarse hasta contar con un período activo y las aceptaciones requeridas.</p>
    <?php endif; ?>
</section>
<?php else: ?>
<p role="status"><strong>Estado:</strong> <?php $statusCode = $enrollment->status; require dirname(__DIR__) . '/components/status-badge.php'; ?></p>
<?php if ($enrollment->status !== 'DRAFT'): ?>
<p class="alert alert-info">La información anual de esta matrícula está en modo de solo lectura. Los datos actuales autorizados conservan sus propias reglas de mantenimiento.</p>
<?php endif; ?>
<?php endif; ?>
<section class="app-data-card mt-4" aria-labelledby="current-data-heading">
    <h2 class="h4" id="current-data-heading">Revisar datos actuales</h2>
    <ul class="app-progress-grid">
        <li>Mis datos — <a href="/representative/data/me<?= $escape($suffix) ?>">Revisar</a></li>
        <li>Datos de <?= $escape($student->displayName) ?> — <a href="/representative/data/students<?= $escape($suffix) ?>">Revisar</a></li>
        <li>Dirección — <a href="/representative/resources/addresses<?= $escape($suffix) ?>">Revisar</a></li>
        <li>Contactos de emergencia — <a href="/representative/resources/emergency-contacts<?= $escape($suffix) ?>">Revisar</a></li>
        <li>Personas autorizadas para retirar — <a href="/representative/resources/authorized-pickups<?= $escape($suffix) ?>">Revisar</a></li>
    </ul>
</section>
<?php if ($enrollment !== null): ?>
<section class="app-data-card mt-4" aria-labelledby="annual-data-heading">
    <h2 class="h4" id="annual-data-heading">Completar esta matrícula</h2>
    <p>El orden sugerido no es obligatorio. Puedes abrir cualquier sección y volver al resumen.</p>
    <ul class="app-progress-grid">
        <li>Facturación: <strong><?= $escape($complete($portal->progress->billing)) ?></strong> — <a href="/representative/enrollment/student/billing<?= $escape($suffix) ?>">Abrir</a></li>
        <li>Información médica: <strong><?= $escape($complete($portal->progress->medical)) ?></strong> — <a href="/representative/enrollment/student/medical<?= $escape($suffix) ?>">Abrir</a></li>
        <li>Transporte: <strong><?= $escape($complete($portal->progress->transport)) ?></strong> — <a href="/representative/enrollment/student/transport<?= $escape($suffix) ?>">Abrir</a></li>
        <li>Retiro o salida autónoma: <strong><?= $escape($complete($portal->progress->pickupOrLeaveAlone)) ?></strong> — <a href="/representative/enrollment/student/leave-alone<?= $escape($suffix) ?>">Abrir declaración anual</a></li>
    </ul>
</section>
<section class="app-consequential-panel mt-4">
    <h2 class="h4"><?= $enrollment->status === 'DRAFT' ? 'Finalización' : 'Estado y revisión' ?></h2>
    <a class="btn btn-primary" href="/representative/enrollment/review<?= $escape($suffix) ?>"><?= $enrollment->status === 'DRAFT' ? 'Revisar y enviar matrícula' : 'Ver revisión de matrícula' ?></a>
</section>
<?php endif; ?>
<p class="mt-4"><a href="/representative/enrollment">Volver a las matrículas de tu familia</a></p>
