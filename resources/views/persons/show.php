<?php

declare(strict_types=1);
?>
<h1>Person details</h1>

<p><a href="/persons">Back to list</a></p>

<?php if (!isset($person) || !is_array($person)): ?>
<p>Person not found.</p>
<?php return; ?>
<?php endif; ?>

<ul>
    <li>id: <?= htmlspecialchars((string) ($person['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></li>
    <li>status_id: <?= htmlspecialchars((string) ($person['status_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></li>
    <li>document_type_id: <?= htmlspecialchars((string) ($person['document_type_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></li>
    <li>document_number: <?= htmlspecialchars((string) ($person['document_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?></li>
    <li>first_name: <?= htmlspecialchars((string) ($person['first_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></li>
    <li>middle_name: <?= htmlspecialchars((string) ($person['middle_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></li>
    <li>last_name: <?= htmlspecialchars((string) ($person['last_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></li>
    <li>second_last_name: <?= htmlspecialchars((string) ($person['second_last_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></li>
    <li>preferred_name: <?= htmlspecialchars((string) ($person['preferred_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></li>
    <li>birth_date: <?= htmlspecialchars((string) ($person['birth_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?></li>
    <li>gender_id: <?= htmlspecialchars((string) ($person['gender_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></li>
    <li>nationality_id: <?= htmlspecialchars((string) ($person['nationality_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></li>
    <li>email: <?= htmlspecialchars((string) ($person['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?></li>
    <li>mobile_phone: <?= htmlspecialchars((string) ($person['mobile_phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?></li>
    <li>home_phone: <?= htmlspecialchars((string) ($person['home_phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?></li>
    <li>address: <?= htmlspecialchars((string) ($person['address'] ?? ''), ENT_QUOTES, 'UTF-8') ?></li>
    <li>notes: <?= htmlspecialchars((string) ($person['notes'] ?? ''), ENT_QUOTES, 'UTF-8') ?></li>
</ul>

<p><a href="/persons/<?= (int) ($person['id'] ?? 0) ?>/edit">Edit</a></p>

<form method="post" action="/persons/<?= (int) ($person['id'] ?? 0) ?>/deactivate">
    <button type="submit">Deactivate</button>
</form>
