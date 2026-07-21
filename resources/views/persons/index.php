<?php

declare(strict_types=1);

$persons = isset($persons) && is_array($persons) ? $persons : [];
$catalogs = isset($catalogs) && is_array($catalogs) ? $catalogs : [];
$statuses = isset($catalogs['statuses']) && is_array($catalogs['statuses']) ? $catalogs['statuses'] : [];
$documentTypes = isset($catalogs['documentTypes']) && is_array($catalogs['documentTypes']) ? $catalogs['documentTypes'] : [];
$genders = isset($catalogs['genders']) && is_array($catalogs['genders']) ? $catalogs['genders'] : [];
$nationalities = isset($catalogs['nationalities']) && is_array($catalogs['nationalities']) ? $catalogs['nationalities'] : [];

$toCatalogMap = static function (array $rows): array {
    $map = [];

    foreach ($rows as $row) {
        $id = $row['id'] ?? null;

        if (!is_numeric($id)) {
            continue;
        }

        $map[(int) $id] = (string) ($row['description'] ?? '');
    }

    return $map;
};

$statusById = $toCatalogMap($statuses);
$documentTypeById = $toCatalogMap($documentTypes);
$genderById = $toCatalogMap($genders);
$nationalityById = $toCatalogMap($nationalities);

$catalogDescription = static function (?int $id, array $map): string {
    if ($id === null) {
        return '';
    }

    return $map[$id] ?? '';
};
?>
<h1>Persons</h1>

<p><a href="/persons/create">Create person</a></p>

<table border="1" cellpadding="6" cellspacing="0">
    <thead>
        <tr>
            <th>Estado</th>
            <th>Tipo de documento</th>
            <th>Document Number</th>
            <th>First Name</th>
            <th>Middle Name</th>
            <th>Last Name</th>
            <th>Second Last Name</th>
            <th>Preferred Name</th>
            <th>Birth Date</th>
            <th>Genero</th>
            <th>Nacionalidad</th>
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
                <td colspan="17">No persons found.</td>
            </tr>
        <?php endif; ?>

        <?php foreach ($persons as $person): ?>
            <tr>
                <td><?= htmlspecialchars($catalogDescription($person['status_id'] ?? null, $statusById), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($catalogDescription($person['document_type_id'] ?? null, $documentTypeById), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($person['document_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($person['first_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($person['middle_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($person['last_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($person['second_last_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($person['preferred_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($person['birth_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($catalogDescription($person['gender_id'] ?? null, $genderById), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($catalogDescription($person['nationality_id'] ?? null, $nationalityById), ENT_QUOTES, 'UTF-8') ?></td>
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
