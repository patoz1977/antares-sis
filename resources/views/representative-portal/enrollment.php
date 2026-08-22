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

    return $id === null ? 'Not supplied' : 'Unavailable';
};
$selected = static fn (mixed $left, mixed $right): string => (string) $left === (string) $right ? ' selected' : '';
$checked = static fn (mixed $left, string $right): string => (string) $left === $right ? ' checked' : '';
$status = static fn (RepresentativeEnrollmentSectionStatus $value): string =>
    $value === RepresentativeEnrollmentSectionStatus::Complete ? 'Complete' : 'Pending';
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
$editable = $portal->maintenanceEnabled && !$portal->readOnly;
$draftEditable = $editable && $enrollment?->status === 'DRAFT';
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
?>
<main class="container py-3">
<header class="mb-4">
    <h1>Representative Enrollment</h1>
    <p>Current Family: <strong><?= $escape($context->familyDisplayName) ?></strong></p>
    <nav aria-label="Representative portal navigation">
        <a href="/representative">Representative Portal</a>
        <?php if ($context->acknowledgementsSatisfied): ?>
        <span aria-hidden="true"> · </span>
        <a href="/representative/resources">Family Resources</a>
        <?php endif; ?>
        <?php if ($context->canChangeFamily): ?>
        <span aria-hidden="true"> · </span>
        <a href="/representative">Change Family</a>
        <?php endif; ?>
    </nav>
</header>

<?php if (is_string($successMessage ?? null) && $successMessage !== ''): ?>
<p class="alert alert-success" role="status"><?= $escape($successMessage) ?></p>
<?php endif; ?>
<?php if (is_string($errorMessage ?? null) && $errorMessage !== ''): ?>
<p class="alert alert-warning" role="alert"><?= $escape($errorMessage) ?></p>
<?php endif; ?>
<?php if ($sectionErrors !== []): ?>
<div class="alert alert-danger" role="alert" aria-labelledby="enrollment-errors-heading">
    <h2 id="enrollment-errors-heading" class="h5">Review this section</h2>
    <ul>
        <?php foreach ($sectionErrors as $message): ?>
        <li><?= $escape($message) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<section class="mb-4" aria-labelledby="academic-period-heading">
    <h2 id="academic-period-heading">Academic Period</h2>
    <?php if ($period === null): ?>
    <p role="status">No active Academic Period is currently configured. Enrollment maintenance is unavailable.</p>
    <?php else: ?>
    <p><strong><?= $escape($period->name) ?></strong> (<?= $escape($period->code) ?>)</p>
    <p><?= $escape($period->startsOn) ?> to <?= $escape($period->endsOn) ?></p>
    <?php endif; ?>
</section>

<section class="mb-4" aria-labelledby="acknowledgements-heading">
    <h2 id="acknowledgements-heading">Institutional Acknowledgements</h2>
    <?php if ($period !== null && !$context->acknowledgementsSatisfied): ?>
    <p role="alert">Institutional Acknowledgements are required before Enrollment information can be maintained.</p>
    <p><a href="/representative/acknowledgements">Review Institutional Acknowledgements</a></p>
    <?php elseif ($period !== null): ?>
    <p>Complete for the current Academic Period.</p>
    <?php else: ?>
    <p>Unavailable until an active Academic Period is configured.</p>
    <?php endif; ?>
</section>

<section class="mb-4" aria-labelledby="student-navigation-heading">
    <h2 id="student-navigation-heading">Student</h2>
    <?php if ($context->students === []): ?>
    <p>No active Students are available in the current Family.</p>
    <?php else: ?>
    <form method="get" action="/representative/enrollment" class="row g-2 align-items-end">
        <div class="col-12 col-md-8">
            <label for="student_id" class="form-label">Select Student</label>
            <select id="student_id" name="student_id" class="form-select" required>
                <option value="">Choose a Student</option>
                <?php foreach ($context->students as $option): ?>
                <option value="<?= $escape($option->student->id) ?>"<?= $selected($option->student->id, $studentOption?->student->id) ?>>
                    <?= $escape($option->displayName) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12 col-md-auto">
            <button type="submit" class="btn btn-primary">Open Student</button>
        </div>
    </form>
    <?php endif; ?>
</section>

<?php if ($studentOption !== null): ?>
<section class="mb-4" aria-labelledby="enrollment-state-heading">
    <h2 id="enrollment-state-heading">Enrollment State</h2>
    <p>Selected Student: <strong><?= $escape($studentOption->displayName) ?></strong></p>
    <?php if ($enrollment === null): ?>
    <p>Enrollment Draft has not been started.</p>
    <?php if ($portal->maintenanceEnabled): ?>
    <form method="post" action="/representative/enrollment/open">
        <?php $studentHidden(); ?>
        <button type="submit" class="btn btn-primary">Start Enrollment Draft</button>
    </form>
    <?php endif; ?>
    <?php else: ?>
    <p>Status: <strong><?= $escape($enrollment->status) ?></strong></p>
    <?php if ($portal->readOnly): ?>
    <p role="status">This Enrollment is read-only.</p>
    <?php else: ?>
    <p>This Draft can be maintained section by section.</p>
    <?php endif; ?>
    <?php endif; ?>
</section>

<section class="mb-4" aria-labelledby="progress-heading">
    <h2 id="progress-heading">Section Progress</h2>
    <p>These indicators help complete information. They do not mean the Enrollment has been submitted.</p>
    <ul>
        <li>Institutional Acknowledgements: <?= $escape($status($portal->progress->acknowledgements)) ?></li>
        <li>Representative Personal: <?= $escape($status($portal->progress->representativePersonal)) ?></li>
        <li>Representative Contact: <?= $escape($status($portal->progress->representativeContact)) ?></li>
        <li>Employment: <?= $escape($status($portal->progress->employment)) ?></li>
        <li>Student Personal: <?= $escape($status($portal->progress->studentPersonal)) ?></li>
        <li>Student Address: <?= $escape($status($portal->progress->studentAddress)) ?></li>
        <li>Academic Placement: <?= $escape($status($portal->progress->academicPlacement)) ?></li>
        <li>Billing: <?= $escape($status($portal->progress->billing)) ?></li>
        <li>Medical: <?= $escape($status($portal->progress->medical)) ?></li>
        <li>Transport: <?= $escape($status($portal->progress->transport)) ?></li>
        <li>Emergency Contacts: <?= $escape($status($portal->progress->emergencyContacts)) ?></li>
        <li>Pickup or leave-alone: <?= $escape($status($portal->progress->pickupOrLeaveAlone)) ?></li>
    </ul>
    <?php if ($context->acknowledgementsSatisfied): ?>
    <p><a href="/representative/resources">Maintain addresses, emergency contacts and authorized pickups in Family Resources</a>.</p>
    <?php endif; ?>
</section>
<?php endif; ?>

<section class="mb-4" aria-labelledby="representative-personal-heading">
    <h2 id="representative-personal-heading">Representative Personal Information</h2>
    <dl>
        <dt>Name</dt><dd><?= $escape($personName($representative)) ?></dd>
        <dt>Document type</dt><dd><?= $escape($optionName($formOptions->documentTypes, $representative->documentTypeId)) ?></dd>
        <dt>Document number</dt><dd><?= $escape($representative->documentNumber ?? 'Not supplied') ?></dd>
        <dt>Sex</dt><dd><?= $escape($optionName($formOptions->sexes, $representative->sexId)) ?></dd>
    </dl>
    <?php if ($editable): ?>
    <form method="post" action="/representative/enrollment/representative/personal" class="row g-3">
        <?php $hiddenContext(); ?>
        <?php if ($studentOption !== null): ?><input type="hidden" name="student_id" value="<?= $escape($studentOption->student->id) ?>"><?php endif; ?>
        <div class="col-12 col-md-6"><label class="form-label">First name <input class="form-control" name="first_name" value="<?= $escape($field('representative-personal', 'first_name', $representative->firstName)) ?>" required></label></div>
        <div class="col-12 col-md-6"><label class="form-label">Middle name <input class="form-control" name="middle_name" value="<?= $escape($field('representative-personal', 'middle_name', $representative->middleName)) ?>"></label></div>
        <div class="col-12 col-md-6"><label class="form-label">First surname <input class="form-control" name="first_surname" value="<?= $escape($field('representative-personal', 'first_surname', $representative->firstSurname)) ?>" required></label></div>
        <div class="col-12 col-md-6"><label class="form-label">Second surname <input class="form-control" name="second_surname" value="<?= $escape($field('representative-personal', 'second_surname', $representative->secondSurname)) ?>"></label></div>
        <div class="col-12 col-md-4"><label class="form-label">Birth date <input class="form-control" type="date" name="birth_date" value="<?= $escape($field('representative-personal', 'birth_date', $representative->birthDate->format('Y-m-d'))) ?>" required></label></div>
        <div class="col-12 col-md-4"><label class="form-label">Marital status <select class="form-select" name="marital_status_id"><option value="">Not supplied</option><?php foreach ($formOptions->maritalStatuses as $option): ?><option value="<?= $escape($option->id) ?>"<?= $selected($field('representative-personal', 'marital_status_id', $representative->maritalStatusId), $option->id) ?>><?= $escape($option->name) ?></option><?php endforeach; ?></select></label></div>
        <div class="col-12 col-md-4"><label class="form-label">Education level <select class="form-select" name="education_level_id"><option value="">Not supplied</option><?php foreach ($formOptions->educationLevels as $option): ?><option value="<?= $escape($option->id) ?>"<?= $selected($field('representative-personal', 'education_level_id', $representative->educationLevelId), $option->id) ?>><?= $escape($option->name) ?></option><?php endforeach; ?></select></label></div>
        <div class="col-12"><button type="submit" class="btn btn-primary">Save Personal Information</button></div>
    </form>
    <?php endif; ?>
</section>

<section class="mb-4" aria-labelledby="representative-contact-heading">
    <h2 id="representative-contact-heading">Representative Contact Information</h2>
    <dl><dt>Email</dt><dd><?= $escape($representative->email ?? 'Not supplied') ?></dd><dt>Mobile phone</dt><dd><?= $escape($representative->mobilePhone ?? 'Not supplied') ?></dd><dt>Landline phone</dt><dd><?= $escape($representative->landlinePhone ?? 'Not supplied') ?></dd></dl>
    <?php if ($editable): ?>
    <form method="post" action="/representative/enrollment/representative/contact" class="row g-3">
        <?php $hiddenContext(); ?><?php if ($studentOption !== null): ?><input type="hidden" name="student_id" value="<?= $escape($studentOption->student->id) ?>"><?php endif; ?>
        <div class="col-12"><label class="form-label">Email <input class="form-control" type="email" name="email" value="<?= $escape($field('representative-contact', 'email', $representative->email)) ?>" required></label></div>
        <div class="col-12 col-md-6"><label class="form-label">Mobile phone <input class="form-control" name="mobile_phone" value="<?= $escape($field('representative-contact', 'mobile_phone', $representative->mobilePhone)) ?>"></label></div>
        <div class="col-12 col-md-6"><label class="form-label">Landline phone <input class="form-control" name="landline_phone" value="<?= $escape($field('representative-contact', 'landline_phone', $representative->landlinePhone)) ?>"></label></div>
        <div class="col-12"><button type="submit" class="btn btn-primary">Save Contact Information</button></div>
    </form>
    <?php endif; ?>
</section>

<section class="mb-4" aria-labelledby="employment-heading">
    <h2 id="employment-heading">Employment Information</h2>
    <p>This section is optional.</p>
    <dl><dt>Occupation</dt><dd><?= $escape($role->occupation ?? 'Not supplied') ?></dd><dt>Company</dt><dd><?= $escape($role->companyName ?? 'Not supplied') ?></dd><dt>Position</dt><dd><?= $escape($role->position ?? 'Not supplied') ?></dd><dt>Work phone</dt><dd><?= $escape($role->workPhone ?? 'Not supplied') ?></dd><dt>Work email</dt><dd><?= $escape($role->workEmail ?? 'Not supplied') ?></dd></dl>
    <?php if ($editable): ?>
    <form method="post" action="/representative/enrollment/representative/employment" class="row g-3">
        <?php $hiddenContext(); ?><?php if ($studentOption !== null): ?><input type="hidden" name="student_id" value="<?= $escape($studentOption->student->id) ?>"><?php endif; ?>
        <div class="col-12 col-md-6"><label class="form-label">Occupation <input class="form-control" name="occupation" value="<?= $escape($field('representative-employment', 'occupation', $role->occupation)) ?>"></label></div>
        <div class="col-12 col-md-6"><label class="form-label">Company <input class="form-control" name="company_name" value="<?= $escape($field('representative-employment', 'company_name', $role->companyName)) ?>"></label></div>
        <div class="col-12 col-md-6"><label class="form-label">Position <input class="form-control" name="position" value="<?= $escape($field('representative-employment', 'position', $role->position)) ?>"></label></div>
        <div class="col-12 col-md-6"><label class="form-label">Work phone <input class="form-control" name="work_phone" value="<?= $escape($field('representative-employment', 'work_phone', $role->workPhone)) ?>"></label></div>
        <div class="col-12"><label class="form-label">Work email <input class="form-control" type="email" name="work_email" value="<?= $escape($field('representative-employment', 'work_email', $role->workEmail)) ?>"></label></div>
        <div class="col-12"><button type="submit" class="btn btn-primary">Save Employment Information</button></div>
    </form>
    <?php endif; ?>
</section>

<?php if ($studentOption !== null && $student !== null && $studentRole !== null): ?>
<section class="mb-4" aria-labelledby="student-personal-heading">
    <h2 id="student-personal-heading">Student Personal Information</h2>
    <dl>
        <dt>Name</dt><dd><?= $escape($personName($student)) ?></dd>
        <dt>Document type</dt><dd><?= $escape($optionName($formOptions->documentTypes, $student->documentTypeId)) ?></dd>
        <dt>Document number</dt><dd><?= $escape($student->documentNumber ?? 'Not supplied') ?></dd>
        <dt>Sex</dt><dd><?= $escape($optionName($formOptions->sexes, $student->sexId)) ?></dd>
        <dt>Institutional code</dt><dd><?= $escape($studentRole->institutionalCode) ?></dd>
        <dt>Admission date</dt><dd><?= $escape($studentRole->admissionDate->format('Y-m-d')) ?></dd>
        <dt>Student status</dt><dd><?= $escape($studentRole->status->value) ?></dd>
    </dl>
    <?php if ($editable): ?>
    <form method="post" action="/representative/enrollment/student/personal" class="row g-3">
        <?php $studentHidden(); ?>
        <div class="col-12 col-md-6"><label class="form-label">First name <input class="form-control" name="first_name" value="<?= $escape($field('student-personal', 'first_name', $student->firstName)) ?>" required></label></div>
        <div class="col-12 col-md-6"><label class="form-label">Middle name <input class="form-control" name="middle_name" value="<?= $escape($field('student-personal', 'middle_name', $student->middleName)) ?>"></label></div>
        <div class="col-12 col-md-6"><label class="form-label">First surname <input class="form-control" name="first_surname" value="<?= $escape($field('student-personal', 'first_surname', $student->firstSurname)) ?>" required></label></div>
        <div class="col-12 col-md-6"><label class="form-label">Second surname <input class="form-control" name="second_surname" value="<?= $escape($field('student-personal', 'second_surname', $student->secondSurname)) ?>"></label></div>
        <div class="col-12 col-md-4"><label class="form-label">Birth date <input class="form-control" type="date" name="birth_date" value="<?= $escape($field('student-personal', 'birth_date', $student->birthDate->format('Y-m-d'))) ?>" required></label></div>
        <div class="col-12 col-md-4"><label class="form-label">Marital status <select class="form-select" name="marital_status_id"><option value="">Not supplied</option><?php foreach ($formOptions->maritalStatuses as $option): ?><option value="<?= $escape($option->id) ?>"<?= $selected($field('student-personal', 'marital_status_id', $student->maritalStatusId), $option->id) ?>><?= $escape($option->name) ?></option><?php endforeach; ?></select></label></div>
        <div class="col-12 col-md-4"><label class="form-label">Education level <select class="form-select" name="education_level_id"><option value="">Not supplied</option><?php foreach ($formOptions->educationLevels as $option): ?><option value="<?= $escape($option->id) ?>"<?= $selected($field('student-personal', 'education_level_id', $student->educationLevelId), $option->id) ?>><?= $escape($option->name) ?></option><?php endforeach; ?></select></label></div>
        <div class="col-12"><button type="submit" class="btn btn-primary">Save Student Information</button></div>
    </form>
    <?php endif; ?>
</section>

<section class="mb-4" aria-labelledby="placement-heading">
    <h2 id="placement-heading">Academic Placement</h2>
    <?php if ($academicPlacement === null): ?>
    <p>Pending academic placement.</p>
    <?php else: ?>
    <dl><dt>Grade</dt><dd><?= $escape($academicPlacement['grade']->name) ?></dd><dt>Section</dt><dd><?= $escape($academicPlacement['section']?->name ?? 'Not assigned') ?></dd></dl>
    <?php endif; ?>
</section>

<?php if ($draftEditable): ?>
<?php $billing = $enrollment->billingInformation; ?>
<section class="mb-4" aria-labelledby="billing-heading">
    <h2 id="billing-heading">Billing Information</h2>
    <form method="post" action="/representative/enrollment/student/billing" class="row g-3">
        <?php $studentHidden(); ?>
        <div class="col-12"><label class="form-label">Identification type <select class="form-select" name="identification_type_id" required><option value="">Select</option><?php foreach ($formOptions->documentTypes as $option): ?><option value="<?= $escape($option->id) ?>"<?= $selected($field('billing', 'identification_type_id', $billing?->identificationTypeId), $option->id) ?>><?= $escape($option->name) ?></option><?php endforeach; ?></select></label></div>
        <div class="col-12 col-md-6"><label class="form-label">Identification number <input class="form-control" name="identification_number" value="<?= $escape($field('billing', 'identification_number', $billing?->identificationNumber)) ?>" required></label></div>
        <div class="col-12 col-md-6"><label class="form-label">Legal name <input class="form-control" name="legal_name" value="<?= $escape($field('billing', 'legal_name', $billing?->legalName)) ?>" required></label></div>
        <div class="col-12"><label class="form-label">Billing address <input class="form-control" name="billing_address" value="<?= $escape($field('billing', 'billing_address', $billing?->billingAddress)) ?>" required></label></div>
        <div class="col-12 col-md-6"><label class="form-label">Billing email <input class="form-control" type="email" name="billing_email" value="<?= $escape($field('billing', 'billing_email', $billing?->billingEmail)) ?>" required></label></div>
        <div class="col-12 col-md-6"><label class="form-label">Phone <input class="form-control" name="phone" value="<?= $escape($field('billing', 'phone', $billing?->phone)) ?>" required></label></div>
        <div class="col-12"><button type="submit" class="btn btn-primary">Save Billing Information</button></div>
    </form>
</section>

<?php $medical = $enrollment->medicalInformation; ?>
<section class="mb-4" aria-labelledby="medical-heading">
    <h2 id="medical-heading">Medical Information</h2>
    <p>Complete the related detail only when the answer is Yes.</p>
    <form method="post" action="/representative/enrollment/student/medical" class="row g-3">
        <?php $studentHidden(); ?>
        <?php
        $medicalFields = [
            ['has_medical_condition', 'Medical condition', 'medical_condition_detail', 'Medical condition detail', $medical?->hasMedicalCondition, $medical?->medicalConditionDetail],
            ['has_allergies', 'Allergies', 'allergy_detail', 'Allergy detail', $medical?->hasAllergies, $medical?->allergyDetail],
            ['takes_permanent_medication', 'Permanent medication', 'medication_name', 'Medication name', $medical?->takesPermanentMedication, $medical?->medicationName],
            ['requires_special_care', 'Special care', 'special_care_detail', 'Special care detail', $medical?->requiresSpecialCare, $medical?->specialCareDetail],
            ['has_medical_insurance', 'Medical insurance', 'insurance_provider', 'Insurance provider', $medical?->hasMedicalInsurance, $medical?->insuranceProvider],
        ];
        foreach ($medicalFields as [$booleanName, $legend, $detailName, $detailLabel, $storedBoolean, $storedDetail]):
            $currentBoolean = $field('medical', $booleanName, $storedBoolean === null ? '' : ($storedBoolean ? '1' : '0'));
        ?>
        <fieldset class="col-12"><legend class="h6"><?= $escape($legend) ?></legend><label><input type="radio" name="<?= $escape($booleanName) ?>" value="1"<?= $checked($currentBoolean, '1') ?> required> Yes</label> <label><input type="radio" name="<?= $escape($booleanName) ?>" value="0"<?= $checked($currentBoolean, '0') ?> required> No</label></fieldset>
        <div class="col-12"><label class="form-label"><?= $escape($detailLabel) ?> <textarea class="form-control" name="<?= $escape($detailName) ?>"><?= $escape($field('medical', $detailName, $storedDetail)) ?></textarea></label></div>
        <?php endforeach; ?>
        <div class="col-12 col-md-6"><label class="form-label">Pediatrician name <input class="form-control" name="pediatrician_name" value="<?= $escape($field('medical', 'pediatrician_name', $medical?->pediatricianName)) ?>"></label></div>
        <div class="col-12 col-md-6"><label class="form-label">Pediatrician phone <input class="form-control" name="pediatrician_phone" value="<?= $escape($field('medical', 'pediatrician_phone', $medical?->pediatricianPhone)) ?>"></label></div>
        <div class="col-12"><label class="form-label">Observations <textarea class="form-control" name="observations"><?= $escape($field('medical', 'observations', $medical?->observations)) ?></textarea></label></div>
        <div class="col-12"><button type="submit" class="btn btn-primary">Save Medical Information</button></div>
    </form>
</section>

<section class="mb-4" aria-labelledby="transport-heading">
    <h2 id="transport-heading">Transport Information</h2>
    <?php $transportValue = $field('transport', 'requires_institutional_transport', $enrollment->transportInformation === null ? '' : ($enrollment->transportInformation->requiresInstitutionalTransport ? '1' : '0')); ?>
    <form method="post" action="/representative/enrollment/student/transport">
        <?php $studentHidden(); ?>
        <fieldset><legend class="h6">Requires institutional transport</legend><label><input type="radio" name="requires_institutional_transport" value="1"<?= $checked($transportValue, '1') ?> required> Yes</label> <label><input type="radio" name="requires_institutional_transport" value="0"<?= $checked($transportValue, '0') ?> required> No</label></fieldset>
        <button type="submit" class="btn btn-primary">Save Transport Information</button>
    </form>
</section>

<section class="mb-4" aria-labelledby="leave-alone-heading">
    <h2 id="leave-alone-heading">Leave-alone Authorization</h2>
    <?php $leaveValue = $field('leave-alone', 'is_authorized_to_leave_alone', $enrollment->isAuthorizedToLeaveAlone ? '1' : '0'); ?>
    <form method="post" action="/representative/enrollment/student/leave-alone">
        <?php $studentHidden(); ?>
        <fieldset><legend class="h6">Student may leave alone</legend><label><input type="radio" name="is_authorized_to_leave_alone" value="1"<?= $checked($leaveValue, '1') ?> required> Yes</label> <label><input type="radio" name="is_authorized_to_leave_alone" value="0"<?= $checked($leaveValue, '0') ?> required> No</label></fieldset>
        <button type="submit" class="btn btn-primary">Save Leave-alone Authorization</button>
    </form>
</section>
<?php elseif ($enrollment !== null): ?>
<section class="mb-4" aria-labelledby="annual-readonly-heading">
    <h2 id="annual-readonly-heading">Annual Information</h2>
    <?php if ($enrollment->billingInformation === null): ?>
    <p>Billing Information: Not supplied.</p>
    <?php else: ?>
    <h3>Billing Information</h3>
    <dl>
        <dt>Identification type</dt><dd><?= $escape($optionName($formOptions->documentTypes, $enrollment->billingInformation->identificationTypeId)) ?></dd>
        <dt>Identification number</dt><dd><?= $escape($enrollment->billingInformation->identificationNumber) ?></dd>
        <dt>Legal name</dt><dd><?= $escape($enrollment->billingInformation->legalName) ?></dd>
        <dt>Billing address</dt><dd><?= $escape($enrollment->billingInformation->billingAddress) ?></dd>
        <dt>Billing email</dt><dd><?= $escape($enrollment->billingInformation->billingEmail) ?></dd>
        <dt>Phone</dt><dd><?= $escape($enrollment->billingInformation->phone) ?></dd>
    </dl>
    <?php endif; ?>
    <?php if ($enrollment->medicalInformation === null): ?>
    <p>Medical Information: Not supplied.</p>
    <?php else: ?>
    <h3>Medical Information</h3>
    <dl>
        <dt>Medical condition</dt><dd><?= $enrollment->medicalInformation->hasMedicalCondition ? 'Yes' : 'No' ?></dd>
        <dt>Medical condition detail</dt><dd><?= $escape($enrollment->medicalInformation->medicalConditionDetail ?? 'Not supplied') ?></dd>
        <dt>Allergies</dt><dd><?= $enrollment->medicalInformation->hasAllergies ? 'Yes' : 'No' ?></dd>
        <dt>Allergy detail</dt><dd><?= $escape($enrollment->medicalInformation->allergyDetail ?? 'Not supplied') ?></dd>
        <dt>Permanent medication</dt><dd><?= $enrollment->medicalInformation->takesPermanentMedication ? 'Yes' : 'No' ?></dd>
        <dt>Medication name</dt><dd><?= $escape($enrollment->medicalInformation->medicationName ?? 'Not supplied') ?></dd>
        <dt>Special care</dt><dd><?= $enrollment->medicalInformation->requiresSpecialCare ? 'Yes' : 'No' ?></dd>
        <dt>Special care detail</dt><dd><?= $escape($enrollment->medicalInformation->specialCareDetail ?? 'Not supplied') ?></dd>
        <dt>Medical insurance</dt><dd><?= $enrollment->medicalInformation->hasMedicalInsurance ? 'Yes' : 'No' ?></dd>
        <dt>Insurance provider</dt><dd><?= $escape($enrollment->medicalInformation->insuranceProvider ?? 'Not supplied') ?></dd>
        <dt>Pediatrician</dt><dd><?= $escape($enrollment->medicalInformation->pediatricianName ?? 'Not supplied') ?></dd>
        <dt>Pediatrician phone</dt><dd><?= $escape($enrollment->medicalInformation->pediatricianPhone ?? 'Not supplied') ?></dd>
        <dt>Observations</dt><dd><?= $escape($enrollment->medicalInformation->observations ?? 'Not supplied') ?></dd>
    </dl>
    <?php endif; ?>
    <p>Requires institutional transport: <?= $enrollment->transportInformation === null ? 'Not supplied' : ($enrollment->transportInformation->requiresInstitutionalTransport ? 'Yes' : 'No') ?></p>
    <p>Leave-alone authorization: <?= $enrollment->isAuthorizedToLeaveAlone ? 'Yes' : 'No' ?></p>
</section>
<?php endif; ?>
<?php endif; ?>

<footer class="mt-4">
    <p><a href="<?= $escape($studentLocation) ?>">Reload Enrollment information</a></p>
    <form method="post" action="/logout">
        <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken ?? '') ?>">
        <button type="submit" class="btn btn-outline-secondary">Sign out</button>
    </form>
</footer>
</main>
