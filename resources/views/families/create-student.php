<?php

declare(strict_types=1);

use App\Student\Domain\StudentStatus;

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$selected = static fn (mixed $actual, mixed $expected): string => (string) $actual === (string) $expected
    ? ' selected'
    : '';
?>
<h1>Add Student to Family</h1>

<p>Family ID: <?= $escape($family->id) ?></p>
<p>Family display name: <?= $escape($family->displayName) ?></p>
<p>The new Student will be added to this Family.</p>

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

<form method="post" action="/families/students/create">
    <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken ?? '') ?>">
    <input type="hidden" name="family_id" value="<?= $escape($family->id) ?>">

    <fieldset>
        <legend>Person</legend>
        <div><label for="first-name">First name</label><input id="first-name" name="first_name" type="text" value="<?= $escape($values['first_name'] ?? '') ?>" required></div>
        <div><label for="middle-name">Middle name</label><input id="middle-name" name="middle_name" type="text" value="<?= $escape($values['middle_name'] ?? '') ?>"></div>
        <div><label for="first-surname">First surname</label><input id="first-surname" name="first_surname" type="text" value="<?= $escape($values['first_surname'] ?? '') ?>" required></div>
        <div><label for="second-surname">Second surname</label><input id="second-surname" name="second_surname" type="text" value="<?= $escape($values['second_surname'] ?? '') ?>"></div>
        <div>
            <label for="document-type">Document type</label>
            <select id="document-type" name="document_type_id">
                <option value="">None</option>
                <?php foreach ($personOptions->documentTypes as $option): ?>
                <option value="<?= $escape($option->id) ?>"<?= $selected($values['document_type_id'] ?? '', $option->id) ?>><?= $escape($option->name) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div><label for="document-number">Document number</label><input id="document-number" name="document_number" type="text" value="<?= $escape($values['document_number'] ?? '') ?>"></div>
        <div><label for="birth-date">Birth date</label><input id="birth-date" name="birth_date" type="date" value="<?= $escape($values['birth_date'] ?? '') ?>" required></div>
        <div>
            <label for="sex">Sex</label>
            <select id="sex" name="sex_id" required>
                <option value="">Select</option>
                <?php foreach ($personOptions->sexes as $option): ?>
                <option value="<?= $escape($option->id) ?>"<?= $selected($values['sex_id'] ?? '', $option->id) ?>><?= $escape($option->name) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="marital-status">Marital status</label>
            <select id="marital-status" name="marital_status_id">
                <option value="">None</option>
                <?php foreach ($personOptions->maritalStatuses as $option): ?>
                <option value="<?= $escape($option->id) ?>"<?= $selected($values['marital_status_id'] ?? '', $option->id) ?>><?= $escape($option->name) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="education-level">Education level</label>
            <select id="education-level" name="education_level_id">
                <option value="">None</option>
                <?php foreach ($personOptions->educationLevels as $option): ?>
                <option value="<?= $escape($option->id) ?>"<?= $selected($values['education_level_id'] ?? '', $option->id) ?>><?= $escape($option->name) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div><label for="email">Email</label><input id="email" name="email" type="email" value="<?= $escape($values['email'] ?? '') ?>"></div>
        <div><label for="mobile-phone">Mobile phone</label><input id="mobile-phone" name="mobile_phone" type="text" value="<?= $escape($values['mobile_phone'] ?? '') ?>"></div>
        <div><label for="landline-phone">Landline phone</label><input id="landline-phone" name="landline_phone" type="text" value="<?= $escape($values['landline_phone'] ?? '') ?>"></div>
        <div>
            <label for="person-status">Person status</label>
            <select id="person-status" name="person_status" required>
                <?php foreach ($personOptions->statuses as $option): ?>
                <option value="<?= $escape($option->code) ?>"<?= $selected($values['person_status'] ?? '', $option->code) ?>><?= $escape($option->name) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </fieldset>

    <fieldset>
        <legend>Student</legend>
        <div><label for="institutional-code">Institutional code</label><input id="institutional-code" name="institutional_code" type="text" value="<?= $escape($values['institutional_code'] ?? '') ?>" required></div>
        <div><label for="admission-date">Admission date</label><input id="admission-date" name="admission_date" type="date" value="<?= $escape($values['admission_date'] ?? '') ?>" required></div>
        <div>
            <label for="student-status">Student status</label>
            <select id="student-status" name="student_status" required>
                <?php foreach (StudentStatus::cases() as $status): ?>
                <option value="<?= $escape($status->value) ?>"<?= $selected($values['student_status'] ?? '', $status->value) ?>><?= $escape(ucfirst(strtolower($status->value))) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </fieldset>

    <fieldset>
        <legend>Membership</legend>
        <div><label for="started-at">Started at</label><input id="started-at" name="started_at" type="datetime-local" value="<?= $escape($values['started_at'] ?? '') ?>" required></div>
    </fieldset>

    <button type="submit"<?= ($canSubmit ?? false) ? '' : ' disabled' ?>>Add Student</button>
</form>

<p><a href="/families/show?id=<?= $escape($family->id) ?>">Cancel</a></p>
