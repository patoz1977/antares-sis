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
$currentPage = is_string($page ?? null) ? $page : 'me';
$pageTitles = [
    'me' => 'Mis datos',
    'student' => 'Datos del estudiante',
    'placement' => 'Ubicación académica',
    'billing' => 'Facturación',
    'medical' => 'Información médica',
    'transport' => 'Transporte',
    'leave-alone' => 'Salida autónoma',
];
$annualPages = ['placement', 'billing', 'medical', 'transport', 'leave-alone'];
$isAnnualPage = in_array($currentPage, $annualPages, true);
$studentSuffix = $studentOption === null ? '' : '?student_id=' . $studentOption->student->id;
$summaryLocation = '/representative/enrollment' . $studentSuffix;
$currentReturn = $studentOption === null ? '/representative/data' : $summaryLocation;
$annualOrder = [
    'placement' => '/representative/enrollment/student/placement',
    'billing' => '/representative/enrollment/student/billing',
    'medical' => '/representative/enrollment/student/medical',
    'transport' => '/representative/enrollment/student/transport',
    'leave-alone' => '/representative/enrollment/student/leave-alone',
];
$annualKeys = array_keys($annualOrder);
$annualIndex = array_search($currentPage, $annualKeys, true);
$annualStatus = match ($currentPage) {
    'placement' => $portal->progress->academicPlacement,
    'billing' => $portal->progress->billing,
    'medical' => $portal->progress->medical,
    'transport' => $portal->progress->transport,
    'leave-alone' => $portal->progress->pickupOrLeaveAlone,
    default => null,
};
$liveDataEditable = $portal->liveDataMaintenanceEnabled;
$draftEditable = $portal->enrollmentDraftMaintenanceEnabled && $enrollment?->status === 'DRAFT';
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
<?php
$breadcrumbItems = [
    ['label' => 'Inicio', 'url' => '/representative'],
    ['label' => $isAnnualPage ? 'Matrícula' : 'Actualización de datos', 'url' => $isAnnualPage ? '/representative/enrollment' : '/representative/data'],
];
if ($studentOption !== null) {
    $breadcrumbItems[] = ['label' => $studentOption->displayName, 'url' => $summaryLocation];
}
$breadcrumbItems[] = ['label' => $pageTitles[$currentPage] ?? 'Datos'];
require dirname(__DIR__) . '/components/breadcrumb.php';
?>
<header class="app-page-header">
    <h1><?= $escape($pageTitles[$currentPage] ?? 'Datos') ?></h1>
    <?php if ($studentOption !== null): ?><p>Estudiante: <strong><?= $escape($studentOption->displayName) ?></strong></p><?php endif; ?>
    <?php if ($period !== null): ?><p>Período académico: <?= $escape($period->name) ?></p><?php endif; ?>
    <?php if ($annualStatus instanceof RepresentativeEnrollmentSectionStatus): ?>
    <p>Estado de la sección: <strong data-progress-section="<?= $escape($currentPage) ?>"><?= $annualStatus === RepresentativeEnrollmentSectionStatus::Complete ? 'Completa' : 'Pendiente' ?></strong></p>
    <?php endif; ?>
    <p class="text-body-secondary">Familia actual: <?= $escape($context->familyDisplayName) ?></p>
    <p class="text-body-secondary">Los campos marcados con * son obligatorios.</p>
</header>
<?php if ($isAnnualPage): ?>
<nav class="app-section-nav" aria-label="Secciones de matrícula">
    <?php if ($annualIndex !== false && $annualIndex > 0): ?>
    <a href="<?= $escape($annualOrder[$annualKeys[$annualIndex - 1]] . $studentSuffix) ?>" data-enrollment-navigation>Anterior</a>
    <?php endif; ?>
    <?php if ($annualIndex !== false && $annualIndex < count($annualKeys) - 1): ?>
    <a href="<?= $escape($annualOrder[$annualKeys[$annualIndex + 1]] . $studentSuffix) ?>" data-enrollment-navigation>Siguiente</a>
    <?php else: ?>
    <a href="<?= $escape('/representative/enrollment/review' . $studentSuffix) ?>" data-enrollment-navigation>Revisar y enviar</a>
    <?php endif; ?>
    <a href="<?= $escape($summaryLocation) ?>" data-enrollment-navigation>Volver al resumen</a>
</nav>
<?php endif; ?>
<script src="/js/representative-enrollment.js" defer></script>
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
<?php if ($isAnnualPage && $enrollment === null): ?>
<p class="alert alert-info" role="status">Inicia la matrícula desde el resumen del estudiante antes de completar esta sección anual.</p>
<?php endif; ?>
<?php if ($currentPage === 'me'): ?>
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
        <div class="col-12 col-md-6"><label class="form-label app-required-label">Primer nombre <input class="form-control" name="first_name" value="<?= $escape($field('representative-personal', 'first_name', $representative->firstName)) ?>" required></label></div>
        <div class="col-12 col-md-6"><label class="form-label">Segundo nombre <input class="form-control" name="middle_name" value="<?= $escape($field('representative-personal', 'middle_name', $representative->middleName)) ?>"></label></div>
        <div class="col-12 col-md-6"><label class="form-label app-required-label">Primer apellido <input class="form-control" name="first_surname" value="<?= $escape($field('representative-personal', 'first_surname', $representative->firstSurname)) ?>" required></label></div>
        <div class="col-12 col-md-6"><label class="form-label">Segundo apellido <input class="form-control" name="second_surname" value="<?= $escape($field('representative-personal', 'second_surname', $representative->secondSurname)) ?>"></label></div>
        <div class="col-12 col-md-4"><label class="form-label app-required-label">Fecha de nacimiento <input class="form-control" type="date" name="birth_date" value="<?= $escape($field('representative-personal', 'birth_date', $representative->birthDate->format('Y-m-d'))) ?>" required></label></div>
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
        <div class="col-12"><label class="form-label app-required-label">Correo electrónico <input class="form-control" type="email" name="email" value="<?= $escape($field('representative-contact', 'email', $representative->email)) ?>" required></label></div>
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

<?php endif; ?>
<?php if ($studentOption !== null && $student !== null && $studentRole !== null): ?>
<?php if ($currentPage === 'student'): ?>
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
        <div class="col-12 col-md-6"><label class="form-label app-required-label">Primer nombre <input class="form-control" name="first_name" value="<?= $escape($field('student-personal', 'first_name', $student->firstName)) ?>" required></label></div>
        <div class="col-12 col-md-6"><label class="form-label">Segundo nombre <input class="form-control" name="middle_name" value="<?= $escape($field('student-personal', 'middle_name', $student->middleName)) ?>"></label></div>
        <div class="col-12 col-md-6"><label class="form-label app-required-label">Primer apellido <input class="form-control" name="first_surname" value="<?= $escape($field('student-personal', 'first_surname', $student->firstSurname)) ?>" required></label></div>
        <div class="col-12 col-md-6"><label class="form-label">Segundo apellido <input class="form-control" name="second_surname" value="<?= $escape($field('student-personal', 'second_surname', $student->secondSurname)) ?>"></label></div>
        <div class="col-12 col-md-4"><label class="form-label app-required-label">Fecha de nacimiento <input class="form-control" type="date" name="birth_date" value="<?= $escape($field('student-personal', 'birth_date', $student->birthDate->format('Y-m-d'))) ?>" required></label></div>
        <div class="col-12 col-md-4"><label class="form-label">Estado civil <select class="form-select" name="marital_status_id"><option value="">No informado</option><?php foreach ($formOptions->maritalStatuses as $option): ?><option value="<?= $escape($option->id) ?>"<?= $selected($field('student-personal', 'marital_status_id', $student->maritalStatusId), $option->id) ?>><?= $escape($option->name) ?></option><?php endforeach; ?></select></label></div>
        <div class="col-12 col-md-4"><label class="form-label">Nivel educativo <select class="form-select" name="education_level_id"><option value="">No informado</option><?php foreach ($formOptions->educationLevels as $option): ?><option value="<?= $escape($option->id) ?>"<?= $selected($field('student-personal', 'education_level_id', $student->educationLevelId), $option->id) ?>><?= $escape($option->name) ?></option><?php endforeach; ?></select></label></div>
        <?php $autosaveFeedback('student-personal'); ?>
        <div class="col-12"><button type="submit" class="btn btn-primary" data-enrollment-fallback-save>Guardar información del estudiante</button></div>
    </form>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($isAnnualPage): ?>
<section class="app-context-banner" id="informacion-anual-matricula" aria-labelledby="annual-data-heading">
    <div>
        <h2 class="h4" id="annual-data-heading">Información anual de matrícula</h2>
        <p class="mb-0">Estos datos pertenecen exclusivamente a la matrícula del período académico seleccionado.</p>
    </div>
</section>

<?php if ($currentPage === 'placement'): ?>
<section class="app-data-card app-readonly-panel" aria-labelledby="placement-heading">
    <h2 class="h4" id="placement-heading">Ubicación académica</h2>
    <p class="text-body-secondary">Información de solo lectura asignada por la institución.</p>
    <?php if ($academicPlacement === null): ?>
    <p>Ubicación académica pendiente.</p>
    <?php else: ?>
    <dl class="app-data-list"><dt>Grado</dt><dd><?= $escape($academicPlacement['grade']->name) ?></dd><dt>Sección</dt><dd><?= $escape($academicPlacement['section']?->name ?? 'No asignada') ?></dd></dl>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($draftEditable): ?>
<?php $billing = $enrollment->billingInformation; ?>
<?php if ($currentPage === 'billing'): ?>
<section class="app-form-section" aria-labelledby="billing-heading">
    <h2 class="h4" id="billing-heading">Información de facturación</h2>
    <form method="post" action="/representative/enrollment/student/billing" class="row g-3" data-enrollment-autosave data-section="billing">
        <?php $studentHidden(); ?>
        <div class="col-12"><label class="form-label app-required-label">Tipo de identificación <select class="form-select" name="identification_type_id" required><option value="">Seleccionar</option><?php foreach ($formOptions->documentTypes as $option): ?><option value="<?= $escape($option->id) ?>"<?= $selected($field('billing', 'identification_type_id', $billing?->identificationTypeId), $option->id) ?>><?= $escape($option->name) ?></option><?php endforeach; ?></select></label></div>
        <div class="col-12 col-md-6"><label class="form-label app-required-label">Número de identificación <input class="form-control" name="identification_number" value="<?= $escape($field('billing', 'identification_number', $billing?->identificationNumber)) ?>" required></label></div>
        <div class="col-12 col-md-6"><label class="form-label app-required-label">Nombre o razón social <input class="form-control" name="legal_name" value="<?= $escape($field('billing', 'legal_name', $billing?->legalName)) ?>" required></label></div>
        <div class="col-12"><label class="form-label app-required-label">Dirección de facturación <input class="form-control" name="billing_address" value="<?= $escape($field('billing', 'billing_address', $billing?->billingAddress)) ?>" required></label></div>
        <div class="col-12 col-md-6"><label class="form-label app-required-label">Correo de facturación <input class="form-control" type="email" name="billing_email" value="<?= $escape($field('billing', 'billing_email', $billing?->billingEmail)) ?>" required></label></div>
        <div class="col-12 col-md-6"><label class="form-label app-required-label">Teléfono <input class="form-control" name="phone" value="<?= $escape($field('billing', 'phone', $billing?->phone)) ?>" required></label></div>
        <?php $autosaveFeedback('billing'); ?>
        <div class="col-12"><button type="submit" class="btn btn-primary" data-enrollment-fallback-save>Guardar facturación</button></div>
    </form>
</section>
<?php endif; ?>

<?php $medical = $enrollment->medicalInformation; ?>
<?php if ($currentPage === 'medical'): ?>
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
        <fieldset class="col-12"><legend class="h6 app-required-label"><?= $escape($legend) ?></legend><label><input type="radio" name="<?= $escape($booleanName) ?>" value="1" data-medical-controller<?= $checked($currentBoolean, '1') ?> required> Sí</label> <label><input type="radio" name="<?= $escape($booleanName) ?>" value="0" data-medical-controller<?= $checked($currentBoolean, '0') ?> required> No</label></fieldset>
        <div class="col-12" data-medical-dependent-for="<?= $escape($booleanName) ?>"><label class="form-label"><?= $escape($detailLabel) ?> <textarea class="form-control" name="<?= $escape($detailName) ?>" data-medical-detail-for="<?= $escape($booleanName) ?>"><?= $escape($field('medical', $detailName, $storedDetail)) ?></textarea></label></div>
        <?php endforeach; ?>
        <div class="col-12 col-md-6"><label class="form-label">Nombre del pediatra <input class="form-control" name="pediatrician_name" value="<?= $escape($field('medical', 'pediatrician_name', $medical?->pediatricianName)) ?>"></label></div>
        <div class="col-12 col-md-6"><label class="form-label">Teléfono del pediatra <input class="form-control" name="pediatrician_phone" value="<?= $escape($field('medical', 'pediatrician_phone', $medical?->pediatricianPhone)) ?>"></label></div>
        <div class="col-12"><label class="form-label">Observaciones <textarea class="form-control" name="observations"><?= $escape($field('medical', 'observations', $medical?->observations)) ?></textarea></label></div>
        <?php $autosaveFeedback('medical'); ?>
        <div class="col-12"><button type="submit" class="btn btn-primary" data-enrollment-fallback-save>Guardar información médica</button></div>
    </form>
</section>
<?php endif; ?>

<?php if ($currentPage === 'transport'): ?>
<section class="app-form-section" aria-labelledby="transport-heading">
    <h2 class="h4" id="transport-heading">Información de transporte</h2>
    <?php $transportValue = $field('transport', 'requires_institutional_transport', $enrollment->transportInformation === null ? '' : ($enrollment->transportInformation->requiresInstitutionalTransport ? '1' : '0')); ?>
    <form method="post" action="/representative/enrollment/student/transport" data-enrollment-autosave data-section="transport">
        <?php $studentHidden(); ?>
        <fieldset><legend class="h6 app-required-label">¿Requiere transporte institucional?</legend><label><input type="radio" name="requires_institutional_transport" value="1"<?= $checked($transportValue, '1') ?> required> Sí</label> <label><input type="radio" name="requires_institutional_transport" value="0"<?= $checked($transportValue, '0') ?> required> No</label></fieldset>
        <?php $autosaveFeedback('transport'); ?>
        <button type="submit" class="btn btn-primary" data-enrollment-fallback-save>Guardar transporte</button>
    </form>
</section>
<?php endif; ?>

<?php if ($currentPage === 'leave-alone'): ?>
<section class="app-form-section" aria-labelledby="leave-alone-heading">
    <h2 class="h4" id="leave-alone-heading">Autorización de salida autónoma</h2>
    <?php $leaveValue = $field('leave-alone', 'is_authorized_to_leave_alone', $enrollment->isAuthorizedToLeaveAlone ? '1' : '0'); ?>
    <form method="post" action="/representative/enrollment/student/leave-alone" data-enrollment-autosave data-section="leave-alone">
        <?php $studentHidden(); ?>
        <fieldset><legend class="h6 app-required-label">¿El estudiante puede salir solo?</legend><label><input type="radio" name="is_authorized_to_leave_alone" value="1"<?= $checked($leaveValue, '1') ?> required> Sí</label> <label><input type="radio" name="is_authorized_to_leave_alone" value="0"<?= $checked($leaveValue, '0') ?> required> No</label></fieldset>
        <?php $autosaveFeedback('leave-alone'); ?>
        <button type="submit" class="btn btn-primary" data-enrollment-fallback-save>Guardar autorización de salida</button>
    </form>
</section>
<?php endif; ?>
<?php elseif ($enrollment !== null): ?>
<section class="app-data-card app-readonly-panel" aria-labelledby="annual-readonly-heading">
    <h2 class="h4" id="annual-readonly-heading"><?= $escape($pageTitles[$currentPage]) ?> — solo lectura</h2>
    <?php if ($currentPage === 'billing'): ?>
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
    <?php endif; ?>
    <?php if ($currentPage === 'medical'): ?>
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
    <?php endif; ?>
    <?php if ($currentPage === 'transport'): ?>
    <p class="mt-4">Requiere transporte institucional: <?= $enrollment->transportInformation === null ? 'No informado' : ($enrollment->transportInformation->requiresInstitutionalTransport ? 'Sí' : 'No') ?></p>
    <?php endif; ?>
    <?php if ($currentPage === 'leave-alone'): ?>
    <p>Autorización de salida autónoma: <?= $enrollment->isAuthorizedToLeaveAlone ? 'Sí' : 'No' ?></p>
    <?php endif; ?>
</section>
<?php endif; ?>
<?php endif; ?>
<?php endif; ?>

<div class="app-action-group mt-4">
    <a class="btn btn-outline-secondary" href="<?= $escape($currentReturn) ?>" data-enrollment-navigation><?= $isAnnualPage ? 'Volver al resumen de matrícula' : ($studentOption === null ? 'Volver a Actualización de datos' : 'Volver a la matrícula') ?></a>
    <?php if ($currentPage === 'leave-alone' && $studentOption !== null): ?>
    <a class="btn btn-link" href="/representative/resources/authorized-pickups<?= $escape($studentSuffix) ?>" data-enrollment-navigation>Revisar personas autorizadas para retirar</a>
    <?php endif; ?>
</div>
