<?php

declare(strict_types=1);

use App\Enrollment\Application\Administrative\Dto\AdministrativeEnrollmentReviewContext;

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$review = ($context ?? null) instanceof AdministrativeEnrollmentReviewContext ? $context : null;
if (!$review instanceof AdministrativeEnrollmentReviewContext) {
    throw new RuntimeException('Administrative Enrollment review context is required.');
}
$name = static fn (object $person): string => trim(implode(' ', array_filter([
    $person->firstName,
    $person->middleName,
    $person->firstSurname,
    $person->secondSurname,
], static fn (?string $part): bool => $part !== null && $part !== '')));
$supplied = static fn (?string $value): string => $value === null || $value === '' ? 'No registrado' : $value;
$yesNo = static fn (bool $value): string => $value ? 'Sí' : 'No';
$formatInstant = static fn (?DateTimeImmutable $value): string =>
    $value?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s') ?? 'No registrada';
$enrollment = $review->enrollment;
$resources = $review->currentFamilyResources;
$billing = $enrollment->billingInformation;
$medical = $enrollment->medicalInformation;
$transport = $enrollment->transportInformation;
?>
    <header class="app-page-header d-flex flex-column flex-md-row justify-content-between gap-3 align-items-md-start">
        <div>
            <p class="text-uppercase fw-semibold text-primary mb-2">Administración de matrículas</p>
            <h1 class="display-6 fw-bold mb-2">Revisión administrativa</h1>
            <p class="text-body-secondary mb-0">Contrasta datos actuales del SIS con la información anual de la matrícula.</p>
        </div>
        <a class="btn btn-outline-secondary" href="/enrollments">Volver a la cola</a>
    </header>

    <section class="app-data-card" aria-labelledby="lifecycle-identity-heading">
        <h2 class="h3" id="lifecycle-identity-heading">Identidad y ciclo de vida</h2>
        <dl class="app-data-list">
            <dt>ID de matrícula</dt><dd><?= $escape($enrollment->id) ?></dd>
            <dt>Estado</dt><dd><?php $statusCode = $enrollment->status; require dirname(__DIR__) . '/components/status-badge.php'; ?></dd>
            <dt>Período académico</dt>
            <dd><?= $escape($review->academicPeriod->name) ?> (<?= $escape($review->academicPeriod->code) ?>, <?= $escape($review->academicPeriod->status) ?>)</dd>
            <dt>Inicio (UTC)</dt><dd><?= $escape($formatInstant($enrollment->startedAt)) ?></dd>
            <dt>Envío (UTC)</dt><dd><?= $escape($formatInstant($enrollment->submittedAt)) ?></dd>
            <dt>Finalización (UTC)</dt><dd><?= $escape($formatInstant($enrollment->completedAt)) ?></dd>
            <dt>Cancelación (UTC)</dt><dd><?= $escape($formatInstant($enrollment->cancelledAt)) ?></dd>
        </dl>
    </section>

    <section class="app-data-card" aria-labelledby="current-sis-information-heading">
        <h2 class="h3" id="current-sis-information-heading">Datos actuales del SIS</h2>
        <p class="alert alert-info">Esta sección muestra información viva y actual al momento de la revisión; no es una copia histórica del envío.</p>

        <h3 class="h5 mt-4">Estudiante actual</h3>
        <dl class="app-data-list">
            <dt>Nombre</dt><dd><?= $escape($name($review->studentPerson)) ?></dd>
            <dt>Código institucional</dt><dd><?= $escape($review->student->institutionalCode) ?></dd>
            <dt>Fecha de nacimiento</dt><dd><?= $escape($review->studentPerson->birthDate->format('Y-m-d')) ?></dd>
            <dt>Fecha de admisión</dt><dd><?= $escape($review->student->admissionDate->format('Y-m-d')) ?></dd>
            <dt>Estado</dt><dd><?= $escape($review->student->status->value === 'ACTIVE' ? 'Activo' : 'Inactivo') ?></dd>
        </dl>

        <h3 class="h5 mt-4">Familia actual</h3>
        <dl class="app-data-list">
            <dt>Nombre</dt><dd><?= $escape($review->familyDisplayName) ?></dd>
            <dt>Estado</dt><dd><?= $escape($review->familyStatus === 'ACTIVE' ? 'Activa' : 'Inactiva') ?></dd>
        </dl>

        <h3 class="h5 mt-4">Representantes familiares activos</h3>
        <?php if ($review->currentRepresentatives === []): ?>
        <p>No hay representantes activos.</p>
        <?php else: ?>
        <?php foreach ($review->currentRepresentatives as $representative): ?>
        <article>
            <h4 class="h6"><?= $escape($name($representative->person)) ?><?= $representative->isPrimary ? ' — Principal' : '' ?></h4>
            <dl class="app-data-list">
                <dt>Correo personal</dt><dd><?= $escape($supplied($representative->person->email)) ?></dd>
                <dt>Teléfono móvil</dt><dd><?= $escape($supplied($representative->person->mobilePhone)) ?></dd>
                <dt>Ocupación</dt><dd><?= $escape($supplied($representative->representative->occupation)) ?></dd>
                <dt>Teléfono laboral</dt><dd><?= $escape($supplied($representative->representative->workPhone)) ?></dd>
            </dl>
        </article>
        <?php endforeach; ?>
        <?php endif; ?>

        <h3 class="h5 mt-4">Dirección actual del estudiante</h3>
        <?php if ($resources->studentAddress === null): ?>
        <p>No hay una dirección asignada actualmente.</p>
        <?php else: ?>
        <address>
            <strong><?= $escape($resources->studentAddress->label) ?></strong><br>
            <?= $escape($resources->studentAddress->mainStreet) ?>
            <?= $escape($resources->studentAddress->streetNumber ?? '') ?><br>
            <?= $escape($supplied($resources->studentAddress->sector)) ?><br>
            <?= $escape($supplied($resources->studentAddress->reference)) ?>
        </address>
        <?php endif; ?>

        <h3 class="h5 mt-4">Contactos de emergencia actuales</h3>
        <?php if ($resources->emergencyContacts === []): ?>
        <p>No hay contactos de emergencia asignados actualmente.</p>
        <?php else: ?>
        <ul>
            <?php foreach ($resources->emergencyContacts as $contact): ?>
            <li><?= $escape($contact->names) ?> — <?= $escape($contact->mobilePhone) ?></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>

        <h3 class="h5 mt-4">Personas autorizadas para retirar</h3>
        <?php if ($resources->authorizedPickups === []): ?>
        <p>No hay personas autorizadas asignadas actualmente.</p>
        <?php else: ?>
        <ul>
            <?php foreach ($resources->authorizedPickups as $pickup): ?>
            <li><?= $escape($pickup->names) ?> — <?= $escape($pickup->mobilePhone) ?></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </section>

    <section class="app-data-card" aria-labelledby="annual-enrollment-information-heading">
        <h2 class="h3" id="annual-enrollment-information-heading">Información anual de la matrícula</h2>
        <p class="alert alert-secondary">Esta sección pertenece a la matrícula anual y se conserva con su ciclo de vida.</p>

        <h3 class="h5">Ubicación académica</h3>
        <dl class="app-data-list">
            <dt>Grado</dt><dd><?= $escape($review->grade?->name ?? 'Sin asignar') ?></dd>
            <dt>Sección</dt><dd><?= $escape($review->section?->name ?? 'Sin asignar') ?></dd>
        </dl>

        <h3 class="h5 mt-4">Información de facturación</h3>
        <?php if ($billing === null): ?>
        <p>No registrada.</p>
        <?php else: ?>
        <dl class="app-data-list">
            <dt>Número de identificación</dt><dd><?= $escape($billing->identificationNumber) ?></dd>
            <dt>Nombre legal</dt><dd><?= $escape($billing->legalName) ?></dd>
            <dt>Dirección de facturación</dt><dd><?= $escape($billing->billingAddress) ?></dd>
            <dt>Correo de facturación</dt><dd><?= $escape($billing->billingEmail) ?></dd>
            <dt>Teléfono</dt><dd><?= $escape($billing->phone) ?></dd>
        </dl>
        <?php endif; ?>

        <h3 class="h5 mt-4">Información médica</h3>
        <?php if ($medical === null): ?>
        <p>No registrada.</p>
        <?php else: ?>
        <dl class="app-data-list">
            <dt>Condición médica</dt><dd><?= $escape($yesNo($medical->hasMedicalCondition)) ?></dd>
            <dt>Detalle de condición</dt><dd><?= $escape($supplied($medical->medicalConditionDetail)) ?></dd>
            <dt>Alergias</dt><dd><?= $escape($yesNo($medical->hasAllergies)) ?></dd>
            <dt>Detalle de alergias</dt><dd><?= $escape($supplied($medical->allergyDetail)) ?></dd>
            <dt>Medicación permanente</dt><dd><?= $escape($yesNo($medical->takesPermanentMedication)) ?></dd>
            <dt>Nombre de medicación</dt><dd><?= $escape($supplied($medical->medicationName)) ?></dd>
            <dt>Cuidado especial</dt><dd><?= $escape($yesNo($medical->requiresSpecialCare)) ?></dd>
            <dt>Detalle de cuidado</dt><dd><?= $escape($supplied($medical->specialCareDetail)) ?></dd>
            <dt>Seguro médico</dt><dd><?= $escape($yesNo($medical->hasMedicalInsurance)) ?></dd>
            <dt>Aseguradora</dt><dd><?= $escape($supplied($medical->insuranceProvider)) ?></dd>
            <dt>Pediatra</dt><dd><?= $escape($supplied($medical->pediatricianName)) ?></dd>
            <dt>Teléfono del pediatra</dt><dd><?= $escape($supplied($medical->pediatricianPhone)) ?></dd>
            <dt>Observaciones</dt><dd><?= $escape($supplied($medical->observations)) ?></dd>
        </dl>
        <?php endif; ?>

        <h3 class="h5 mt-4">Transporte y salida</h3>
        <dl class="app-data-list">
            <dt>Transporte institucional</dt><dd><?= $escape($transport === null ? 'No registrado' : $yesNo($transport->requiresInstitutionalTransport)) ?></dd>
            <dt>Autorizado para salir solo</dt><dd><?= $escape($yesNo($enrollment->isAuthorizedToLeaveAlone)) ?></dd>
        </dl>
    </section>

    <section class="app-consequential-panel" aria-labelledby="lifecycle-actions-heading">
        <h2 class="h3" id="lifecycle-actions-heading">Acciones del ciclo de vida</h2>
        <p class="text-body-secondary">Cada acción conserva las reglas de transición existentes y se valida nuevamente en el servidor.</p>
        <div class="app-action-group">
        <?php foreach ([
            '/enrollments/reopen' => ['Reabrir matrícula', 'btn-outline-primary'],
            '/enrollments/complete' => ['Completar matrícula', 'btn-success'],
            '/enrollments/cancel' => ['Cancelar matrícula', 'btn-outline-danger'],
        ] as $action => [$label, $buttonClass]): ?>
        <form method="post" action="<?= $escape($action) ?>">
            <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken ?? '') ?>">
            <input type="hidden" name="enrollment_id" value="<?= $escape($enrollment->id) ?>">
            <button class="btn <?= $escape($buttonClass) ?>" type="submit"><?= $escape($label) ?></button>
        </form>
        <?php endforeach; ?>
        </div>
    </section>
