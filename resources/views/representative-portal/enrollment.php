<?php

declare(strict_types=1);

use App\Enrollment\Application\RepresentativePortal\Dto\RepresentativeEnrollmentPortalState;
use App\Enrollment\Application\RepresentativePortal\RepresentativeEnrollmentSectionStatus;
use App\Person\Http\PersonFormOption;
use App\Person\Http\PersonFormOptions;

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$portal = ($state ?? null) instanceof RepresentativeEnrollmentPortalState ? $state : null;
$formOptions = ($options ?? null) instanceof PersonFormOptions
    ? $options
    : new PersonFormOptions([], [], [], [], []);
$safeValues = is_array($values ?? null) ? $values : [];
$sectionErrors = is_array($errors ?? null) ? $errors : [];
$failed = is_string($failedSection ?? null) ? $failedSection : null;
$field = static function (string $section, string $key, mixed $fallback = '') use ($failed, $safeValues): mixed {
    return $failed === $section && array_key_exists($key, $safeValues) ? $safeValues[$key] : $fallback;
};
$optionName = static function (array $items, ?int $id): string {
    foreach ($items as $item) {
        if ($item instanceof PersonFormOption && $item->id === $id) {
            return $item->name;
        }
    }

    return $id === null ? 'No informado' : 'No disponible';
};
$selected = static fn (mixed $left, mixed $right): string => (string) $left === (string) $right ? ' selected' : '';
$checked = static fn (mixed $left, string $right): string => (string) $left === $right ? ' checked' : '';
$status = static fn (RepresentativeEnrollmentSectionStatus $value): string =>
    $value === RepresentativeEnrollmentSectionStatus::Complete ? 'Completa' : 'Pendiente';
$personName = static fn (object $person): string => trim(implode(' ', array_filter([
    $person->firstName,
    $person->middleName,
    $person->firstSurname,
    $person->secondSurname,
], static fn (?string $part): bool => $part !== null && $part !== '')));

if (!$portal instanceof RepresentativeEnrollmentPortalState) {
    throw new RuntimeException('Representative Enrollment state is required.');
}

$context = $portal->context;
$representative = $portal->representativePerson;
$role = $portal->representative;
$studentOption = $portal->selectedStudent;
$student = $studentOption?->person;
$studentRole = $studentOption?->student;
$enrollment = $portal->enrollment;
$period = $context->academicPeriod;
$liveDataEditable = $portal->liveDataMaintenanceEnabled;
$draftEditable = $portal->enrollmentDraftMaintenanceEnabled && $enrollment?->status === 'DRAFT';
$studentLocation = $studentOption === null
    ? '/representative/enrollment'
    : '/representative/enrollment?student_id=' . $studentOption->student->id;
$hiddenContext = static function () use ($escape, $csrfToken, $context, $period): void {
    ?>
    <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken ?? '') ?>">
    <input type="hidden" name="expected_family_id" value="<?= $escape($context->familyId) ?>">
    <input type="hidden" name="expected_academic_period_id" value="<?= $escape($period?->id ?? '') ?>">
    <?php
};
$studentHidden = static function () use ($hiddenContext, $escape, $studentOption): void {
    $hiddenContext();
    ?>
    <input type="hidden" name="student_id" value="<?= $escape($studentOption?->student->id ?? '') ?>">
    <?php
};
$autosaveFeedback = static function (string $section) use ($escape): void {
    ?>
    <div class="col-12" data-enrollment-autosave-feedback="<?= $escape($section) ?>">
        <span class="app-autosave-status" data-enrollment-autosave-status role="status" aria-live="polite"></span>
        <div data-enrollment-autosave-errors class="alert alert-danger mt-2" role="alert" tabindex="-1" hidden></div>
    </div>
    <?php
};
?>
<script src="/js/representative-enrollment.js" defer></script>
<?php
$breadcrumbItems = [
    ['label' => 'Portal', 'url' => '/representative'],
    ['label' => 'Matrícula'],
];
require dirname(__DIR__) . '/components/breadcrumb.php';
?>
<header class="app-page-header">
    <h1>Matrícula de estudiantes</h1>
    <p class="text-body-secondary">Actualiza datos actuales del SIS y la información anual de matrícula según el contexto autorizado.</p>
    <nav class="app-section-nav" aria-label="Secciones de matrícula">
        <a href="/representative" data-enrollment-navigation>Portal de representantes</a>
        <a href="/representative" data-enrollment-navigation>Cambiar familia</a>
        <a href="#datos-actuales-sis">Datos actuales del SIS</a>
        <a href="#estudiantes">Estudiantes</a>
        <a href="#informacion-anual-matricula">Información anual</a>
        <?php if ($context->acknowledgementsSatisfied): ?>
        <a href="/representative/resources" data-enrollment-navigation>Recursos familiares</a>
        <?php endif; ?>
    </nav>
</header>

<?php if ($sectionErrors !== []): ?>
<div class="alert alert-danger" role="alert" aria-labelledby="enrollment-errors-heading">
    <h2 id="enrollment-errors-heading" class="h5">Revisa esta sección</h2>
    <ul>
        <?php foreach ($sectionErrors as $message): ?>
        <li><?= $escape($message) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<section class="app-context-banner" aria-labelledby="academic-period-heading">
    <div>
    <h2 class="h4" id="academic-period-heading">Contexto actual</h2>
    <p class="mb-1"><strong>Familia:</strong> <?= $escape($context->familyDisplayName) ?></p>
    <?php if ($period === null): ?>
    <p class="mb-0" role="status">No existe un período académico activo. El mantenimiento de matrícula no está disponible.</p>
    <?php else: ?>
    <p class="mb-1"><strong>Período:</strong> <?= $escape($period->name) ?> (<?= $escape($period->code) ?>)</p>
    <p class="mb-0"><?= $escape($period->startsOn) ?> a <?= $escape($period->endsOn) ?></p>
    <?php endif; ?>
    </div>
</section>

<section class="app-data-card" aria-labelledby="acknowledgements-heading">
    <h2 class="h4" id="acknowledgements-heading">Aceptaciones institucionales</h2>
    <?php if ($period !== null && !$context->acknowledgementsSatisfied): ?>
    <p role="alert">Debes completar las aceptaciones institucionales antes de mantener la información de matrícula.</p>
    <p><a class="btn btn-primary" href="/representative/acknowledgements" data-enrollment-navigation>Revisar aceptaciones</a></p>
    <?php elseif ($period !== null): ?>
    <p class="mb-0">Completadas para el período académico actual.</p>
    <?php else: ?>
    <p class="mb-0">No disponibles hasta que exista un período académico activo.</p>
    <?php endif; ?>
</section>

<section class="app-form-section" id="estudiantes" aria-labelledby="student-navigation-heading">
    <h2 class="h4" id="student-navigation-heading">Estudiantes</h2>
    <?php if ($context->students === []): ?>
    <p>No hay estudiantes activos disponibles en la familia actual.</p>
    <?php else: ?>
    <form method="get" action="/representative/enrollment" class="row g-2 align-items-end" data-enrollment-navigation>
        <div class="col-12 col-md-8">
            <label for="student_id" class="form-label">Seleccionar estudiante</label>
            <select id="student_id" name="student_id" class="form-select" required>
                <option value="">Elige un estudiante</option>
                <?php foreach ($context->students as $option): ?>
                <option value="<?= $escape($option->student->id) ?>"<?= $selected($option->student->id, $studentOption?->student->id) ?>>
                    <?= $escape($option->displayName) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12 col-md-auto">
            <button type="submit" class="btn btn-primary">Abrir estudiante</button>
        </div>
    </form>
    <?php endif; ?>
</section>

<?php if ($studentOption !== null): ?>
<section class="app-data-card" aria-labelledby="enrollment-state-heading">
    <h2 class="h4" id="enrollment-state-heading">Estado de matrícula</h2>
    <p>Estudiante seleccionado: <strong><?= $escape($studentOption->displayName) ?></strong></p>
    <?php if ($enrollment === null): ?>
    <p>La matrícula en borrador todavía no ha sido iniciada.</p>
    <?php if ($portal->enrollmentDraftMaintenanceEnabled): ?>
    <form method="post" action="/representative/enrollment/open">
        <?php $studentHidden(); ?>
        <button type="submit" class="btn btn-primary">Iniciar matrícula en borrador</button>
    </form>
    <?php endif; ?>
    <?php else: ?>
    <p><strong>Estado:</strong> <?php $statusCode = $enrollment->status; require dirname(__DIR__) . '/components/status-badge.php'; ?></p>
    <p><a class="btn btn-outline-primary" href="/representative/enrollment/review?student_id=<?= $escape($studentOption->student->id) ?>" data-enrollment-navigation>Revisar y enviar matrícula</a></p>
    <?php if (!$portal->enrollmentDraftMaintenanceEnabled): ?>
    <p class="alert alert-info mb-0" role="status">La información anual de esta matrícula está en modo de solo lectura. Los datos vivos autorizados continúan editables.</p>
    <?php else: ?>
    <p class="mb-0">Puedes completar este borrador sección por sección.</p>
    <?php endif; ?>
    <?php endif; ?>
</section>

<section class="app-data-card" aria-labelledby="progress-heading">
    <h2 class="h4" id="progress-heading">Progreso por secciones</h2>
    <p>Estos indicadores orientan el llenado; no significan que la matrícula haya sido enviada.</p>
    <ul class="app-progress-grid">
        <li>Aceptaciones institucionales: <strong data-progress-section="acknowledgements"><?= $escape($status($portal->progress->acknowledgements)) ?></strong></li>
        <li>Datos personales del representante: <strong data-progress-section="representative-personal"><?= $escape($status($portal->progress->representativePersonal)) ?></strong></li>
        <li>Contacto del representante: <strong data-progress-section="representative-contact"><?= $escape($status($portal->progress->representativeContact)) ?></strong></li>
        <li>Empleo: <strong data-progress-section="representative-employment"><?= $escape($status($portal->progress->employment)) ?></strong></li>
        <li>Datos personales del estudiante: <strong data-progress-section="student-personal"><?= $escape($status($portal->progress->studentPersonal)) ?></strong></li>
        <li>Dirección del estudiante: <strong data-progress-section="student-address"><?= $escape($status($portal->progress->studentAddress)) ?></strong></li>
        <li>Ubicación académica: <strong data-progress-section="academic-placement"><?= $escape($status($portal->progress->academicPlacement)) ?></strong></li>
        <li>Facturación: <strong data-progress-section="billing"><?= $escape($status($portal->progress->billing)) ?></strong></li>
        <li>Información médica: <strong data-progress-section="medical"><?= $escape($status($portal->progress->medical)) ?></strong></li>
        <li>Transporte: <strong data-progress-section="transport"><?= $escape($status($portal->progress->transport)) ?></strong></li>
        <li>Contactos de emergencia: <strong data-progress-section="emergency-contacts"><?= $escape($status($portal->progress->emergencyContacts)) ?></strong></li>
        <li>Retiro o salida autónoma: <strong data-progress-section="leave-alone"><?= $escape($status($portal->progress->pickupOrLeaveAlone)) ?></strong></li>
    </ul>
    <?php if ($context->acknowledgementsSatisfied): ?>
    <p><a href="/representative/resources" data-enrollment-navigation>Mantener direcciones, contactos y retiros autorizados en Recursos familiares</a>.</p>
    <?php endif; ?>
</section>
<?php endif; ?>

<section class="app-context-banner" id="datos-actuales-sis" aria-labelledby="live-data-heading">
    <div>
        <h2 class="h4" id="live-data-heading">Datos actuales del SIS</h2>
        <p class="mb-0">Estos datos pertenecen al representante y al estudiante, no al historial anual de la matrícula.</p>
    </div>
</section>

<section class="app-data-card" aria-labelledby="representative-personal-heading">
    <h2 class="h4" id="representative-personal-heading">Información personal del representante</h2>
    <dl class="app-data-list mb-4">
        <dt>Nombre</dt><dd><?= $escape($personName($representative)) ?></dd>
        <dt>Tipo de documento</dt><dd><?= $escape($optionName($formOptions->documentTypes, $representative->documentTypeId)) ?></dd>
        <dt>Número de documento</dt><dd><?= $escape($representative->documentNumber ?? 'No informado') ?></dd>
        <dt>Sexo</dt><dd><?= $escape($optionName($formOptions->sexes, $representative->sexId)) ?></dd>
    </dl>
    <?php if ($liveDataEditable): ?>
    <form method="post" action="/representative/enrollment/representative/personal" class="row g-3" data-enrollment-autosave data-section="representative-personal">
        <?php $hiddenContext(); ?>
        <?php if ($studentOption !== null): ?><input type="hidden" name="student_id" value="<?= $escape($studentOption->student->id) ?>"><?php endif; ?>
        <div class="col-12 col-md-6"><label class="form-label">Primer nombre <input class="form-control" name="first_name" value="<?= $escape($field('representative-personal', 'first_name', $representative->firstName)) ?>" required></label></div>
        <div class="col-12 col-md-6"><label class="form-label">Segundo nombre <input class="form-control" name="middle_name" value="<?= $escape($field('representative-personal', 'middle_name', $representative->middleName)) ?>"></label></div>
        <div class="col-12 col-md-6"><label class="form-label">Primer apellido <input class="form-control" name="first_surname" value="<?= $escape($field('representative-personal', 'first_surname', $representative->firstSurname)) ?>" required></label></div>
        <div class="col-12 col-md-6"><label class="form-label">Segundo apellido <input class="form-control" name="second_surname" value="<?= $escape($field('representative-personal', 'second_surname', $representative->secondSurname)) ?>"></label></div>
        <div class="col-12 col-md-4"><label class="form-label">Fecha de nacimiento <input class="form-control" type="date" name="birth_date" value="<?= $escape($field('representative-personal', 'birth_date', $representative->birthDate->format('Y-m-d'))) ?>" required></label></div>
        <div class="col-12 col-md-4"><label class="form-label">Estado civil <select class="form-select" name="marital_status_id"><option value="">No informado</option><?php foreach ($formOptions->maritalStatuses as $option): ?><option value="<?= $escape($option->id) ?>"<?= $selected($field('representative-personal', 'marital_status_id', $representative->maritalStatusId), $option->id) ?>><?= $escape($option->name) ?></option><?php endforeach; ?></select></label></div>
        <div class="col-12 col-md-4"><label class="form-label">Nivel educativo <select class="form-select" name="education_level_id"><option value="">No informado</option><?php foreach ($formOptions->educationLevels as $option): ?><option value="<?= $escape($option->id) ?>"<?= $selected($field('representative-personal', 'education_level_id', $representative->educationLevelId), $option->id) ?>><?= $escape($option->name) ?></option><?php endforeach; ?></select></label></div>
        <?php $autosaveFeedback('representative-personal'); ?>
        <div class="col-12"><button type="submit" class="btn btn-primary" data-enrollment-fallback-save>Guardar información personal</button></div>
    </form>
    <?php endif; ?>
</section>

<section class="app-data-card" aria-labelledby="representative-contact-heading">
    <h2 class="h4" id="representative-contact-heading">Información de contacto del representante</h2>
    <dl class="app-data-list mb-4"><dt>Correo electrónico</dt><dd><?= $escape($representative->email ?? 'No informado') ?></dd><dt>Teléfono móvil</dt><dd><?= $escape($representative->mobilePhone ?? 'No informado') ?></dd><dt>Teléfono convencional</dt><dd><?= $escape($representative->landlinePhone ?? 'No informado') ?></dd></dl>
    <?php if ($liveDataEditable): ?>
    <form method="post" action="/representative/enrollment/representative/contact" class="row g-3" data-enrollment-autosave data-section="representative-contact">
        <?php $hiddenContext(); ?><?php if ($studentOption !== null): ?><input type="hidden" name="student_id" value="<?= $escape($studentOption->student->id) ?>"><?php endif; ?>
        <div class="col-12"><label class="form-label">Correo electrónico <input class="form-control" type="email" name="email" value="<?= $escape($field('representative-contact', 'email', $representative->email)) ?>" required></label></div>
        <div class="col-12 col-md-6"><label class="form-label">Teléfono móvil <input class="form-control" name="mobile_phone" value="<?= $escape($field('representative-contact', 'mobile_phone', $representative->mobilePhone)) ?>"></label></div>
        <div class="col-12 col-md-6"><label class="form-label">Teléfono convencional <input class="form-control" name="landline_phone" value="<?= $escape($field('representative-contact', 'landline_phone', $representative->landlinePhone)) ?>"></label></div>
        <?php $autosaveFeedback('representative-contact'); ?>
        <div class="col-12"><button type="submit" class="btn btn-primary" data-enrollment-fallback-save>Guardar información de contacto</button></div>
    </form>
    <?php endif; ?>
</section>

<section class="app-data-card" aria-labelledby="employment-heading">
    <h2 class="h4" id="employment-heading">Información laboral</h2>
    <p>Esta sección es opcional.</p>
    <dl class="app-data-list mb-4"><dt>Ocupación</dt><dd><?= $escape($role->occupation ?? 'No informada') ?></dd><dt>Empresa</dt><dd><?= $escape($role->companyName ?? 'No informada') ?></dd><dt>Cargo</dt><dd><?= $escape($role->position ?? 'No informado') ?></dd><dt>Teléfono laboral</dt><dd><?= $escape($role->workPhone ?? 'No informado') ?></dd><dt>Correo laboral</dt><dd><?= $escape($role->workEmail ?? 'No informado') ?></dd></dl>
    <?php if ($liveDataEditable): ?>
    <form method="post" action="/representative/enrollment/representative/employment" class="row g-3" data-enrollment-autosave data-section="representative-employment">
        <?php $hiddenContext(); ?><?php if ($studentOption !== null): ?><input type="hidden" name="student_id" value="<?= $escape($studentOption->student->id) ?>"><?php endif; ?>
        <div class="col-12 col-md-6"><label class="form-label">Ocupación <input class="form-control" name="occupation" value="<?= $escape($field('representative-employment', 'occupation', $role->occupation)) ?>"></label></div>
        <div class="col-12 col-md-6"><label class="form-label">Empresa <input class="form-control" name="company_name" value="<?= $escape($field('representative-employment', 'company_name', $role->companyName)) ?>"></label></div>
        <div class="col-12 col-md-6"><label class="form-label">Cargo <input class="form-control" name="position" value="<?= $escape($field('representative-employment', 'position', $role->position)) ?>"></label></div>
        <div class="col-12 col-md-6"><label class="form-label">Teléfono laboral <input class="form-control" name="work_phone" value="<?= $escape($field('representative-employment', 'work_phone', $role->workPhone)) ?>"></label></div>
        <div class="col-12"><label class="form-label">Correo laboral <input class="form-control" type="email" name="work_email" value="<?= $escape($field('representative-employment', 'work_email', $role->workEmail)) ?>"></label></div>
        <?php $autosaveFeedback('representative-employment'); ?>
        <div class="col-12"><button type="submit" class="btn btn-primary" data-enrollment-fallback-save>Guardar información laboral</button></div>
    </form>
    <?php endif; ?>
</section>

<?php if ($studentOption !== null && $student !== null && $studentRole !== null): ?>
<section class="app-data-card" aria-labelledby="student-personal-heading">
    <h2 class="h4" id="student-personal-heading">Información personal del estudiante</h2>
    <dl class="app-data-list mb-4">
        <dt>Nombre</dt><dd><?= $escape($personName($student)) ?></dd>
        <dt>Tipo de documento</dt><dd><?= $escape($optionName($formOptions->documentTypes, $student->documentTypeId)) ?></dd>
        <dt>Número de documento</dt><dd><?= $escape($student->documentNumber ?? 'No informado') ?></dd>
        <dt>Sexo</dt><dd><?= $escape($optionName($formOptions->sexes, $student->sexId)) ?></dd>
        <dt>Código institucional</dt><dd><?= $escape($studentRole->institutionalCode) ?></dd>
        <dt>Fecha de admisión</dt><dd><?= $escape($studentRole->admissionDate->format('Y-m-d')) ?></dd>
        <dt>Estado del estudiante</dt><dd><?php $statusCode = $studentRole->status->value; require dirname(__DIR__) . '/components/status-badge.php'; ?></dd>
    </dl>
    <?php if ($liveDataEditable): ?>
    <form method="post" action="/representative/enrollment/student/personal" class="row g-3" data-enrollment-autosave data-section="student-personal">
        <?php $studentHidden(); ?>
        <div class="col-12 col-md-6"><label class="form-label">Primer nombre <input class="form-control" name="first_name" value="<?= $escape($field('student-personal', 'first_name', $student->firstName)) ?>" required></label></div>
        <div class="col-12 col-md-6"><label class="form-label">Segundo nombre <input class="form-control" name="middle_name" value="<?= $escape($field('student-personal', 'middle_name', $student->middleName)) ?>"></label></div>
        <div class="col-12 col-md-6"><label class="form-label">Primer apellido <input class="form-control" name="first_surname" value="<?= $escape($field('student-personal', 'first_surname', $student->firstSurname)) ?>" required></label></div>
        <div class="col-12 col-md-6"><label class="form-label">Segundo apellido <input class="form-control" name="second_surname" value="<?= $escape($field('student-personal', 'second_surname', $student->secondSurname)) ?>"></label></div>
        <div class="col-12 col-md-4"><label class="form-label">Fecha de nacimiento <input class="form-control" type="date" name="birth_date" value="<?= $escape($field('student-personal', 'birth_date', $student->birthDate->format('Y-m-d'))) ?>" required></label></div>
        <div class="col-12 col-md-4"><label class="form-label">Estado civil <select class="form-select" name="marital_status_id"><option value="">No informado</option><?php foreach ($formOptions->maritalStatuses as $option): ?><option value="<?= $escape($option->id) ?>"<?= $selected($field('student-personal', 'marital_status_id', $student->maritalStatusId), $option->id) ?>><?= $escape($option->name) ?></option><?php endforeach; ?></select></label></div>
        <div class="col-12 col-md-4"><label class="form-label">Nivel educativo <select class="form-select" name="education_level_id"><option value="">No informado</option><?php foreach ($formOptions->educationLevels as $option): ?><option value="<?= $escape($option->id) ?>"<?= $selected($field('student-personal', 'education_level_id', $student->educationLevelId), $option->id) ?>><?= $escape($option->name) ?></option><?php endforeach; ?></select></label></div>
        <?php $autosaveFeedback('student-personal'); ?>
        <div class="col-12"><button type="submit" class="btn btn-primary" data-enrollment-fallback-save>Guardar información del estudiante</button></div>
    </form>
    <?php endif; ?>
</section>

<section class="app-context-banner" id="informacion-anual-matricula" aria-labelledby="annual-data-heading">
    <div>
        <h2 class="h4" id="annual-data-heading">Información anual de matrícula</h2>
        <p class="mb-0">Estos datos pertenecen exclusivamente a la matrícula del período académico seleccionado.</p>
    </div>
</section>

<section class="app-data-card app-readonly-panel" aria-labelledby="placement-heading">
    <h2 class="h4" id="placement-heading">Ubicación académica</h2>
    <p class="text-body-secondary">Información de solo lectura asignada por la institución.</p>
    <?php if ($academicPlacement === null): ?>
    <p>Ubicación académica pendiente.</p>
    <?php else: ?>
    <dl class="app-data-list"><dt>Grado</dt><dd><?= $escape($academicPlacement['grade']->name) ?></dd><dt>Sección</dt><dd><?= $escape($academicPlacement['section']?->name ?? 'No asignada') ?></dd></dl>
    <?php endif; ?>
</section>

<?php if ($draftEditable): ?>
<?php $billing = $enrollment->billingInformation; ?>
<section class="app-form-section" aria-labelledby="billing-heading">
    <h2 class="h4" id="billing-heading">Información de facturación</h2>
    <form method="post" action="/representative/enrollment/student/billing" class="row g-3" data-enrollment-autosave data-section="billing">
        <?php $studentHidden(); ?>
        <div class="col-12"><label class="form-label">Tipo de identificación <select class="form-select" name="identification_type_id" required><option value="">Seleccionar</option><?php foreach ($formOptions->documentTypes as $option): ?><option value="<?= $escape($option->id) ?>"<?= $selected($field('billing', 'identification_type_id', $billing?->identificationTypeId), $option->id) ?>><?= $escape($option->name) ?></option><?php endforeach; ?></select></label></div>
        <div class="col-12 col-md-6"><label class="form-label">Número de identificación <input class="form-control" name="identification_number" value="<?= $escape($field('billing', 'identification_number', $billing?->identificationNumber)) ?>" required></label></div>
        <div class="col-12 col-md-6"><label class="form-label">Nombre o razón social <input class="form-control" name="legal_name" value="<?= $escape($field('billing', 'legal_name', $billing?->legalName)) ?>" required></label></div>
        <div class="col-12"><label class="form-label">Dirección de facturación <input class="form-control" name="billing_address" value="<?= $escape($field('billing', 'billing_address', $billing?->billingAddress)) ?>" required></label></div>
        <div class="col-12 col-md-6"><label class="form-label">Correo de facturación <input class="form-control" type="email" name="billing_email" value="<?= $escape($field('billing', 'billing_email', $billing?->billingEmail)) ?>" required></label></div>
        <div class="col-12 col-md-6"><label class="form-label">Teléfono <input class="form-control" name="phone" value="<?= $escape($field('billing', 'phone', $billing?->phone)) ?>" required></label></div>
        <?php $autosaveFeedback('billing'); ?>
        <div class="col-12"><button type="submit" class="btn btn-primary" data-enrollment-fallback-save>Guardar facturación</button></div>
    </form>
</section>

<?php $medical = $enrollment->medicalInformation; ?>
<section class="app-form-section app-sensitive-data" aria-labelledby="medical-heading">
    <h2 class="h4" id="medical-heading">Información médica</h2>
    <p>Completa el detalle relacionado únicamente cuando la respuesta sea Sí.</p>
    <form method="post" action="/representative/enrollment/student/medical" class="row g-3" data-enrollment-autosave data-section="medical">
        <?php $studentHidden(); ?>
        <?php
        $medicalFields = [
            ['has_medical_condition', 'Condición médica', 'medical_condition_detail', 'Detalle de la condición médica', $medical?->hasMedicalCondition, $medical?->medicalConditionDetail],
            ['has_allergies', 'Alergias', 'allergy_detail', 'Detalle de alergias', $medical?->hasAllergies, $medical?->allergyDetail],
            ['takes_permanent_medication', 'Medicación permanente', 'medication_name', 'Nombre del medicamento', $medical?->takesPermanentMedication, $medical?->medicationName],
            ['requires_special_care', 'Cuidados especiales', 'special_care_detail', 'Detalle de cuidados especiales', $medical?->requiresSpecialCare, $medical?->specialCareDetail],
            ['has_medical_insurance', 'Seguro médico', 'insurance_provider', 'Proveedor del seguro', $medical?->hasMedicalInsurance, $medical?->insuranceProvider],
        ];
        foreach ($medicalFields as [$booleanName, $legend, $detailName, $detailLabel, $storedBoolean, $storedDetail]):
            $currentBoolean = $field('medical', $booleanName, $storedBoolean === null ? '' : ($storedBoolean ? '1' : '0'));
        ?>
        <fieldset class="col-12"><legend class="h6"><?= $escape($legend) ?></legend><label><input type="radio" name="<?= $escape($booleanName) ?>" value="1" data-medical-controller<?= $checked($currentBoolean, '1') ?> required> Sí</label> <label><input type="radio" name="<?= $escape($booleanName) ?>" value="0" data-medical-controller<?= $checked($currentBoolean, '0') ?> required> No</label></fieldset>
        <div class="col-12" data-medical-dependent-for="<?= $escape($booleanName) ?>"><label class="form-label"><?= $escape($detailLabel) ?> <textarea class="form-control" name="<?= $escape($detailName) ?>" data-medical-detail-for="<?= $escape($booleanName) ?>"><?= $escape($field('medical', $detailName, $storedDetail)) ?></textarea></label></div>
        <?php endforeach; ?>
        <div class="col-12 col-md-6"><label class="form-label">Nombre del pediatra <input class="form-control" name="pediatrician_name" value="<?= $escape($field('medical', 'pediatrician_name', $medical?->pediatricianName)) ?>"></label></div>
        <div class="col-12 col-md-6"><label class="form-label">Teléfono del pediatra <input class="form-control" name="pediatrician_phone" value="<?= $escape($field('medical', 'pediatrician_phone', $medical?->pediatricianPhone)) ?>"></label></div>
        <div class="col-12"><label class="form-label">Observaciones <textarea class="form-control" name="observations"><?= $escape($field('medical', 'observations', $medical?->observations)) ?></textarea></label></div>
        <?php $autosaveFeedback('medical'); ?>
        <div class="col-12"><button type="submit" class="btn btn-primary" data-enrollment-fallback-save>Guardar información médica</button></div>
    </form>
</section>

<section class="app-form-section" aria-labelledby="transport-heading">
    <h2 class="h4" id="transport-heading">Información de transporte</h2>
    <?php $transportValue = $field('transport', 'requires_institutional_transport', $enrollment->transportInformation === null ? '' : ($enrollment->transportInformation->requiresInstitutionalTransport ? '1' : '0')); ?>
    <form method="post" action="/representative/enrollment/student/transport" data-enrollment-autosave data-section="transport">
        <?php $studentHidden(); ?>
        <fieldset><legend class="h6">¿Requiere transporte institucional?</legend><label><input type="radio" name="requires_institutional_transport" value="1"<?= $checked($transportValue, '1') ?> required> Sí</label> <label><input type="radio" name="requires_institutional_transport" value="0"<?= $checked($transportValue, '0') ?> required> No</label></fieldset>
        <?php $autosaveFeedback('transport'); ?>
        <button type="submit" class="btn btn-primary" data-enrollment-fallback-save>Guardar transporte</button>
    </form>
</section>

<section class="app-form-section" aria-labelledby="leave-alone-heading">
    <h2 class="h4" id="leave-alone-heading">Autorización de salida autónoma</h2>
    <?php $leaveValue = $field('leave-alone', 'is_authorized_to_leave_alone', $enrollment->isAuthorizedToLeaveAlone ? '1' : '0'); ?>
    <form method="post" action="/representative/enrollment/student/leave-alone" data-enrollment-autosave data-section="leave-alone">
        <?php $studentHidden(); ?>
        <fieldset><legend class="h6">¿El estudiante puede salir solo?</legend><label><input type="radio" name="is_authorized_to_leave_alone" value="1"<?= $checked($leaveValue, '1') ?> required> Sí</label> <label><input type="radio" name="is_authorized_to_leave_alone" value="0"<?= $checked($leaveValue, '0') ?> required> No</label></fieldset>
        <?php $autosaveFeedback('leave-alone'); ?>
        <button type="submit" class="btn btn-primary" data-enrollment-fallback-save>Guardar autorización de salida</button>
    </form>
</section>
<?php elseif ($enrollment !== null): ?>
<section class="app-data-card app-readonly-panel" aria-labelledby="annual-readonly-heading">
    <h2 class="h4" id="annual-readonly-heading">Información anual de solo lectura</h2>
    <?php if ($enrollment->billingInformation === null): ?>
    <p>Información de facturación: no informada.</p>
    <?php else: ?>
    <h3 class="h5">Información de facturación</h3>
    <dl class="app-data-list">
        <dt>Tipo de identificación</dt><dd><?= $escape($optionName($formOptions->documentTypes, $enrollment->billingInformation->identificationTypeId)) ?></dd>
        <dt>Número de identificación</dt><dd><?= $escape($enrollment->billingInformation->identificationNumber) ?></dd>
        <dt>Nombre o razón social</dt><dd><?= $escape($enrollment->billingInformation->legalName) ?></dd>
        <dt>Dirección de facturación</dt><dd><?= $escape($enrollment->billingInformation->billingAddress) ?></dd>
        <dt>Correo de facturación</dt><dd><?= $escape($enrollment->billingInformation->billingEmail) ?></dd>
        <dt>Teléfono</dt><dd><?= $escape($enrollment->billingInformation->phone) ?></dd>
    </dl>
    <?php endif; ?>
    <?php if ($enrollment->medicalInformation === null): ?>
    <p>Información médica: no informada.</p>
    <?php else: ?>
    <div class="app-sensitive-data mt-4 p-3">
    <h3 class="h5">Información médica</h3>
    <dl class="app-data-list">
        <dt>Condición médica</dt><dd><?= $enrollment->medicalInformation->hasMedicalCondition ? 'Sí' : 'No' ?></dd>
        <dt>Detalle de condición</dt><dd><?= $escape($enrollment->medicalInformation->medicalConditionDetail ?? 'No informado') ?></dd>
        <dt>Alergias</dt><dd><?= $enrollment->medicalInformation->hasAllergies ? 'Sí' : 'No' ?></dd>
        <dt>Detalle de alergias</dt><dd><?= $escape($enrollment->medicalInformation->allergyDetail ?? 'No informado') ?></dd>
        <dt>Medicación permanente</dt><dd><?= $enrollment->medicalInformation->takesPermanentMedication ? 'Sí' : 'No' ?></dd>
        <dt>Nombre del medicamento</dt><dd><?= $escape($enrollment->medicalInformation->medicationName ?? 'No informado') ?></dd>
        <dt>Cuidados especiales</dt><dd><?= $enrollment->medicalInformation->requiresSpecialCare ? 'Sí' : 'No' ?></dd>
        <dt>Detalle de cuidados</dt><dd><?= $escape($enrollment->medicalInformation->specialCareDetail ?? 'No informado') ?></dd>
        <dt>Seguro médico</dt><dd><?= $enrollment->medicalInformation->hasMedicalInsurance ? 'Sí' : 'No' ?></dd>
        <dt>Proveedor del seguro</dt><dd><?= $escape($enrollment->medicalInformation->insuranceProvider ?? 'No informado') ?></dd>
        <dt>Pediatra</dt><dd><?= $escape($enrollment->medicalInformation->pediatricianName ?? 'No informado') ?></dd>
        <dt>Teléfono del pediatra</dt><dd><?= $escape($enrollment->medicalInformation->pediatricianPhone ?? 'No informado') ?></dd>
        <dt>Observaciones</dt><dd><?= $escape($enrollment->medicalInformation->observations ?? 'No informadas') ?></dd>
    </dl>
    </div>
    <?php endif; ?>
    <p class="mt-4">Requiere transporte institucional: <?= $enrollment->transportInformation === null ? 'No informado' : ($enrollment->transportInformation->requiresInstitutionalTransport ? 'Sí' : 'No') ?></p>
    <p>Autorización de salida autónoma: <?= $enrollment->isAuthorizedToLeaveAlone ? 'Sí' : 'No' ?></p>
</section>
<?php endif; ?>
<?php endif; ?>

<div class="app-action-group mt-4">
    <a class="btn btn-outline-secondary" href="<?= $escape($studentLocation) ?>" data-enrollment-navigation>Actualizar información</a>
    <a class="btn btn-link" href="/representative" data-enrollment-navigation>Volver al portal</a>
</div>
