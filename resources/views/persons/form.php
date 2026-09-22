<?php

declare(strict_types=1);

use App\Person\Http\PersonFormOption;

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$selected = static fn (mixed $actual, mixed $expected): string => (string) $actual === (string) $expected ? ' selected' : '';
$isEdit = ($mode ?? '') === 'edit';
$action = $isEdit ? '/persons/update' : '/persons/create';
?>
<header class="app-page-header">
    <p class="text-uppercase fw-semibold text-primary mb-2">Personas</p>
    <h1 class="display-6 fw-bold mb-2"><?= $isEdit ? 'Editar persona' : 'Crear persona' ?></h1>
    <p class="text-body-secondary mb-0">Los campos marcados como obligatorios se validan en el servidor.</p>
</header>

<?php require dirname(__DIR__) . '/components/validation-summary.php'; ?>

<form class="app-content-narrow" method="post" action="<?= $escape($action) ?>">
    <p class="text-body-secondary">Los campos marcados con * son obligatorios.</p>
    <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken ?? '') ?>">
    <?php if ($isEdit): ?>
    <input type="hidden" name="id" value="<?= $escape($personId ?? '') ?>">
    <?php endif; ?>

    <fieldset class="app-form-section">
        <legend>Datos personales</legend>
        <div class="app-form-grid">
            <div><label class="form-label app-required-label" for="first-name">Primer nombre</label><input class="form-control" id="first-name" name="first_name" type="text" value="<?= $escape($values['first_name'] ?? '') ?>" required></div>
            <div><label class="form-label" for="middle-name">Segundo nombre</label><input class="form-control" id="middle-name" name="middle_name" type="text" value="<?= $escape($values['middle_name'] ?? '') ?>"></div>
            <div><label class="form-label app-required-label" for="first-surname">Primer apellido</label><input class="form-control" id="first-surname" name="first_surname" type="text" value="<?= $escape($values['first_surname'] ?? '') ?>" required></div>
            <div><label class="form-label" for="second-surname">Segundo apellido</label><input class="form-control" id="second-surname" name="second_surname" type="text" value="<?= $escape($values['second_surname'] ?? '') ?>"></div>
            <div><label class="form-label app-required-label" for="birth-date">Fecha de nacimiento</label><input class="form-control" id="birth-date" name="birth_date" type="date" value="<?= $escape($values['birth_date'] ?? '') ?>" required></div>
            <div>
                <label class="form-label app-required-label" for="sex">Sexo</label>
                <select class="form-select" id="sex" name="sex_id" required>
                    <option value="">Selecciona una opción</option>
                    <?php foreach ($options->sexes as $option): ?>
                    <option value="<?= $escape($option->id) ?>"<?= $selected($values['sex_id'] ?? '', $option->id) ?>><?= $escape($option->name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label" for="marital-status">Estado civil</label>
                <select class="form-select" id="marital-status" name="marital_status_id">
                    <option value="">No registrado</option>
                    <?php foreach ($options->maritalStatuses as $option): ?>
                    <option value="<?= $escape($option->id) ?>"<?= $selected($values['marital_status_id'] ?? '', $option->id) ?>><?= $escape($option->name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label" for="education-level">Nivel educativo</label>
                <select class="form-select" id="education-level" name="education_level_id">
                    <option value="">No registrado</option>
                    <?php foreach ($options->educationLevels as $option): ?>
                    <option value="<?= $escape($option->id) ?>"<?= $selected($values['education_level_id'] ?? '', $option->id) ?>><?= $escape($option->name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </fieldset>

    <fieldset class="app-form-section">
        <legend>Identificación</legend>
        <div class="app-form-grid">
            <div>
                <label class="form-label" for="document-type">Tipo de documento</label>
                <select class="form-select" id="document-type" name="document_type_id">
                    <option value="">Sin documento</option>
                    <?php foreach ($options->documentTypes as $option): ?>
                    <?php /** @var PersonFormOption $option */ ?>
                    <option value="<?= $escape($option->id) ?>"<?= $selected($values['document_type_id'] ?? '', $option->id) ?>><?= $escape($option->name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><label class="form-label" for="document-number">Número de documento</label><input class="form-control" id="document-number" name="document_number" type="text" value="<?= $escape($values['document_number'] ?? '') ?>"></div>
        </div>
    </fieldset>

    <fieldset class="app-form-section">
        <legend>Contacto</legend>
        <div class="app-form-grid">
            <div class="app-field-wide"><label class="form-label" for="email">Correo electrónico</label><input class="form-control" id="email" name="email" type="email" value="<?= $escape($values['email'] ?? '') ?>"></div>
            <div><label class="form-label" for="mobile-phone">Teléfono móvil</label><input class="form-control" id="mobile-phone" name="mobile_phone" type="text" value="<?= $escape($values['mobile_phone'] ?? '') ?>"></div>
            <div><label class="form-label" for="landline-phone">Teléfono fijo</label><input class="form-control" id="landline-phone" name="landline_phone" type="text" value="<?= $escape($values['landline_phone'] ?? '') ?>"></div>
        </div>
    </fieldset>

    <fieldset class="app-form-section">
        <legend>Estado</legend>
        <label class="form-label app-required-label" for="status">Estado de la persona</label>
        <select class="form-select" id="status" name="status" required>
            <?php foreach ($options->statuses as $option): ?>
            <option value="<?= $escape($option->code) ?>"<?= $selected($values['status'] ?? '', $option->code) ?>><?= $escape($option->name) ?></option>
            <?php endforeach; ?>
        </select>
    </fieldset>

    <div class="app-action-group">
        <button class="btn btn-primary" type="submit"<?= ($canSubmit ?? false) ? '' : ' disabled' ?>><?= $isEdit ? 'Guardar cambios' : 'Crear persona' ?></button>
        <a class="btn btn-outline-secondary" href="<?= $isEdit ? '/persons/show?id=' . $escape($personId ?? '') : '/persons' ?>">Cancelar</a>
    </div>
</form>
