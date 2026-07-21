<?php

declare(strict_types=1);

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
<h1>Person details</h1>

<p><a href="/persons">Back to list</a></p>

<?php if (!isset($person) || !is_array($person)): ?>
<p>Person not found.</p>
<?php return; ?>
<?php endif; ?>

<ul>
    <li>Estado: <?= htmlspecialchars($catalogDescription($person['status_id'] ?? null, $statusById), ENT_QUOTES, 'UTF-8') ?></li>
    <li>Tipo de documento: <?= htmlspecialchars($catalogDescription($person['document_type_id'] ?? null, $documentTypeById), ENT_QUOTES, 'UTF-8') ?></li>
    <li>document_number: <?= htmlspecialchars((string) ($person['document_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?></li>
    <li>first_name: <?= htmlspecialchars((string) ($person['first_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></li>
    <li>middle_name: <?= htmlspecialchars((string) ($person['middle_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></li>
    <li>last_name: <?= htmlspecialchars((string) ($person['last_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></li>
    <li>second_last_name: <?= htmlspecialchars((string) ($person['second_last_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></li>
    <li>preferred_name: <?= htmlspecialchars((string) ($person['preferred_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></li>
    <li>birth_date: <?= htmlspecialchars((string) ($person['birth_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?></li>
    <li>Genero: <?= htmlspecialchars($catalogDescription($person['gender_id'] ?? null, $genderById), ENT_QUOTES, 'UTF-8') ?></li>
    <li>Nacionalidad: <?= htmlspecialchars($catalogDescription($person['nationality_id'] ?? null, $nationalityById), ENT_QUOTES, 'UTF-8') ?></li>
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
