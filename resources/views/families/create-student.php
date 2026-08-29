<?php

declare(strict_types=1);

use App\Student\Domain\StudentStatus;

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$selected = static fn (mixed $actual, mixed $expected): string => (string) $actual === (string) $expected
    ? ' selected'
    : '';
?>
<header class="app-page-header">
    <p class="text-uppercase fw-semibold text-primary mb-2">Familia <?= $escape($family->displayName) ?></p>
    <h1 class="display-6 fw-bold mb-2">Agregar estudiante</h1>
    <p class="text-body-secondary mb-0">El nuevo estudiante se asociará a esta familia. ID interno: <?= $escape($family->id) ?>.</p>
</header>

<?php require dirname(__DIR__) . '/components/validation-summary.php'; ?>

<form class="app-content-narrow" method="post" action="/families/students/create">
    <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken ?? '') ?>">
    <input type="hidden" name="family_id" value="<?= $escape($family->id) ?>">

    <fieldset class="app-form-section">
        <legend>Datos personales</legend>
        <div><label class="form-label" for="first-name">Primer nombre</label><input class="form-control" id="first-name" name="first_name" type="text" value="<?= $escape($values['first_name'] ?? '') ?>" required></div>
        <div><label class="form-label" for="middle-name">Segundo nombre</label><input class="form-control" id="middle-name" name="middle_name" type="text" value="<?= $escape($values['middle_name'] ?? '') ?>"></div>
        <div><label class="form-label" for="first-surname">Primer apellido</label><input class="form-control" id="first-surname" name="first_surname" type="text" value="<?= $escape($values['first_surname'] ?? '') ?>" required></div>
        <div><label class="form-label" for="second-surname">Segundo apellido</label><input class="form-control" id="second-surname" name="second_surname" type="text" value="<?= $escape($values['second_surname'] ?? '') ?>"></div>
        <div>
            <label class="form-label" for="document-type">Tipo de documento</label>
            <select class="form-select" id="document-type" name="document_type_id">
                <option value="">Sin documento</option>
                <?php foreach ($personOptions->documentTypes as $option): ?>
                <option value="<?= $escape($option->id) ?>"<?= $selected($values['document_type_id'] ?? '', $option->id) ?>><?= $escape($option->name) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div><label class="form-label" for="document-number">Número de documento</label><input class="form-control" id="document-number" name="document_number" type="text" value="<?= $escape($values['document_number'] ?? '') ?>"></div>
        <div><label class="form-label" for="birth-date">Fecha de nacimiento</label><input class="form-control" id="birth-date" name="birth_date" type="date" value="<?= $escape($values['birth_date'] ?? '') ?>" required></div>
        <div>
            <label class="form-label" for="sex">Sexo</label>
            <select class="form-select" id="sex" name="sex_id" required>
                <option value="">Selecciona una opción</option>
                <?php foreach ($personOptions->sexes as $option): ?>
                <option value="<?= $escape($option->id) ?>"<?= $selected($values['sex_id'] ?? '', $option->id) ?>><?= $escape($option->name) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="form-label" for="marital-status">Estado civil</label>
            <select class="form-select" id="marital-status" name="marital_status_id">
                <option value="">No registrado</option>
                <?php foreach ($personOptions->maritalStatuses as $option): ?>
                <option value="<?= $escape($option->id) ?>"<?= $selected($values['marital_status_id'] ?? '', $option->id) ?>><?= $escape($option->name) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="form-label" for="education-level">Nivel educativo</label>
            <select class="form-select" id="education-level" name="education_level_id">
                <option value="">No registrado</option>
                <?php foreach ($personOptions->educationLevels as $option): ?>
                <option value="<?= $escape($option->id) ?>"<?= $selected($values['education_level_id'] ?? '', $option->id) ?>><?= $escape($option->name) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div><label class="form-label" for="email">Correo electrónico</label><input class="form-control" id="email" name="email" type="email" value="<?= $escape($values['email'] ?? '') ?>"></div>
        <div><label class="form-label" for="mobile-phone">Teléfono móvil</label><input class="form-control" id="mobile-phone" name="mobile_phone" type="text" value="<?= $escape($values['mobile_phone'] ?? '') ?>"></div>
        <div><label class="form-label" for="landline-phone">Teléfono fijo</label><input class="form-control" id="landline-phone" name="landline_phone" type="text" value="<?= $escape($values['landline_phone'] ?? '') ?>"></div>
        <div>
            <label class="form-label" for="person-status">Estado de la persona</label>
            <select class="form-select" id="person-status" name="person_status" required>
                <?php foreach ($personOptions->statuses as $option): ?>
                <option value="<?= $escape($option->code) ?>"<?= $selected($values['person_status'] ?? '', $option->code) ?>><?= $escape($option->name) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </fieldset>

    <fieldset class="app-form-section">
        <legend>Información del estudiante</legend>
        <div><label class="form-label" for="institutional-code">Código institucional</label><input class="form-control" id="institutional-code" name="institutional_code" type="text" value="<?= $escape($values['institutional_code'] ?? '') ?>" required></div>
        <div><label class="form-label" for="admission-date">Fecha de admisión</label><input class="form-control" id="admission-date" name="admission_date" type="date" value="<?= $escape($values['admission_date'] ?? '') ?>" required></div>
        <div>
            <label class="form-label" for="student-status">Estado del estudiante</label>
            <select class="form-select" id="student-status" name="student_status" required>
                <?php foreach (StudentStatus::cases() as $status): ?>
                <option value="<?= $escape($status->value) ?>"<?= $selected($values['student_status'] ?? '', $status->value) ?>><?= $escape($status->value === 'ACTIVE' ? 'Activo' : 'Inactivo') ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </fieldset>

    <fieldset class="app-form-section">
        <legend>Membresía familiar</legend>
        <div><label class="form-label" for="started-at">Fecha y hora de inicio</label><input class="form-control" id="started-at" name="started_at" type="datetime-local" value="<?= $escape($values['started_at'] ?? '') ?>" required></div>
    </fieldset>

    <div class="app-action-group">
        <button class="btn btn-primary" type="submit"<?= ($canSubmit ?? false) ? '' : ' disabled' ?>>Agregar estudiante</button>
        <a class="btn btn-outline-secondary" href="/families/show?id=<?= $escape($family->id) ?>">Cancelar</a>
    </div>
</form>
