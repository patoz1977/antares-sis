<?php

declare(strict_types=1);

use App\Person\Http\PersonFormOption;

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$selected = static fn (mixed $actual, mixed $expected): string => (string) $actual === (string) $expected
    ? ' selected'
    : '';
$isEdit = ($mode ?? '') === 'edit';
$action = $isEdit ? '/persons/update' : '/persons/create';
?>
<h1><?= $isEdit ? 'Edit Person' : 'Create Person' ?></h1>

<?php if (($errors ?? []) !== []): ?>
<div role="alert">
    <p>Please review the form:</p>
    <ul>
        <?php foreach ($errors as $error): ?>
        <li><?= $escape($error) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<form method="post" action="<?= $action ?>">
    <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken ?? '') ?>">
    <?php if ($isEdit): ?>
    <input type="hidden" name="id" value="<?= $escape($personId ?? '') ?>">
    <?php endif; ?>

    <div>
        <label for="first-name">First name</label>
        <input id="first-name" name="first_name" type="text" value="<?= $escape($values['first_name'] ?? '') ?>" required>
    </div>

    <div>
        <label for="middle-name">Middle name</label>
        <input id="middle-name" name="middle_name" type="text" value="<?= $escape($values['middle_name'] ?? '') ?>">
    </div>

    <div>
        <label for="first-surname">First surname</label>
        <input id="first-surname" name="first_surname" type="text" value="<?= $escape($values['first_surname'] ?? '') ?>" required>
    </div>

    <div>
        <label for="second-surname">Second surname</label>
        <input id="second-surname" name="second_surname" type="text" value="<?= $escape($values['second_surname'] ?? '') ?>">
    </div>

    <div>
        <label for="document-type">Document type</label>
        <select id="document-type" name="document_type_id">
            <option value="">None</option>
            <?php foreach ($options->documentTypes as $option): ?>
            <?php /** @var PersonFormOption $option */ ?>
            <option value="<?= $escape($option->id) ?>"<?= $selected($values['document_type_id'] ?? '', $option->id) ?>><?= $escape($option->name) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div>
        <label for="document-number">Document number</label>
        <input id="document-number" name="document_number" type="text" value="<?= $escape($values['document_number'] ?? '') ?>">
    </div>

    <div>
        <label for="birth-date">Birth date</label>
        <input id="birth-date" name="birth_date" type="date" value="<?= $escape($values['birth_date'] ?? '') ?>" required>
    </div>

    <div>
        <label for="sex">Sex</label>
        <select id="sex" name="sex_id" required>
            <option value="">Select</option>
            <?php foreach ($options->sexes as $option): ?>
            <option value="<?= $escape($option->id) ?>"<?= $selected($values['sex_id'] ?? '', $option->id) ?>><?= $escape($option->name) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div>
        <label for="marital-status">Marital status</label>
        <select id="marital-status" name="marital_status_id">
            <option value="">None</option>
            <?php foreach ($options->maritalStatuses as $option): ?>
            <option value="<?= $escape($option->id) ?>"<?= $selected($values['marital_status_id'] ?? '', $option->id) ?>><?= $escape($option->name) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div>
        <label for="education-level">Education level</label>
        <select id="education-level" name="education_level_id">
            <option value="">None</option>
            <?php foreach ($options->educationLevels as $option): ?>
            <option value="<?= $escape($option->id) ?>"<?= $selected($values['education_level_id'] ?? '', $option->id) ?>><?= $escape($option->name) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div>
        <label for="email">Email</label>
        <input id="email" name="email" type="email" value="<?= $escape($values['email'] ?? '') ?>">
    </div>

    <div>
        <label for="mobile-phone">Mobile phone</label>
        <input id="mobile-phone" name="mobile_phone" type="text" value="<?= $escape($values['mobile_phone'] ?? '') ?>">
    </div>

    <div>
        <label for="landline-phone">Landline phone</label>
        <input id="landline-phone" name="landline_phone" type="text" value="<?= $escape($values['landline_phone'] ?? '') ?>">
    </div>

    <div>
        <label for="status">Status</label>
        <select id="status" name="status" required>
            <?php foreach ($options->statuses as $option): ?>
            <option value="<?= $escape($option->code) ?>"<?= $selected($values['status'] ?? '', $option->code) ?>><?= $escape($option->name) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <button type="submit"<?= ($canSubmit ?? false) ? '' : ' disabled' ?>><?= $isEdit ? 'Update Person' : 'Create Person' ?></button>
</form>

<p><a href="<?= $isEdit ? '/persons/show?id=' . $escape($personId ?? '') : '/persons' ?>">Cancel</a></p>
