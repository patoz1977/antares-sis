<?php

declare(strict_types=1);

$catalogs = isset($catalogs) && is_array($catalogs) ? $catalogs : [];
$statuses = isset($catalogs['statuses']) && is_array($catalogs['statuses']) ? $catalogs['statuses'] : [];

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

$catalogDescription = static function (?int $id, array $map): string {
    if ($id === null) {
        return '';
    }

    return $map[$id] ?? '';
};
?>
<h1>Family details</h1>

<p><a href="/families">Back to list</a></p>

<?php if (!isset($family) || !is_array($family)): ?>
<p>Family not found.</p>
<?php return; ?>
<?php endif; ?>

<ul>
    <li>Status: <?= htmlspecialchars($catalogDescription($family['status_id'] ?? null, $statusById), ENT_QUOTES, 'UTF-8') ?></li>
    <li>Family Code: <?= htmlspecialchars((string) ($family['family_code'] ?? ''), ENT_QUOTES, 'UTF-8') ?></li>
    <li>Name: <?= htmlspecialchars((string) ($family['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></li>
    <li>Notes: <?= htmlspecialchars((string) ($family['notes'] ?? ''), ENT_QUOTES, 'UTF-8') ?></li>
</ul>

<p><a href="/families/<?= (int) ($family['id'] ?? 0) ?>/edit">Edit</a></p>

<form method="post" action="/families/<?= (int) ($family['id'] ?? 0) ?>/deactivate">
    <button type="submit">Deactivate</button>
</form>
