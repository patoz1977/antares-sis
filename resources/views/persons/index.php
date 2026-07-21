<?php

declare(strict_types=1);

$persons = isset($persons) && is_array($persons) ? $persons : [];
?>
<h1>Persons</h1>

<p><a href="/persons/create">Create person</a></p>

<table border="1" cellpadding="6" cellspacing="0">
    <thead>
        <tr>
            <th>ID</th>
            <th>Status ID</th>
            <th>Document Type ID</th>
            <th>Document Number</th>
            <th>First Name</th>
            <th>Middle Name</th>
            <th>Last Name</th>
            <th>Second Last Name</th>
            <th>Preferred Name</th>
            <th>Birth Date</th>
            <th>Gender ID</th>
            <th>Nationality ID</th>
            <th>Email</th>
            <th>Mobile Phone</th>
            <th>Home Phone</th>
            <th>Address</th>
            <th>Notes</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php if ($persons === []): ?>
            <tr>
                <td colspan="18">No persons found.</td>
            </tr>
        <?php endif; ?>

        <?php foreach ($persons as $person): ?>
            <tr>
                <td><?= htmlspecialchars((string) ($person['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($person['status_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($person['document_type_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($person['document_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($person['first_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($person['middle_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($person['last_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($person['second_last_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($person['preferred_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($person['birth_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($person['gender_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($person['nationality_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($person['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($person['mobile_phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($person['home_phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($person['address'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($person['notes'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td>
                    <a href="/persons/<?= (int) ($person['id'] ?? 0) ?>">View</a>
                    <a href="/persons/<?= (int) ($person['id'] ?? 0) ?>/edit">Edit</a>
                    <form method="post" action="/persons/<?= (int) ($person['id'] ?? 0) ?>/deactivate">
                        <button type="submit">Deactivate</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
