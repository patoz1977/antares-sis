<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$value = static fn (string $key, mixed $fallback = ''): mixed => array_key_exists($key, $values) ? $values[$key] : $fallback;
$timestamp = static fn (DateTimeImmutable $date): string => $date->format(DateTimeImmutable::ATOM);
$activeRepresentatives = array_values(array_filter($family->representatives, static fn ($item): bool => $item->isActive));
$activeStudents = array_values(array_filter($family->students, static fn ($item): bool => $item->isActive));
$activeAddresses = array_values(array_filter($resources->addresses, static fn ($item): bool => $item->status === 'ACTIVE'));
$activeContacts = array_values(array_filter($resources->emergencyContacts, static fn ($item): bool => $item->status === 'ACTIVE'));
$activePickups = array_values(array_filter($resources->authorizedPickups, static fn ($item): bool => $item->status === 'ACTIVE'));
?>
<h1>Family resources</h1>

<dl>
    <dt>Family</dt><dd><?= $escape($resources->displayName) ?></dd>
    <dt>Family ID</dt><dd><?= $escape($resources->familyId) ?></dd>
    <dt>Status</dt><dd><?= $escape($resources->status) ?></dd>
</dl>

<?php if (is_string($successMessage ?? null) && $successMessage !== ''): ?>
<p><?= $escape($successMessage) ?></p>
<?php endif; ?>
<?php if (is_string($errorMessage ?? null) && $errorMessage !== ''): ?>
<p role="alert"><?= $escape($errorMessage) ?></p>
<?php endif; ?>
<?php if ($errors !== []): ?>
<div role="alert">
    <p>Review the submitted information.</p>
    <ul>
    <?php foreach ($errors as $error): ?>
        <li><?= $escape($error) ?></li>
    <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<p><a href="/families/show?id=<?= $escape($resources->familyId) ?>">Back to Family</a></p>
<p><a href="/families">Back to Families</a></p>

<section aria-labelledby="addresses-heading">
<h2 id="addresses-heading">Addresses</h2>

<h3>Create Address</h3>
<form method="post" action="/families/resources/addresses/create">
    <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
    <input type="hidden" name="family_id" value="<?= $escape($resources->familyId) ?>">
    <label>Label <input name="label" value="<?= $escape($value('label')) ?>" required></label>
    <label>Main street <input name="main_street" value="<?= $escape($value('main_street')) ?>" required></label>
    <label>Street number <input name="street_number" value="<?= $escape($value('street_number')) ?>"></label>
    <label>Secondary street <input name="secondary_street" value="<?= $escape($value('secondary_street')) ?>"></label>
    <label>Sector <input name="sector" value="<?= $escape($value('sector')) ?>"></label>
    <label>Reference <input name="reference" value="<?= $escape($value('reference')) ?>"></label>
    <label>Latitude <input name="latitude" inputmode="decimal" value="<?= $escape($value('latitude')) ?>"></label>
    <label>Longitude <input name="longitude" inputmode="decimal" value="<?= $escape($value('longitude')) ?>"></label>
    <button type="submit">Create Address</button>
</form>

<?php if ($resources->addresses === []): ?>
<p>No Addresses registered.</p>
<?php else: ?>
<?php foreach ($resources->addresses as $address): ?>
<article>
    <h3><?= $escape($address->label) ?></h3>
    <dl>
        <dt>Address ID</dt><dd><?= $escape($address->id) ?></dd>
        <dt>Main street</dt><dd><?= $escape($address->mainStreet) ?></dd>
        <dt>Street number</dt><dd><?= $escape($address->streetNumber ?? 'Not supplied') ?></dd>
        <dt>Secondary street</dt><dd><?= $escape($address->secondaryStreet ?? 'Not supplied') ?></dd>
        <dt>Sector</dt><dd><?= $escape($address->sector ?? 'Not supplied') ?></dd>
        <dt>Reference</dt><dd><?= $escape($address->reference ?? 'Not supplied') ?></dd>
        <dt>Latitude</dt><dd><?= $escape($address->latitude ?? 'Not supplied') ?></dd>
        <dt>Longitude</dt><dd><?= $escape($address->longitude ?? 'Not supplied') ?></dd>
        <dt>Status</dt><dd><?= $escape($address->status) ?></dd>
    </dl>
    <form method="post" action="/families/resources/addresses/update">
        <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
        <input type="hidden" name="family_id" value="<?= $escape($resources->familyId) ?>">
        <input type="hidden" name="family_address_id" value="<?= $escape($address->id) ?>">
        <label>Label <input name="label" value="<?= $escape($address->label) ?>" required></label>
        <label>Main street <input name="main_street" value="<?= $escape($address->mainStreet) ?>" required></label>
        <label>Street number <input name="street_number" value="<?= $escape($address->streetNumber) ?>"></label>
        <label>Secondary street <input name="secondary_street" value="<?= $escape($address->secondaryStreet) ?>"></label>
        <label>Sector <input name="sector" value="<?= $escape($address->sector) ?>"></label>
        <label>Reference <input name="reference" value="<?= $escape($address->reference) ?>"></label>
        <label>Latitude <input name="latitude" inputmode="decimal" value="<?= $escape($address->latitude) ?>"></label>
        <label>Longitude <input name="longitude" inputmode="decimal" value="<?= $escape($address->longitude) ?>"></label>
        <button type="submit">Update Address</button>
    </form>
    <form method="post" action="/families/resources/addresses/<?= $address->status === 'ACTIVE' ? 'deactivate' : 'activate' ?>">
        <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
        <input type="hidden" name="family_id" value="<?= $escape($resources->familyId) ?>">
        <input type="hidden" name="family_address_id" value="<?= $escape($address->id) ?>">
        <button type="submit"><?= $address->status === 'ACTIVE' ? 'Deactivate' : 'Activate' ?> Address</button>
    </form>
</article>
<?php endforeach; ?>
<?php endif; ?>

<h3>Assign Address to Representative</h3>
<form method="post" action="/families/resources/representatives/address">
    <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
    <input type="hidden" name="family_id" value="<?= $escape($resources->familyId) ?>">
    <label>Representative
        <select name="representative_id" required>
            <?php foreach ($activeRepresentatives as $membership): ?>
            <option value="<?= $escape($membership->representativeId) ?>">Representative <?= $escape($membership->representativeId) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Address
        <select name="family_address_id" required>
            <?php foreach ($activeAddresses as $address): ?>
            <option value="<?= $escape($address->id) ?>"><?= $escape($address->label) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Started at <input name="started_at" type="datetime-local" required></label>
    <button type="submit"<?= $activeRepresentatives === [] || $activeAddresses === [] ? ' disabled' : '' ?>>Assign Representative Address</button>
</form>

<h3>Assign Address to Student</h3>
<form method="post" action="/families/resources/students/address">
    <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
    <input type="hidden" name="family_id" value="<?= $escape($resources->familyId) ?>">
    <label>Student
        <select name="student_id" required>
            <?php foreach ($activeStudents as $membership): ?>
            <option value="<?= $escape($membership->studentId) ?>">Student <?= $escape($membership->studentId) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Address
        <select name="family_address_id" required>
            <?php foreach ($activeAddresses as $address): ?>
            <option value="<?= $escape($address->id) ?>"><?= $escape($address->label) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Started at <input name="started_at" type="datetime-local" required></label>
    <button type="submit"<?= $activeStudents === [] || $activeAddresses === [] ? ' disabled' : '' ?>>Assign Student Address</button>
</form>

<h3>Address assignment history</h3>
<?php foreach ($resources->representativeAddressAssignments as $assignment): ?>
<article>
    <p>Representative <?= $escape($assignment->representativeId) ?> — Address <?= $escape($assignment->familyAddressId) ?> — <?= $assignment->isActive ? 'Active' : 'History' ?></p>
    <p><?= $escape($timestamp($assignment->startedAt)) ?> to <?= $assignment->endedAt === null ? 'Not ended' : $escape($timestamp($assignment->endedAt)) ?></p>
    <?php if ($assignment->isActive): ?>
    <form method="post" action="/families/resources/representatives/address/end">
        <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
        <input type="hidden" name="family_id" value="<?= $escape($resources->familyId) ?>">
        <input type="hidden" name="assignment_id" value="<?= $escape($assignment->id) ?>">
        <label>Ended at <input name="ended_at" type="datetime-local" required></label>
        <button type="submit">End Representative Address</button>
    </form>
    <?php endif; ?>
</article>
<?php endforeach; ?>
<?php foreach ($resources->studentAddressAssignments as $assignment): ?>
<article>
    <p>Student <?= $escape($assignment->studentId) ?> — Address <?= $escape($assignment->familyAddressId) ?> — <?= $assignment->isActive ? 'Active' : 'History' ?></p>
    <p><?= $escape($timestamp($assignment->startedAt)) ?> to <?= $assignment->endedAt === null ? 'Not ended' : $escape($timestamp($assignment->endedAt)) ?></p>
    <?php if ($assignment->isActive): ?>
    <form method="post" action="/families/resources/students/address/end">
        <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
        <input type="hidden" name="family_id" value="<?= $escape($resources->familyId) ?>">
        <input type="hidden" name="assignment_id" value="<?= $escape($assignment->id) ?>">
        <label>Ended at <input name="ended_at" type="datetime-local" required></label>
        <button type="submit">End Student Address</button>
    </form>
    <?php endif; ?>
</article>
<?php endforeach; ?>
</section>

<section aria-labelledby="emergency-heading">
<h2 id="emergency-heading">Emergency Contacts</h2>
<?php if ($options->relationshipTypes === []): ?>
<p role="alert">Active relationship types are unavailable. Emergency Contact and Authorized Pickup forms are disabled.</p>
<?php endif; ?>
<h3>Create Emergency Contact</h3>
<form method="post" action="/families/resources/emergency-contacts/create">
    <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
    <input type="hidden" name="family_id" value="<?= $escape($resources->familyId) ?>">
    <label>Names <input name="names" value="<?= $escape($value('names')) ?>" required></label>
    <label>Relationship type
        <select name="relationship_type_id" required>
            <?php foreach ($options->relationshipTypes as $option): ?>
            <option value="<?= $escape($option->id) ?>"><?= $escape($option->name) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Mobile phone <input name="mobile_phone" value="<?= $escape($value('mobile_phone')) ?>" required></label>
    <label>Phone <input name="phone" value="<?= $escape($value('phone')) ?>"></label>
    <label>Email <input name="email" type="email" value="<?= $escape($value('email')) ?>"></label>
    <label>Observations <textarea name="observations"><?= $escape($value('observations')) ?></textarea></label>
    <button type="submit"<?= $options->relationshipTypes === [] ? ' disabled' : '' ?>>Create Emergency Contact</button>
</form>

<?php foreach ($resources->emergencyContacts as $contact): ?>
<article>
    <h3><?= $escape($contact->names) ?></h3>
    <dl>
        <dt>Emergency Contact ID</dt><dd><?= $escape($contact->id) ?></dd>
        <dt>Relationship type ID</dt><dd><?= $escape($contact->relationshipTypeId) ?></dd>
        <dt>Mobile phone</dt><dd><?= $escape($contact->mobilePhone) ?></dd>
        <dt>Phone</dt><dd><?= $escape($contact->phone ?? 'Not supplied') ?></dd>
        <dt>Email</dt><dd><?= $escape($contact->email ?? 'Not supplied') ?></dd>
        <dt>Observations</dt><dd><?= $escape($contact->observations ?? 'Not supplied') ?></dd>
        <dt>Status</dt><dd><?= $escape($contact->status) ?></dd>
    </dl>
    <form method="post" action="/families/resources/emergency-contacts/update">
        <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
        <input type="hidden" name="family_id" value="<?= $escape($resources->familyId) ?>">
        <input type="hidden" name="family_emergency_contact_id" value="<?= $escape($contact->id) ?>">
        <label>Names <input name="names" value="<?= $escape($contact->names) ?>" required></label>
        <label>Relationship type
            <select name="relationship_type_id" required>
                <?php foreach ($options->relationshipTypes as $option): ?>
                <option value="<?= $escape($option->id) ?>"<?= $option->id === $contact->relationshipTypeId ? ' selected' : '' ?>><?= $escape($option->name) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Mobile phone <input name="mobile_phone" value="<?= $escape($contact->mobilePhone) ?>" required></label>
        <label>Phone <input name="phone" value="<?= $escape($contact->phone) ?>"></label>
        <label>Email <input name="email" type="email" value="<?= $escape($contact->email) ?>"></label>
        <label>Observations <textarea name="observations"><?= $escape($contact->observations) ?></textarea></label>
        <button type="submit"<?= $options->relationshipTypes === [] ? ' disabled' : '' ?>>Update Emergency Contact</button>
    </form>
    <form method="post" action="/families/resources/emergency-contacts/<?= $contact->status === 'ACTIVE' ? 'deactivate' : 'activate' ?>">
        <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
        <input type="hidden" name="family_id" value="<?= $escape($resources->familyId) ?>">
        <input type="hidden" name="family_emergency_contact_id" value="<?= $escape($contact->id) ?>">
        <button type="submit"><?= $contact->status === 'ACTIVE' ? 'Deactivate' : 'Activate' ?> Emergency Contact</button>
    </form>
</article>
<?php endforeach; ?>

<h3>Assign Emergency Contact</h3>
<form method="post" action="/families/resources/emergency-contacts/assign">
    <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
    <input type="hidden" name="family_id" value="<?= $escape($resources->familyId) ?>">
    <label>Emergency Contact
        <select name="family_emergency_contact_id" required>
            <?php foreach ($activeContacts as $contact): ?>
            <option value="<?= $escape($contact->id) ?>"><?= $escape($contact->names) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Student
        <select name="student_id" required>
            <?php foreach ($activeStudents as $membership): ?>
            <option value="<?= $escape($membership->studentId) ?>">Student <?= $escape($membership->studentId) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Priority <input name="priority" type="number" min="1"></label>
    <label>Started at <input name="started_at" type="datetime-local" required></label>
    <button type="submit"<?= $activeContacts === [] || $activeStudents === [] ? ' disabled' : '' ?>>Assign Emergency Contact</button>
</form>

<h3>Emergency Contact assignment history</h3>
<?php foreach ($resources->emergencyContactAssignments as $assignment): ?>
<article>
    <p>Emergency Contact <?= $escape($assignment->familyEmergencyContactId) ?> — Student <?= $escape($assignment->studentId) ?> — Priority <?= $escape($assignment->priority ?? 'Not supplied') ?> — <?= $assignment->isActive ? 'Active' : 'History' ?></p>
    <p><?= $escape($timestamp($assignment->startedAt)) ?> to <?= $assignment->endedAt === null ? 'Not ended' : $escape($timestamp($assignment->endedAt)) ?></p>
    <?php if ($assignment->isActive): ?>
    <form method="post" action="/families/resources/emergency-contacts/end">
        <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
        <input type="hidden" name="family_id" value="<?= $escape($resources->familyId) ?>">
        <input type="hidden" name="assignment_id" value="<?= $escape($assignment->id) ?>">
        <label>Ended at <input name="ended_at" type="datetime-local" required></label>
        <button type="submit">End Emergency Contact assignment</button>
    </form>
    <?php endif; ?>
</article>
<?php endforeach; ?>
</section>

<section aria-labelledby="pickups-heading">
<h2 id="pickups-heading">Authorized Pickups</h2>
<h3>Create Authorized Pickup</h3>
<form method="post" action="/families/resources/authorized-pickups/create">
    <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
    <input type="hidden" name="family_id" value="<?= $escape($resources->familyId) ?>">
    <label>Names <input name="names" value="<?= $escape($value('names')) ?>" required></label>
    <label>Relationship type
        <select name="relationship_type_id" required>
            <?php foreach ($options->relationshipTypes as $option): ?>
            <option value="<?= $escape($option->id) ?>"<?= (string) $option->id === (string) $value('relationship_type_id') ? ' selected' : '' ?>><?= $escape($option->name) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Mobile phone <input name="mobile_phone" value="<?= $escape($value('mobile_phone')) ?>" required></label>
    <label>Phone <input name="phone" value="<?= $escape($value('phone')) ?>"></label>
    <label>Document type (optional)
        <select name="document_type_id">
            <option value="">No identification</option>
            <?php foreach ($options->documentTypes as $option): ?>
            <option value="<?= $escape($option->id) ?>"<?= (string) $option->id === (string) $value('document_type_id') ? ' selected' : '' ?>><?= $escape($option->name) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Document number (optional) <input name="document_number" value="<?= $escape($value('document_number')) ?>"></label>
    <label>Observations <textarea name="observations"><?= $escape($value('observations')) ?></textarea></label>
    <button type="submit"<?= $options->relationshipTypes === [] ? ' disabled' : '' ?>>Create Authorized Pickup</button>
</form>
<?php if ($options->documentTypes === []): ?>
<p>No active document types are available. Authorized Pickups may still be saved without identification.</p>
<?php endif; ?>

<?php foreach ($resources->authorizedPickups as $pickup): ?>
<article>
    <h3><?= $escape($pickup->names) ?></h3>
    <dl>
        <dt>Authorized Pickup ID</dt><dd><?= $escape($pickup->id) ?></dd>
        <dt>Relationship type ID</dt><dd><?= $escape($pickup->relationshipTypeId) ?></dd>
        <dt>Mobile phone</dt><dd><?= $escape($pickup->mobilePhone) ?></dd>
        <dt>Phone</dt><dd><?= $escape($pickup->phone ?? 'Not supplied') ?></dd>
        <dt>Document type ID</dt><dd><?= $escape($pickup->documentTypeId ?? 'Not supplied') ?></dd>
        <dt>Document number</dt><dd><?= $escape($pickup->documentNumber ?? 'Not supplied') ?></dd>
        <dt>Observations</dt><dd><?= $escape($pickup->observations ?? 'Not supplied') ?></dd>
        <dt>Status</dt><dd><?= $escape($pickup->status) ?></dd>
    </dl>
    <form method="post" action="/families/resources/authorized-pickups/update">
        <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
        <input type="hidden" name="family_id" value="<?= $escape($resources->familyId) ?>">
        <input type="hidden" name="family_authorized_pickup_id" value="<?= $escape($pickup->id) ?>">
        <label>Names <input name="names" value="<?= $escape($pickup->names) ?>" required></label>
        <label>Relationship type
            <select name="relationship_type_id" required>
                <?php foreach ($options->relationshipTypes as $option): ?>
                <option value="<?= $escape($option->id) ?>"<?= $option->id === $pickup->relationshipTypeId ? ' selected' : '' ?>><?= $escape($option->name) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Mobile phone <input name="mobile_phone" value="<?= $escape($pickup->mobilePhone) ?>" required></label>
        <label>Phone <input name="phone" value="<?= $escape($pickup->phone) ?>"></label>
        <label>Document type
            <select name="document_type_id">
                <option value="">No identification</option>
                <?php foreach ($options->documentTypes as $option): ?>
                <option value="<?= $escape($option->id) ?>"<?= $option->id === $pickup->documentTypeId ? ' selected' : '' ?>><?= $escape($option->name) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Document number <input name="document_number" value="<?= $escape($pickup->documentNumber) ?>"></label>
        <label>Observations <textarea name="observations"><?= $escape($pickup->observations) ?></textarea></label>
        <button type="submit"<?= $options->relationshipTypes === [] ? ' disabled' : '' ?>>Update Authorized Pickup</button>
    </form>
    <form method="post" action="/families/resources/authorized-pickups/<?= $pickup->status === 'ACTIVE' ? 'deactivate' : 'activate' ?>">
        <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
        <input type="hidden" name="family_id" value="<?= $escape($resources->familyId) ?>">
        <input type="hidden" name="family_authorized_pickup_id" value="<?= $escape($pickup->id) ?>">
        <button type="submit"><?= $pickup->status === 'ACTIVE' ? 'Deactivate' : 'Activate' ?> Authorized Pickup</button>
    </form>
</article>
<?php endforeach; ?>

<h3>Assign Authorized Pickup</h3>
<form method="post" action="/families/resources/authorized-pickups/assign">
    <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
    <input type="hidden" name="family_id" value="<?= $escape($resources->familyId) ?>">
    <label>Authorized Pickup
        <select name="family_authorized_pickup_id" required>
            <?php foreach ($activePickups as $pickup): ?>
            <option value="<?= $escape($pickup->id) ?>"><?= $escape($pickup->names) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Student
        <select name="student_id" required>
            <?php foreach ($activeStudents as $membership): ?>
            <option value="<?= $escape($membership->studentId) ?>">Student <?= $escape($membership->studentId) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Started at <input name="started_at" type="datetime-local" required></label>
    <button type="submit"<?= $activePickups === [] || $activeStudents === [] ? ' disabled' : '' ?>>Assign Authorized Pickup</button>
</form>

<h3>Authorized Pickup assignment history</h3>
<?php foreach ($resources->authorizedPickupAssignments as $assignment): ?>
<article>
    <p>Authorized Pickup <?= $escape($assignment->familyAuthorizedPickupId) ?> — Student <?= $escape($assignment->studentId) ?> — <?= $assignment->isActive ? 'Active' : 'History' ?></p>
    <p><?= $escape($timestamp($assignment->startedAt)) ?> to <?= $assignment->endedAt === null ? 'Not ended' : $escape($timestamp($assignment->endedAt)) ?></p>
    <?php if ($assignment->isActive): ?>
    <form method="post" action="/families/resources/authorized-pickups/end">
        <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
        <input type="hidden" name="family_id" value="<?= $escape($resources->familyId) ?>">
        <input type="hidden" name="assignment_id" value="<?= $escape($assignment->id) ?>">
        <label>Ended at <input name="ended_at" type="datetime-local" required></label>
        <button type="submit">End Authorized Pickup assignment</button>
    </form>
    <?php endif; ?>
</article>
<?php endforeach; ?>
</section>

<p><a href="/families/show?id=<?= $escape($resources->familyId) ?>">Back to Family</a></p>
<p><a href="/families">Back to Families</a></p>
