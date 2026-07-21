<?php

declare(strict_types=1);

$old = isset($old) && is_array($old) ? $old : [];
$errorMessage = isset($errorMessage) && is_string($errorMessage) ? $errorMessage : null;
$catalogs = isset($catalogs) && is_array($catalogs) ? $catalogs : [];
$statuses = isset($catalogs['statuses']) && is_array($catalogs['statuses']) ? $catalogs['statuses'] : [];
$documentTypes = isset($catalogs['documentTypes']) && is_array($catalogs['documentTypes']) ? $catalogs['documentTypes'] : [];
$genders = isset($catalogs['genders']) && is_array($catalogs['genders']) ? $catalogs['genders'] : [];
$nationalities = isset($catalogs['nationalities']) && is_array($catalogs['nationalities']) ? $catalogs['nationalities'] : [];

$isSelected = static function (mixed $left, mixed $right): bool {
    return (string) $left !== '' && (string) $left === (string) $right;
};
?>
<h1>Create person</h1>

<p><a href="/persons">Back to list</a></p>

<?php if ($errorMessage !== null && $errorMessage !== ''): ?>
<p><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<form method="post" action="/persons">
    <div>
        <label for="status_id">Estado</label>
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
        <label for="document_type_id">Tipo de documento</label>
        <select id="document_type_id" name="document_type_id" required>
            <option value="">Select document type</option>
            <?php foreach ($documentTypes as $documentType): ?>
                <?php $documentTypeId = $documentType['id'] ?? ''; ?>
                <?php $documentTypeDescription = (string) ($documentType['description'] ?? ''); ?>
                <option value="<?= htmlspecialchars((string) $documentTypeId, ENT_QUOTES, 'UTF-8') ?>" <?= $isSelected($old['document_type_id'] ?? null, $documentTypeId) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($documentTypeDescription, ENT_QUOTES, 'UTF-8') ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div>
        <label for="document_number">document_number</label>
        <input id="document_number" name="document_number" type="text" required value="<?= htmlspecialchars((string) ($old['document_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
    </div>

    <div>
        <label for="first_name">first_name</label>
        <input id="first_name" name="first_name" type="text" required value="<?= htmlspecialchars((string) ($old['first_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
    </div>

    <div>
        <label for="middle_name">middle_name</label>
        <input id="middle_name" name="middle_name" type="text" value="<?= htmlspecialchars((string) ($old['middle_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
    </div>

    <div>
        <label for="last_name">last_name</label>
        <input id="last_name" name="last_name" type="text" required value="<?= htmlspecialchars((string) ($old['last_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
    </div>

    <div>
        <label for="second_last_name">second_last_name</label>
        <input id="second_last_name" name="second_last_name" type="text" value="<?= htmlspecialchars((string) ($old['second_last_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
    </div>

    <div>
        <label for="preferred_name">preferred_name</label>
        <input id="preferred_name" name="preferred_name" type="text" value="<?= htmlspecialchars((string) ($old['preferred_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
    </div>

    <div>
        <label for="birth_date">birth_date</label>
        <input id="birth_date" name="birth_date" type="date" value="<?= htmlspecialchars((string) ($old['birth_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
    </div>

    <div>
        <label for="gender_id">Genero</label>
        <select id="gender_id" name="gender_id">
            <option value="">Select gender</option>
            <?php foreach ($genders as $gender): ?>
                <?php $genderId = $gender['id'] ?? ''; ?>
                <?php $genderDescription = (string) ($gender['description'] ?? ''); ?>
                <option value="<?= htmlspecialchars((string) $genderId, ENT_QUOTES, 'UTF-8') ?>" <?= $isSelected($old['gender_id'] ?? null, $genderId) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($genderDescription, ENT_QUOTES, 'UTF-8') ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div>
        <label for="nationality_id">Nacionalidad</label>
        <select id="nationality_id" name="nationality_id">
            <option value="">Select nationality</option>
            <?php foreach ($nationalities as $nationality): ?>
                <?php $nationalityId = $nationality['id'] ?? ''; ?>
                <?php $nationalityDescription = (string) ($nationality['description'] ?? ''); ?>
                <option value="<?= htmlspecialchars((string) $nationalityId, ENT_QUOTES, 'UTF-8') ?>" <?= $isSelected($old['nationality_id'] ?? null, $nationalityId) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($nationalityDescription, ENT_QUOTES, 'UTF-8') ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div>
        <label for="email">email</label>
        <input id="email" name="email" type="email" value="<?= htmlspecialchars((string) ($old['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
    </div>

    <div>
        <label for="mobile_phone">mobile_phone</label>
        <input id="mobile_phone" name="mobile_phone" type="text" value="<?= htmlspecialchars((string) ($old['mobile_phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
    </div>

    <div>
        <label for="home_phone">home_phone</label>
        <input id="home_phone" name="home_phone" type="text" value="<?= htmlspecialchars((string) ($old['home_phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
    </div>

    <div>
        <label for="address">address</label>
        <input id="address" name="address" type="text" value="<?= htmlspecialchars((string) ($old['address'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
    </div>

    <div>
        <label for="notes">notes</label>
        <textarea id="notes" name="notes"><?= htmlspecialchars((string) ($old['notes'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
    </div>

    <button type="submit">Save</button>
</form>
