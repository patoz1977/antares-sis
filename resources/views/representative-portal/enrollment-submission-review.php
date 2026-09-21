<?php

declare(strict_types=1);

use App\Enrollment\Application\Submission\Dto\RepresentativeEnrollmentSubmissionReview;

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$submissionReview = ($review ?? null) instanceof RepresentativeEnrollmentSubmissionReview ? $review : null;
if (!$submissionReview instanceof RepresentativeEnrollmentSubmissionReview) {
    throw new RuntimeException('Representative Enrollment Submission review is required.');
}

$personName = static fn (object $person): string => trim(implode(' ', array_filter([
    $person->firstName,
    $person->middleName,
    $person->firstSurname,
    $person->secondSurname,
], static fn (?string $part): bool => $part !== null && $part !== '')));
$yesNo = static fn (bool $value): string => $value ? 'Sí' : 'No';
$supplied = static fn (?string $value): string => $value === null || $value === '' ? 'No informado' : $value;
$formatInstant = static fn (?DateTimeImmutable $value): string =>
    $value?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s') ?? 'No registrado';

$student = $submissionReview->student;
$studentPerson = $student->person;
$representative = $submissionReview->representativePerson;
$enrollment = $submissionReview->enrollment;
$isDraft = $enrollment->status === 'DRAFT';
$period = $submissionReview->academicPeriod;
$billing = $enrollment->billingInformation;
$medical = $enrollment->medicalInformation;
$transport = $enrollment->transportInformation;
$placement = $enrollment->academicPlacement;
$pendingRequirements = array_values(array_filter(
    $submissionReview->validation->requirements,
    static fn (object $requirement): bool => !$requirement->satisfied,
));
$studentAddresses = is_array($studentAddresses ?? null) ? $studentAddresses : [];
$emergencyContacts = is_array($emergencyContacts ?? null) ? $emergencyContacts : [];
$authorizedPickups = is_array($authorizedPickups ?? null) ? $authorizedPickups : [];
$reviewLocation = '/representative/enrollment/review?student_id=' . $student->student->id;
$enrollmentLocation = '/representative/enrollment?student_id=' . $student->student->id;
?>
<?php
$breadcrumbItems = [
    ['label' => 'Portal', 'url' => '/representative'],
    ['label' => 'Matrícula', 'url' => $enrollmentLocation],
    ['label' => 'Revisión y envío'],
];
require dirname(__DIR__) . '/components/breadcrumb.php';
?>
<header class="app-page-header">
    <h1>Revisar y enviar matrícula</h1>
    <p class="text-body-secondary">Revisa la información actual de <strong><?= $escape($student->displayName) ?></strong> antes del envío.</p>
    <nav class="app-section-nav" aria-label="Acciones relacionadas con la revisión">
        <a href="<?= $escape($enrollmentLocation) ?>">Corregir información</a>
        <a href="/representative/data">Actualización de datos</a>
        <a href="/representative/acknowledgements">Aceptaciones institucionales</a>
    </nav>
</header>

<section class="app-context-banner" aria-labelledby="submission-context-heading">
    <div>
        <h2 class="h4" id="submission-context-heading">Contexto de matrícula</h2>
        <dl class="app-data-list">
        <dt>Familia actual</dt><dd><?= $escape($submissionReview->familyDisplayName) ?></dd>
        <dt>Estudiante</dt><dd><?= $escape($student->displayName) ?></dd>
        <dt>Código institucional</dt><dd><?= $escape($student->student->institutionalCode) ?></dd>
        <dt>Período académico</dt><dd><?= $escape($period->name) ?></dd>
        <dt>Estado de matrícula</dt><dd><?php $statusCode = $enrollment->status; require dirname(__DIR__) . '/components/status-badge.php'; ?></dd>
        <dt>Inicio (UTC)</dt><dd><?= $escape($formatInstant($enrollment->startedAt)) ?></dd>
        <dt>Envío (UTC)</dt><dd><?= $escape($formatInstant($enrollment->submittedAt)) ?></dd>
        <dt>Finalización (UTC)</dt><dd><?= $escape($formatInstant($enrollment->completedAt)) ?></dd>
        <dt>Cancelación (UTC)</dt><dd><?= $escape($formatInstant($enrollment->cancelledAt)) ?></dd>
        <dt>Grado</dt><dd><?= $escape(is_string($gradeName ?? null) ? $gradeName : ($placement?->gradeId ?? 'No asignado')) ?></dd>
        <dt>Sección</dt><dd><?= $escape(is_string($sectionName ?? null) ? $sectionName : ($placement?->sectionId ?? 'No asignada')) ?></dd>
    </dl>
    </div>
</section>

<section class="app-data-card" aria-labelledby="current-live-data-heading">
    <h2 id="current-live-data-heading">Datos actuales del SIS</h2>
    <p>Esta información es actual y no constituye una copia histórica de la matrícula.</p>
    <h3 class="h5">Representante actual</h3>
    <dl class="app-data-list">
        <dt>Nombre</dt><dd><?= $escape($personName($representative)) ?></dd>
        <dt>Fecha de nacimiento</dt><dd><?= $escape($representative->birthDate->format('Y-m-d')) ?></dd>
        <dt>Correo electrónico</dt><dd><?= $escape($supplied($representative->email)) ?></dd>
        <dt>Teléfono móvil</dt><dd><?= $escape($supplied($representative->mobilePhone)) ?></dd>
        <dt>Teléfono convencional</dt><dd><?= $escape($supplied($representative->landlinePhone)) ?></dd>
    </dl>
    <h3 class="h5 mt-4">Estudiante actual</h3>
    <dl class="app-data-list">
        <dt>Nombre</dt><dd><?= $escape($personName($studentPerson)) ?></dd>
        <dt>Fecha de nacimiento</dt><dd><?= $escape($studentPerson->birthDate->format('Y-m-d')) ?></dd>
        <dt>Fecha de admisión</dt><dd><?= $escape($student->student->admissionDate->format('Y-m-d')) ?></dd>
        <dt>Estado</dt><dd><?php $statusCode = $student->student->status->value; require dirname(__DIR__) . '/components/status-badge.php'; ?></dd>
    </dl>
</section>

<section class="app-data-card" aria-labelledby="current-family-resources-heading">
    <h2 id="current-family-resources-heading">Recursos familiares actuales</h2>
    <p>Estos son los recursos activos asignados actualmente al estudiante.</p>
    <h3 class="h5">Dirección del estudiante</h3>
    <?php if ($studentAddresses === []): ?>
    <p>No hay una dirección asignada actualmente.</p>
    <?php else: ?>
    <?php foreach ($studentAddresses as $address): ?>
    <address>
        <strong><?= $escape($address->label) ?></strong><br>
        <?= $escape($address->mainStreet) ?><?= $address->streetNumber === null ? '' : ' ' . $escape($address->streetNumber) ?><br>
        <?= $escape($supplied($address->sector)) ?>
    </address>
    <?php endforeach; ?>
    <?php endif; ?>

    <h3 class="h5 mt-4">Contactos de emergencia</h3>
    <?php if ($emergencyContacts === []): ?>
    <p>No hay contactos de emergencia asignados.</p>
    <?php else: ?>
    <ul>
        <?php foreach ($emergencyContacts as $entry): ?>
        <li>
            <?= $escape($entry['contact']->names) ?> — <?= $escape($entry['contact']->mobilePhone) ?>
            (prioridad <?= $escape($entry['priority'] ?? 'no informada') ?>)
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <h3 class="h5 mt-4">Personas autorizadas para retirar</h3>
    <?php if ($authorizedPickups === []): ?>
    <p>No hay personas autorizadas asignadas.</p>
    <?php else: ?>
    <ul>
        <?php foreach ($authorizedPickups as $pickup): ?>
        <li><?= $escape($pickup->names) ?> — <?= $escape($pickup->mobilePhone) ?></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <p><a href="/representative/data">Corregir direcciones, contactos o retiros autorizados actuales</a>.</p>
</section>

<section class="app-data-card" aria-labelledby="annual-information-heading">
    <h2 id="annual-information-heading">Información anual de matrícula</h2>
    <p>Esta información pertenece a la matrícula y queda en modo de solo lectura después del envío.</p>
    <h3 class="h5">Facturación</h3>
    <?php if ($billing === null): ?>
    <p>No informada.</p>
    <?php else: ?>
    <dl class="app-data-list">
        <dt>Número de identificación</dt><dd><?= $escape($billing->identificationNumber) ?></dd>
        <dt>Nombre o razón social</dt><dd><?= $escape($billing->legalName) ?></dd>
        <dt>Dirección de facturación</dt><dd><?= $escape($billing->billingAddress) ?></dd>
        <dt>Correo de facturación</dt><dd><?= $escape($billing->billingEmail) ?></dd>
        <dt>Teléfono</dt><dd><?= $escape($billing->phone) ?></dd>
    </dl>
    <?php endif; ?>

    <div class="app-sensitive-data mt-4 p-3">
    <h3 class="h5">Información médica</h3>
    <?php if ($medical === null): ?>
    <p>No informada.</p>
    <?php else: ?>
    <dl class="app-data-list">
        <dt>Condición médica</dt><dd><?= $escape($yesNo($medical->hasMedicalCondition)) ?></dd>
        <dt>Detalle de condición</dt><dd><?= $escape($supplied($medical->medicalConditionDetail)) ?></dd>
        <dt>Alergias</dt><dd><?= $escape($yesNo($medical->hasAllergies)) ?></dd>
        <dt>Detalle de alergias</dt><dd><?= $escape($supplied($medical->allergyDetail)) ?></dd>
        <dt>Medicación permanente</dt><dd><?= $escape($yesNo($medical->takesPermanentMedication)) ?></dd>
        <dt>Nombre del medicamento</dt><dd><?= $escape($supplied($medical->medicationName)) ?></dd>
        <dt>Cuidados especiales</dt><dd><?= $escape($yesNo($medical->requiresSpecialCare)) ?></dd>
        <dt>Detalle de cuidados</dt><dd><?= $escape($supplied($medical->specialCareDetail)) ?></dd>
        <dt>Seguro médico</dt><dd><?= $escape($yesNo($medical->hasMedicalInsurance)) ?></dd>
        <dt>Proveedor del seguro</dt><dd><?= $escape($supplied($medical->insuranceProvider)) ?></dd>
        <dt>Pediatra</dt><dd><?= $escape($supplied($medical->pediatricianName)) ?></dd>
        <dt>Teléfono del pediatra</dt><dd><?= $escape($supplied($medical->pediatricianPhone)) ?></dd>
        <dt>Observaciones</dt><dd><?= $escape($supplied($medical->observations)) ?></dd>
    </dl>
    <?php endif; ?>
    </div>

    <h3 class="h5 mt-4">Transporte y salida</h3>
    <p>Requiere transporte institucional: <?= $escape($transport === null ? 'No informado' : $yesNo($transport->requiresInstitutionalTransport)) ?></p>
    <p>Autorizado para salir solo: <?= $escape($yesNo($enrollment->isAuthorizedToLeaveAlone)) ?></p>
    <p><a href="<?= $escape($enrollmentLocation) ?>">Corregir información anual de matrícula</a>.</p>
</section>

<section class="app-data-card" aria-labelledby="acknowledgement-state-heading">
    <h2 id="acknowledgement-state-heading">Aceptaciones institucionales</h2>
    <p><?= $submissionReview->acknowledgementsSatisfied
        ? 'Completadas para el período académico actual.'
        : 'Pendientes para el período académico actual.' ?></p>
    <?php if (!$submissionReview->acknowledgementsSatisfied): ?>
    <p><a href="/representative/acknowledgements">Revisar aceptaciones institucionales</a>.</p>
    <?php endif; ?>
</section>

<?php if ($isDraft): ?>
<section class="app-consequential-panel" aria-labelledby="submission-readiness-heading">
    <h2 id="submission-readiness-heading">Preparación para el envío</h2>
    <?php if ($submissionReview->validation->isSubmittable): ?>
    <p class="alert alert-success" role="status">Todos los requisitos actuales para el envío están completos.</p>
    <?php else: ?>
    <p class="alert alert-warning" role="status">Esta matrícula todavía no puede enviarse.</p>
    <ul>
        <?php foreach ($pendingRequirements as $requirement): ?>
        <li><?= $escape($requirement->message) ?></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <?php if ($submissionReview->validation->isSubmittable): ?>
    <p><strong>Después del envío, Facturación, Información médica, Transporte y autorización de salida quedan en modo de solo lectura hasta que la institución reabra la matrícula.</strong></p>
    <p>Puedes seguir actualizando los datos personales y familiares que tu cuenta tenga permitidos.</p>
    <form method="post" action="/representative/enrollment/submit">
        <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken ?? '') ?>">
        <input type="hidden" name="expected_family_id" value="<?= $escape($submissionReview->familyId) ?>">
        <input type="hidden" name="expected_academic_period_id" value="<?= $escape($period->id) ?>">
        <input type="hidden" name="student_id" value="<?= $escape($student->student->id) ?>">
        <button type="submit" class="btn btn-primary">
            <?= $enrollment->submittedAt === null ? 'Enviar matrícula' : 'Reenviar matrícula' ?>
        </button>
    </form>
    <?php endif; ?>
</section>
<?php else: ?>
<section class="app-consequential-panel" aria-labelledby="enrollment-lifecycle-heading">
    <h2 id="enrollment-lifecycle-heading">Estado de la matrícula</h2>
    <?php if ($enrollment->status === 'SUBMITTED'): ?>
    <p role="status">Esta matrícula fue enviada y está disponible para revisión institucional.</p>
    <?php elseif ($enrollment->status === 'COMPLETED'): ?>
    <p role="status">Esta matrícula fue completada por la institución.</p>
    <?php elseif ($enrollment->status === 'CANCELLED'): ?>
    <p role="status">Esta matrícula fue cancelada.</p>
    <?php endif; ?>
    <p>La información anual se encuentra en modo de solo lectura. Los datos actuales autorizados conservan sus propias reglas de mantenimiento.</p>
</section>
<?php endif; ?>

<div class="app-action-group mt-4">
    <a class="btn btn-outline-secondary" href="<?= $escape($reviewLocation) ?>">Actualizar revisión</a>
    <a class="btn btn-link" href="/representative">Volver al portal</a>
</div>
