<?php

declare(strict_types=1);

use App\Person\Http\PersonFormOption;
use App\Representative\Domain\RepresentativeStatus;

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$selected = static fn (mixed $actual, mixed $expected): string => (string) $actual === (string) $expected
    ? ' selected'
    : '';
?>
<header class="app-page-header">
    <p class="text-uppercase fw-semibold text-primary mb-2">Familias</p>
    <h1 class="display-6 fw-bold mb-2">Crear representante y familia</h1>
    <p class="text-body-secondary mb-0">Registra la persona representante, su información propia y el nuevo contexto familiar en una sola operación.</p>
</header>

<?php require dirname(__DIR__) . '/components/validation-summary.php'; ?>

<form class="app-content-narrow" method="post" action="/families/create">
    <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken ?? '') ?>">

    <fieldset class="app-form-section">
        <legend>Datos personales del representante</legend>
        <div>
            <label class="form-label" for="first-name">Primer nombre</label>
            <input class="form-control" id="first-name" name="first_name" type="text" value="<?= $escape($values['first_name'] ?? '') ?>" required>
        </div>
        <div>
            <label class="form-label" for="middle-name">Segundo nombre</label>
            <input class="form-control" id="middle-name" name="middle_name" type="text" value="<?= $escape($values['middle_name'] ?? '') ?>">
        </div>
        <div>
            <label class="form-label" for="first-surname">Primer apellido</label>
            <input class="form-control" id="first-surname" name="first_surname" type="text" value="<?= $escape($values['first_surname'] ?? '') ?>" required>
        </div>
        <div>
            <label class="form-label" for="second-surname">Segundo apellido</label>
            <input class="form-control" id="second-surname" name="second_surname" type="text" value="<?= $escape($values['second_surname'] ?? '') ?>">
        </div>
        <div>
            <label class="form-label" for="document-type">Tipo de documento</label>
            <select class="form-select" id="document-type" name="document_type_id">
                <option value="">Sin documento</option>
                <?php foreach ($personOptions->documentTypes as $option): ?>
                <?php /** @var PersonFormOption $option */ ?>
                <option value="<?= $escape($option->id) ?>"<?= $selected($values['document_type_id'] ?? '', $option->id) ?>><?= $escape($option->name) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="form-label" for="document-number">Número de documento</label>
            <input class="form-control" id="document-number" name="document_number" type="text" value="<?= $escape($values['document_number'] ?? '') ?>">
        </div>
        <div>
            <label class="form-label" for="birth-date">Fecha de nacimiento</label>
            <input class="form-control" id="birth-date" name="birth_date" type="date" value="<?= $escape($values['birth_date'] ?? '') ?>" required>
        </div>
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
        <div>
            <label class="form-label" for="email">Correo electrónico personal</label>
            <input class="form-control" id="email" name="email" type="email" value="<?= $escape($values['email'] ?? '') ?>" required>
        </div>
        <div>
            <label class="form-label" for="mobile-phone">Teléfono móvil</label>
            <input class="form-control" id="mobile-phone" name="mobile_phone" type="text" value="<?= $escape($values['mobile_phone'] ?? '') ?>">
        </div>
        <div>
            <label class="form-label" for="landline-phone">Teléfono fijo</label>
            <input class="form-control" id="landline-phone" name="landline_phone" type="text" value="<?= $escape($values['landline_phone'] ?? '') ?>">
        </div>
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
        <legend>Información del representante</legend>
        <div><label class="form-label" for="occupation">Ocupación</label><input class="form-control" id="occupation" name="occupation" type="text" value="<?= $escape($values['occupation'] ?? '') ?>"></div>
        <div><label class="form-label" for="company-name">Empresa</label><input class="form-control" id="company-name" name="company_name" type="text" value="<?= $escape($values['company_name'] ?? '') ?>"></div>
        <div><label class="form-label" for="position">Cargo</label><input class="form-control" id="position" name="position" type="text" value="<?= $escape($values['position'] ?? '') ?>"></div>
        <div><label class="form-label" for="work-phone">Teléfono laboral</label><input class="form-control" id="work-phone" name="work_phone" type="text" value="<?= $escape($values['work_phone'] ?? '') ?>"></div>
        <div><label class="form-label" for="work-email">Correo laboral</label><input class="form-control" id="work-email" name="work_email" type="email" value="<?= $escape($values['work_email'] ?? '') ?>"></div>
        <div>
            <label class="form-label" for="representative-status">Estado del representante</label>
            <select class="form-select" id="representative-status" name="representative_status" required>
                <?php foreach (RepresentativeStatus::cases() as $status): ?>
                <option value="<?= $escape($status->value) ?>"<?= $selected($values['representative_status'] ?? '', $status->value) ?>><?= $escape($status->value === 'ACTIVE' ? 'Activo' : 'Inactivo') ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </fieldset>

    <fieldset class="app-form-section">
        <legend>Familia y membresía</legend>
        <div><label class="form-label" for="display-name">Nombre visible</label><input class="form-control" id="display-name" name="display_name" type="text" value="<?= $escape($values['display_name'] ?? '') ?>" required></div>
        <div>
            <label class="form-label" for="family-status">Estado de la familia</label>
            <select class="form-select" id="family-status" name="family_status" required>
                <?php foreach ($familyOptions->statuses as $status): ?>
                <option value="<?= $escape($status->value) ?>"<?= $selected($values['family_status'] ?? '', $status->value) ?>><?= $escape($status->value === 'ACTIVE' ? 'Activa' : 'Inactiva') ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="form-label" for="relationship-type">Tipo de relación</label>
            <select class="form-select" id="relationship-type" name="relationship_type_id" required>
                <option value="">Selecciona una opción</option>
                <?php foreach ($familyOptions->relationshipTypes as $option): ?>
                <option value="<?= $escape($option->id) ?>"<?= $selected($values['relationship_type_id'] ?? '', $option->id) ?>><?= $escape($option->name) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div><label class="form-label" for="started-at">Inicio de la membresía</label><input class="form-control" id="started-at" name="started_at" type="datetime-local" value="<?= $escape($values['started_at'] ?? '') ?>" required></div>
    </fieldset>

    <div class="app-action-group">
        <button class="btn btn-primary" type="submit"<?= ($canSubmit ?? false) ? '' : ' disabled' ?>>Crear representante y familia</button>
        <a class="btn btn-outline-secondary" href="/families">Cancelar</a>
    </div>
</form>
