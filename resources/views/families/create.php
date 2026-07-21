<?php

declare(strict_types=1);

$old = isset($old) && is_array($old) ? $old : [];
$errorMessage = isset($errorMessage) && is_string($errorMessage) ? $errorMessage : null;
$catalogs = isset($catalogs) && is_array($catalogs) ? $catalogs : [];
$statuses = isset($catalogs['statuses']) && is_array($catalogs['statuses']) ? $catalogs['statuses'] : [];

$isSelected = static function (mixed $left, mixed $right): bool {
    return (string) $left !== '' && (string) $left === (string) $right;
};
?>
<h1>Create family</h1>

<p><a href="/families">Back to list</a></p>

<?php if ($errorMessage !== null && $errorMessage !== ''): ?>
<p><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<form method="post" action="/families">
    <div>
        <label for="status_id">Status</label>
        <select id="status_id" name="status_id" required>
            <option value="">Select status</option>
            <?php foreach ($statuses as $status): ?>
                <?php $statusId = $status['id'] ?? ''; ?>
                <?php $statusDescription = (string) ($status['description'] ?? ''); ?>
                <option value="<?= htmlspecialchars((string) $statusId, ENT_QUOTES, 'UTF-8') ?>" <?= $isSelected($old['status_id'] ?? null, $statusId) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($statusDescription, ENT_QUOTES, 'UTF-8') ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div>
        <label for="family_code">Family Code</label>
        <input id="family_code" name="family_code" type="text" maxlength="30" required value="<?= htmlspecialchars((string) ($old['family_code'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
    </div>

    <div>
        <label for="name">Name</label>
        <input id="name" name="name" type="text" maxlength="150" value="<?= htmlspecialchars((string) ($old['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
    </div>

    <div>
        <label for="notes">Notes</label>
        <textarea id="notes" name="notes"><?= htmlspecialchars((string) ($old['notes'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
    </div>

    <button type="submit">Save</button>
</form>
