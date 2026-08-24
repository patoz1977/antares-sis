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
$yesNo = static fn (bool $value): string => $value ? 'Yes' : 'No';
$supplied = static fn (?string $value): string => $value === null || $value === '' ? 'Not supplied' : $value;
$formatInstant = static fn (?DateTimeImmutable $value): string =>
    $value?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s') ?? 'Not recorded';

$student = $submissionReview->student;
$studentPerson = $student->person;
$representative = $submissionReview->representativePerson;
$enrollment = $submissionReview->enrollment;
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
<main class="container py-3">
<header class="mb-4">
    <h1>Review and Submit Enrollment</h1>
    <p>Review the current information for <strong><?= $escape($student->displayName) ?></strong> before Submission.</p>
    <nav aria-label="Representative Enrollment review navigation">
        <a href="<?= $escape($enrollmentLocation) ?>">Back to Enrollment maintenance</a>
        <span aria-hidden="true"> · </span>
        <a href="/representative/resources">Maintain Family Resources</a>
        <span aria-hidden="true"> · </span>
        <a href="/representative/acknowledgements">Institutional Acknowledgements</a>
        <span aria-hidden="true"> · </span>
        <a href="/representative">Representative Portal</a>
    </nav>
</header>

<?php if (is_string($successMessage ?? null) && $successMessage !== ''): ?>
<p class="alert alert-success" role="status"><?= $escape($successMessage) ?></p>
<?php endif; ?>
<?php if (is_string($errorMessage ?? null) && $errorMessage !== ''): ?>
<p class="alert alert-warning" role="alert"><?= $escape($errorMessage) ?></p>
<?php endif; ?>

<section class="mb-4" aria-labelledby="submission-context-heading">
    <h2 id="submission-context-heading">Enrollment Context</h2>
    <dl>
        <dt>Current Family</dt><dd><?= $escape($submissionReview->familyDisplayName) ?></dd>
        <dt>Student</dt><dd><?= $escape($student->displayName) ?></dd>
        <dt>Institutional code</dt><dd><?= $escape($student->student->institutionalCode) ?></dd>
        <dt>Academic Period</dt><dd><?= $escape($period->name) ?> (<?= $escape($period->code) ?>)</dd>
        <dt>Enrollment status</dt><dd><strong><?= $escape($enrollment->status) ?></strong></dd>
        <dt>Enrollment started at (UTC)</dt><dd><?= $escape($formatInstant($enrollment->startedAt)) ?></dd>
        <dt>Submitted at (UTC)</dt><dd><?= $escape($formatInstant($enrollment->submittedAt)) ?></dd>
        <dt>Completed at (UTC)</dt><dd><?= $escape($formatInstant($enrollment->completedAt)) ?></dd>
        <dt>Cancelled at (UTC)</dt><dd><?= $escape($formatInstant($enrollment->cancelledAt)) ?></dd>
        <dt>Grade</dt><dd><?= $escape(is_string($gradeName ?? null) ? $gradeName : ($placement?->gradeId ?? 'Not assigned')) ?></dd>
        <dt>Section</dt><dd><?= $escape(is_string($sectionName ?? null) ? $sectionName : ($placement?->sectionId ?? 'Not assigned')) ?></dd>
    </dl>
</section>

<section class="mb-4" aria-labelledby="current-live-data-heading">
    <h2 id="current-live-data-heading">Current Representative and Student Information</h2>
    <p>This is current live information from the SIS.</p>
    <h3>Current Representative</h3>
    <dl>
        <dt>Name</dt><dd><?= $escape($personName($representative)) ?></dd>
        <dt>Birth date</dt><dd><?= $escape($representative->birthDate->format('Y-m-d')) ?></dd>
        <dt>Email</dt><dd><?= $escape($supplied($representative->email)) ?></dd>
        <dt>Mobile phone</dt><dd><?= $escape($supplied($representative->mobilePhone)) ?></dd>
        <dt>Landline phone</dt><dd><?= $escape($supplied($representative->landlinePhone)) ?></dd>
    </dl>
    <h3>Current Student</h3>
    <dl>
        <dt>Name</dt><dd><?= $escape($personName($studentPerson)) ?></dd>
        <dt>Birth date</dt><dd><?= $escape($studentPerson->birthDate->format('Y-m-d')) ?></dd>
        <dt>Admission date</dt><dd><?= $escape($student->student->admissionDate->format('Y-m-d')) ?></dd>
        <dt>Status</dt><dd><?= $escape($student->student->status->value) ?></dd>
    </dl>
</section>

<section class="mb-4" aria-labelledby="current-family-resources-heading">
    <h2 id="current-family-resources-heading">Current Family Resources</h2>
    <p>These are the active Family Resources currently assigned to this Student.</p>
    <h3>Student address</h3>
    <?php if ($studentAddresses === []): ?>
    <p>None currently assigned.</p>
    <?php else: ?>
    <?php foreach ($studentAddresses as $address): ?>
    <address>
        <strong><?= $escape($address->label) ?></strong><br>
        <?= $escape($address->mainStreet) ?><?= $address->streetNumber === null ? '' : ' ' . $escape($address->streetNumber) ?><br>
        <?= $escape($supplied($address->sector)) ?>
    </address>
    <?php endforeach; ?>
    <?php endif; ?>

    <h3>Emergency contacts</h3>
    <?php if ($emergencyContacts === []): ?>
    <p>None currently assigned.</p>
    <?php else: ?>
    <ul>
        <?php foreach ($emergencyContacts as $entry): ?>
        <li>
            <?= $escape($entry['contact']->names) ?> — <?= $escape($entry['contact']->mobilePhone) ?>
            (priority <?= $escape($entry['priority'] ?? 'not supplied') ?>)
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <h3>Authorized pickups</h3>
    <?php if ($authorizedPickups === []): ?>
    <p>None currently assigned.</p>
    <?php else: ?>
    <ul>
        <?php foreach ($authorizedPickups as $pickup): ?>
        <li><?= $escape($pickup->names) ?> — <?= $escape($pickup->mobilePhone) ?></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <p><a href="/representative/resources">Correct current addresses, emergency contacts or authorized pickups</a>.</p>
</section>

<section class="mb-4" aria-labelledby="annual-information-heading">
    <h2 id="annual-information-heading">Annual Enrollment Information</h2>
    <p>This information belongs to this Enrollment and becomes read-only after Submission.</p>
    <h3>Billing</h3>
    <?php if ($billing === null): ?>
    <p>Not supplied.</p>
    <?php else: ?>
    <dl>
        <dt>Identification number</dt><dd><?= $escape($billing->identificationNumber) ?></dd>
        <dt>Legal name</dt><dd><?= $escape($billing->legalName) ?></dd>
        <dt>Billing address</dt><dd><?= $escape($billing->billingAddress) ?></dd>
        <dt>Billing email</dt><dd><?= $escape($billing->billingEmail) ?></dd>
        <dt>Phone</dt><dd><?= $escape($billing->phone) ?></dd>
    </dl>
    <?php endif; ?>

    <h3>Medical</h3>
    <?php if ($medical === null): ?>
    <p>Not supplied.</p>
    <?php else: ?>
    <dl>
        <dt>Medical condition</dt><dd><?= $escape($yesNo($medical->hasMedicalCondition)) ?></dd>
        <dt>Medical condition detail</dt><dd><?= $escape($supplied($medical->medicalConditionDetail)) ?></dd>
        <dt>Allergies</dt><dd><?= $escape($yesNo($medical->hasAllergies)) ?></dd>
        <dt>Allergy detail</dt><dd><?= $escape($supplied($medical->allergyDetail)) ?></dd>
        <dt>Permanent medication</dt><dd><?= $escape($yesNo($medical->takesPermanentMedication)) ?></dd>
        <dt>Medication name</dt><dd><?= $escape($supplied($medical->medicationName)) ?></dd>
        <dt>Special care</dt><dd><?= $escape($yesNo($medical->requiresSpecialCare)) ?></dd>
        <dt>Special care detail</dt><dd><?= $escape($supplied($medical->specialCareDetail)) ?></dd>
        <dt>Medical insurance</dt><dd><?= $escape($yesNo($medical->hasMedicalInsurance)) ?></dd>
        <dt>Insurance provider</dt><dd><?= $escape($supplied($medical->insuranceProvider)) ?></dd>
        <dt>Pediatrician</dt><dd><?= $escape($supplied($medical->pediatricianName)) ?></dd>
        <dt>Pediatrician phone</dt><dd><?= $escape($supplied($medical->pediatricianPhone)) ?></dd>
        <dt>Observations</dt><dd><?= $escape($supplied($medical->observations)) ?></dd>
    </dl>
    <?php endif; ?>

    <h3>Transport and release</h3>
    <p>Requires institutional transport: <?= $escape($transport === null ? 'Not supplied' : $yesNo($transport->requiresInstitutionalTransport)) ?></p>
    <p>Authorized to leave alone: <?= $escape($yesNo($enrollment->isAuthorizedToLeaveAlone)) ?></p>
    <p><a href="<?= $escape($enrollmentLocation) ?>">Correct annual Enrollment information</a>.</p>
</section>

<section class="mb-4" aria-labelledby="acknowledgement-state-heading">
    <h2 id="acknowledgement-state-heading">Institutional Acknowledgements</h2>
    <p><?= $submissionReview->acknowledgementsSatisfied
        ? 'Complete for the current Academic Period.'
        : 'Pending for the current Academic Period.' ?></p>
    <?php if (!$submissionReview->acknowledgementsSatisfied): ?>
    <p><a href="/representative/acknowledgements">Review Institutional Acknowledgements</a>.</p>
    <?php endif; ?>
</section>

<section class="mb-4" aria-labelledby="submission-readiness-heading">
    <h2 id="submission-readiness-heading">Submission Readiness</h2>
    <?php if ($submissionReview->validation->isSubmittable): ?>
    <p class="alert alert-success" role="status">All current Submission requirements are satisfied.</p>
    <?php else: ?>
    <p class="alert alert-warning" role="status">This Enrollment cannot be submitted yet.</p>
    <ul>
        <?php foreach ($pendingRequirements as $requirement): ?>
        <li><?= $escape($requirement->message) ?></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <?php if ($submissionReview->validation->isSubmittable): ?>
    <p><strong>After Submission, annual Billing, Medical, Transport and leave-alone information becomes read-only until administration reopens the Enrollment.</strong></p>
    <p>Current Person, Representative, Student and Family information remains live and follows its existing authorization rules.</p>
    <form method="post" action="/representative/enrollment/submit">
        <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken ?? '') ?>">
        <input type="hidden" name="expected_family_id" value="<?= $escape($submissionReview->familyId) ?>">
        <input type="hidden" name="expected_academic_period_id" value="<?= $escape($period->id) ?>">
        <input type="hidden" name="student_id" value="<?= $escape($student->student->id) ?>">
        <button type="submit" class="btn btn-primary">
            <?= $enrollment->submittedAt === null ? 'Submit Enrollment' : 'Resubmit Enrollment' ?>
        </button>
    </form>
    <?php endif; ?>
</section>

<footer class="mt-4">
    <p><a href="<?= $escape($reviewLocation) ?>">Reload current review</a></p>
</footer>
</main>
