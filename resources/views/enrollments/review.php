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
$supplied = static fn (?string $value): string => $value === null || $value === '' ? 'Not supplied' : $value;
$yesNo = static fn (bool $value): string => $value ? 'Yes' : 'No';
$formatInstant = static fn (?DateTimeImmutable $value): string =>
    $value?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s') ?? 'Not recorded';
$enrollment = $review->enrollment;
$resources = $review->currentFamilyResources;
$billing = $enrollment->billingInformation;
$medical = $enrollment->medicalInformation;
$transport = $enrollment->transportInformation;
?>
<main class="container py-3">
    <header class="mb-4">
        <h1>Administrative Enrollment Review</h1>
        <p><a href="/enrollments">Back to Submitted Enrollment queue</a></p>
    </header>

    <section class="mb-4" aria-labelledby="lifecycle-identity-heading">
        <h2 id="lifecycle-identity-heading">Enrollment identity and lifecycle</h2>
        <dl>
            <dt>Enrollment ID</dt><dd><?= $escape($enrollment->id) ?></dd>
            <dt>Status</dt><dd><strong><?= $escape($enrollment->status) ?></strong></dd>
            <dt>Academic Period</dt>
            <dd><?= $escape($review->academicPeriod->name) ?> (<?= $escape($review->academicPeriod->code) ?>, <?= $escape($review->academicPeriod->status) ?>)</dd>
            <dt>Started at (UTC)</dt><dd><?= $escape($formatInstant($enrollment->startedAt)) ?></dd>
            <dt>Submitted at (UTC)</dt><dd><?= $escape($formatInstant($enrollment->submittedAt)) ?></dd>
            <dt>Completed at (UTC)</dt><dd><?= $escape($formatInstant($enrollment->completedAt)) ?></dd>
            <dt>Cancelled at (UTC)</dt><dd><?= $escape($formatInstant($enrollment->cancelledAt)) ?></dd>
        </dl>
    </section>

    <section class="mb-4" aria-labelledby="current-sis-information-heading">
        <h2 id="current-sis-information-heading">Current SIS information</h2>
        <p>This section shows current live SIS information at the time of this review.</p>

        <h3>Current Student</h3>
        <dl>
            <dt>Name</dt><dd><?= $escape($name($review->studentPerson)) ?></dd>
            <dt>Institutional code</dt><dd><?= $escape($review->student->institutionalCode) ?></dd>
            <dt>Birth date</dt><dd><?= $escape($review->studentPerson->birthDate->format('Y-m-d')) ?></dd>
            <dt>Admission date</dt><dd><?= $escape($review->student->admissionDate->format('Y-m-d')) ?></dd>
            <dt>Status</dt><dd><?= $escape($review->student->status->value) ?></dd>
        </dl>

        <h3>Current Family</h3>
        <dl>
            <dt>Name</dt><dd><?= $escape($review->familyDisplayName) ?></dd>
            <dt>Status</dt><dd><?= $escape($review->familyStatus) ?></dd>
        </dl>

        <h3>Currently active Family Representatives</h3>
        <?php if ($review->currentRepresentatives === []): ?>
        <p>None currently active.</p>
        <?php else: ?>
        <?php foreach ($review->currentRepresentatives as $representative): ?>
        <article>
            <h4><?= $escape($name($representative->person)) ?><?= $representative->isPrimary ? ' — Primary' : '' ?></h4>
            <dl>
                <dt>Personal email</dt><dd><?= $escape($supplied($representative->person->email)) ?></dd>
                <dt>Mobile phone</dt><dd><?= $escape($supplied($representative->person->mobilePhone)) ?></dd>
                <dt>Occupation</dt><dd><?= $escape($supplied($representative->representative->occupation)) ?></dd>
                <dt>Work phone</dt><dd><?= $escape($supplied($representative->representative->workPhone)) ?></dd>
            </dl>
        </article>
        <?php endforeach; ?>
        <?php endif; ?>

        <h3>Current Student Address</h3>
        <?php if ($resources->studentAddress === null): ?>
        <p>None currently assigned.</p>
        <?php else: ?>
        <address>
            <strong><?= $escape($resources->studentAddress->label) ?></strong><br>
            <?= $escape($resources->studentAddress->mainStreet) ?>
            <?= $escape($resources->studentAddress->streetNumber ?? '') ?><br>
            <?= $escape($supplied($resources->studentAddress->sector)) ?><br>
            <?= $escape($supplied($resources->studentAddress->reference)) ?>
        </address>
        <?php endif; ?>

        <h3>Current Emergency Contacts assigned to this Student</h3>
        <?php if ($resources->emergencyContacts === []): ?>
        <p>None currently assigned.</p>
        <?php else: ?>
        <ul>
            <?php foreach ($resources->emergencyContacts as $contact): ?>
            <li><?= $escape($contact->names) ?> — <?= $escape($contact->mobilePhone) ?></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>

        <h3>Current Authorized Pickups assigned to this Student</h3>
        <?php if ($resources->authorizedPickups === []): ?>
        <p>None currently assigned.</p>
        <?php else: ?>
        <ul>
            <?php foreach ($resources->authorizedPickups as $pickup): ?>
            <li><?= $escape($pickup->names) ?> — <?= $escape($pickup->mobilePhone) ?></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </section>

    <section class="mb-4" aria-labelledby="annual-enrollment-information-heading">
        <h2 id="annual-enrollment-information-heading">Annual Enrollment information</h2>
        <p>This section is owned and preserved by the annual Enrollment.</p>

        <h3>Academic Placement</h3>
        <dl>
            <dt>Grade</dt><dd><?= $escape($review->grade?->name ?? 'Not assigned') ?></dd>
            <dt>Section</dt><dd><?= $escape($review->section?->name ?? 'Not assigned') ?></dd>
        </dl>

        <h3>Billing Information</h3>
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

        <h3>Medical Information</h3>
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
            <dt>Pediatrician name</dt><dd><?= $escape($supplied($medical->pediatricianName)) ?></dd>
            <dt>Pediatrician phone</dt><dd><?= $escape($supplied($medical->pediatricianPhone)) ?></dd>
            <dt>Observations</dt><dd><?= $escape($supplied($medical->observations)) ?></dd>
        </dl>
        <?php endif; ?>

        <h3>Transport and departure</h3>
        <dl>
            <dt>Institutional transport</dt><dd><?= $escape($transport === null ? 'Not supplied' : $yesNo($transport->requiresInstitutionalTransport)) ?></dd>
            <dt>Authorized to leave alone</dt><dd><?= $escape($yesNo($enrollment->isAuthorizedToLeaveAlone)) ?></dd>
        </dl>
    </section>

    <section class="mb-4" aria-labelledby="lifecycle-actions-heading">
        <h2 id="lifecycle-actions-heading">Administrative lifecycle actions</h2>
        <?php foreach ([
            '/enrollments/reopen' => 'Reopen Enrollment',
            '/enrollments/complete' => 'Complete Enrollment',
            '/enrollments/cancel' => 'Cancel Enrollment',
        ] as $action => $label): ?>
        <form method="post" action="<?= $escape($action) ?>">
            <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken ?? '') ?>">
            <input type="hidden" name="enrollment_id" value="<?= $escape($enrollment->id) ?>">
            <button type="submit"><?= $escape($label) ?></button>
        </form>
        <?php endforeach; ?>
    </section>
</main>
