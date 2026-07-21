<?php

declare(strict_types=1);

$errorMessage = isset($errorMessage) && is_string($errorMessage) ? $errorMessage : null;
?>
<h1>Edit person</h1>

<p><a href="/persons">Back to list</a></p>

<?php if (!isset($person) || !is_array($person)): ?>
<p>Person not found.</p>
<?php return; ?>
<?php endif; ?>

<?php if ($errorMessage !== null && $errorMessage !== ''): ?>
<p><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<form method="post" action="/persons/<?= (int) ($person['id'] ?? 0) ?>">
    <div>
        <label for="status_id">status_id</label>
        <input id="status_id" name="status_id" type="number" min="1" required value="<?= htmlspecialchars((string) ($person['status_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
    </div>

    <div>
        <label for="document_type_id">document_type_id</label>
        <input id="document_type_id" name="document_type_id" type="number" min="1" required value="<?= htmlspecialchars((string) ($person['document_type_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
    </div>

    <div>
        <label for="document_number">document_number</label>
        <input id="document_number" name="document_number" type="text" required value="<?= htmlspecialchars((string) ($person['document_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
    </div>

    <div>
        <label for="first_name">first_name</label>
        <input id="first_name" name="first_name" type="text" required value="<?= htmlspecialchars((string) ($person['first_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
    </div>

    <div>
        <label for="middle_name">middle_name</label>
        <input id="middle_name" name="middle_name" type="text" value="<?= htmlspecialchars((string) ($person['middle_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
    </div>

    <div>
        <label for="last_name">last_name</label>
        <input id="last_name" name="last_name" type="text" required value="<?= htmlspecialchars((string) ($person['last_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
    </div>

    <div>
        <label for="second_last_name">second_last_name</label>
        <input id="second_last_name" name="second_last_name" type="text" value="<?= htmlspecialchars((string) ($person['second_last_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
    </div>

    <div>
        <label for="preferred_name">preferred_name</label>
        <input id="preferred_name" name="preferred_name" type="text" value="<?= htmlspecialchars((string) ($person['preferred_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
    </div>

    <div>
        <label for="birth_date">birth_date</label>
        <input id="birth_date" name="birth_date" type="date" value="<?= htmlspecialchars((string) ($person['birth_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
    </div>

    <div>
        <label for="gender_id">gender_id</label>
        <input id="gender_id" name="gender_id" type="number" min="1" value="<?= htmlspecialchars((string) ($person['gender_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
    </div>

    <div>
        <label for="nationality_id">nationality_id</label>
        <input id="nationality_id" name="nationality_id" type="number" min="1" value="<?= htmlspecialchars((string) ($person['nationality_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
    </div>

    <div>
        <label for="email">email</label>
        <input id="email" name="email" type="email" value="<?= htmlspecialchars((string) ($person['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
    </div>

    <div>
        <label for="mobile_phone">mobile_phone</label>
        <input id="mobile_phone" name="mobile_phone" type="text" value="<?= htmlspecialchars((string) ($person['mobile_phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
    </div>

    <div>
        <label for="home_phone">home_phone</label>
        <input id="home_phone" name="home_phone" type="text" value="<?= htmlspecialchars((string) ($person['home_phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
    </div>

    <div>
        <label for="address">address</label>
        <input id="address" name="address" type="text" value="<?= htmlspecialchars((string) ($person['address'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
    </div>

    <div>
        <label for="notes">notes</label>
        <textarea id="notes" name="notes"><?= htmlspecialchars((string) ($person['notes'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
    </div>

    <button type="submit">Update</button>
</form>

<form method="post" action="/persons/<?= (int) ($person['id'] ?? 0) ?>/deactivate">
    <button type="submit">Deactivate</button>
</form>
