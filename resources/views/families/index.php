<?php

declare(strict_types=1);

$families = isset($families) && is_array($families) ? $families : [];
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
<h1>Families</h1>

<p><a href="/families/create">Create family</a></p>

<table border="1" cellpadding="6" cellspacing="0">
    <thead>
        <tr>
            <th>Status</th>
            <th>Family Code</th>
            <th>Name</th>
            <th>Notes</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php if ($families === []): ?>
            <tr>
                <td colspan="5">No families found.</td>
            </tr>
        <?php endif; ?>

        <?php foreach ($families as $family): ?>
            <tr>
                <td><?= htmlspecialchars($catalogDescription($family['status_id'] ?? null, $statusById), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($family['family_code'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($family['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string) ($family['notes'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td>
                    <a href="/families/<?= (int) ($family['id'] ?? 0) ?>">View</a>
                    <a href="/families/<?= (int) ($family['id'] ?? 0) ?>/edit">Edit</a>
                    <form method="post" action="/families/<?= (int) ($family['id'] ?? 0) ?>/deactivate">
                        <button type="submit">Deactivate</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
