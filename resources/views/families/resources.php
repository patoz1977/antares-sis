<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$value = static fn (string $key, mixed $fallback = ''): mixed => array_key_exists($key, $values) ? $values[$key] : $fallback;
$timestamp = static fn (DateTimeImmutable $date): string => $date->format(DateTimeImmutable::ATOM);
$activeRepresentatives = array_values(array_filter($family->representatives, static fn ($item): bool => $item->isActive));
$activeStudents = array_values(array_filter($family->students, static fn ($item): bool => $item->isActive));
$activeAddresses = array_values(array_filter($resources->addresses, static fn ($item): bool => $item->status === 'ACTIVE'));
$activeContacts = array_values(array_filter($resources->emergencyContacts, static fn ($item): bool => $item->status === 'ACTIVE'));
$activePickups = array_values(array_filter($resources->authorizedPickups, static fn ($item): bool => $item->status === 'ACTIVE'));
$addressLabel = static function (int $id) use ($resources): string {
    foreach ($resources->addresses as $address) {
        if ($address->id === $id) {
            return $address->label;
        }
    }
    return 'Dirección no disponible';
};
$contactName = static function (int $id) use ($resources): string {
    foreach ($resources->emergencyContacts as $contact) {
        if ($contact->id === $id) {
            return $contact->names;
        }
    }
    return 'Contacto no disponible';
};
$pickupName = static function (int $id) use ($resources): string {
    foreach ($resources->authorizedPickups as $pickup) {
        if ($pickup->id === $id) {
            return $pickup->names;
        }
    }
    return 'Persona no disponible';
};
$catalogName = static function (array $catalog, ?int $id): string {
    if ($id === null) {
        return 'No registrado';
    }
    foreach ($catalog as $option) {
        if ($option->id === $id) {
            return $option->name;
        }
    }
    return 'No disponible';
};
?>
<header class="app-page-header d-flex flex-column flex-lg-row justify-content-between gap-3 align-items-lg-start">
    <div>
        <p class="text-uppercase fw-semibold text-primary mb-2">Familia <?= $escape($resources->displayName) ?></p>
        <h1 class="display-6 fw-bold mb-2">Recursos familiares</h1>
        <p class="text-body-secondary mb-0">Administra direcciones, contactos de emergencia, personas autorizadas y su historial de asignaciones.</p>
    </div>
    <div class="app-action-group">
        <a class="btn btn-outline-primary" href="/families/show?id=<?= $escape($resources->familyId) ?>">Volver a la familia</a>
        <a class="btn btn-outline-secondary" href="/families">Familias</a>
    </div>
</header>

<section class="app-data-card" aria-labelledby="resources-family-heading">
    <h2 class="h4" id="resources-family-heading">Contexto familiar</h2>
    <dl class="app-data-list">
        <dt>Familia</dt><dd><?= $escape($resources->displayName) ?></dd>
        <dt>Estado</dt><dd><?php $statusCode = $resources->status; require dirname(__DIR__) . '/components/status-badge.php'; ?></dd>
    </dl>
</section>

<?php require dirname(__DIR__) . '/components/validation-summary.php'; ?>

<nav class="app-section-nav mb-4" aria-label="Tipos de recursos familiares">
    <a href="#direcciones">Direcciones</a>
    <a href="#contactos-emergencia">Contactos de emergencia</a>
    <a href="#retiros-autorizados">Retiros autorizados</a>
</nav>

<section class="app-resource-section mb-5" id="direcciones" aria-labelledby="addresses-heading">
<h2 class="h3" id="addresses-heading">Direcciones</h2>

<div class="app-form-section">
<h3 class="h5">Crear dirección</h3>
<form method="post" action="/families/resources/addresses/create">
    <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
    <input type="hidden" name="family_id" value="<?= $escape($resources->familyId) ?>">
    <label>Etiqueta <input name="label" value="<?= $escape($value('label')) ?>" required></label>
    <label>Calle principal <input name="main_street" value="<?= $escape($value('main_street')) ?>" required></label>
    <label>Número <input name="street_number" value="<?= $escape($value('street_number')) ?>"></label>
    <label>Calle secundaria <input name="secondary_street" value="<?= $escape($value('secondary_street')) ?>"></label>
    <label>Sector <input name="sector" value="<?= $escape($value('sector')) ?>"></label>
    <label>Referencia <input name="reference" value="<?= $escape($value('reference')) ?>"></label>
    <label>Latitud <input name="latitude" inputmode="decimal" value="<?= $escape($value('latitude')) ?>"></label>
    <label>Longitud <input name="longitude" inputmode="decimal" value="<?= $escape($value('longitude')) ?>"></label>
    <button type="submit">Crear dirección</button>
</form>
</div>

<h3 class="h5 mt-4">Direcciones existentes y mantenimiento</h3>
<?php if ($resources->addresses === []): ?>
<?php $emptyStateTitle = 'No hay direcciones registradas'; $emptyStateText = 'Crea una dirección para poder asignarla a representantes o estudiantes.'; require dirname(__DIR__) . '/components/empty-state.php'; ?>
<?php else: ?>
<?php foreach ($resources->addresses as $address): ?>
<article class="app-data-card">
    <h3><?= $escape($address->label) ?></h3>
    <dl>
        <dt>Calle principal</dt><dd><?= $escape($address->mainStreet) ?></dd>
        <dt>Número</dt><dd><?= $escape($address->streetNumber ?? 'No registrado') ?></dd>
        <dt>Calle secundaria</dt><dd><?= $escape($address->secondaryStreet ?? 'No registrado') ?></dd>
        <dt>Sector</dt><dd><?= $escape($address->sector ?? 'No registrado') ?></dd>
        <dt>Referencia</dt><dd><?= $escape($address->reference ?? 'No registrado') ?></dd>
        <dt>Latitud</dt><dd><?= $escape($address->latitude ?? 'No registrada') ?></dd>
        <dt>Longitud</dt><dd><?= $escape($address->longitude ?? 'No registrada') ?></dd>
        <dt>Estado</dt><dd><?= $escape($address->status === 'ACTIVE' ? 'Activo' : 'Inactivo') ?></dd>
    </dl>
    <form method="post" action="/families/resources/addresses/update">
        <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
        <input type="hidden" name="family_id" value="<?= $escape($resources->familyId) ?>">
        <input type="hidden" name="family_address_id" value="<?= $escape($address->id) ?>">
        <label>Etiqueta <input name="label" value="<?= $escape($address->label) ?>" required></label>
        <label>Calle principal <input name="main_street" value="<?= $escape($address->mainStreet) ?>" required></label>
        <label>Número <input name="street_number" value="<?= $escape($address->streetNumber) ?>"></label>
        <label>Calle secundaria <input name="secondary_street" value="<?= $escape($address->secondaryStreet) ?>"></label>
        <label>Sector <input name="sector" value="<?= $escape($address->sector) ?>"></label>
        <label>Referencia <input name="reference" value="<?= $escape($address->reference) ?>"></label>
        <label>Latitud <input name="latitude" inputmode="decimal" value="<?= $escape($address->latitude) ?>"></label>
        <label>Longitud <input name="longitude" inputmode="decimal" value="<?= $escape($address->longitude) ?>"></label>
        <button type="submit">Guardar dirección</button>
    </form>
    <form method="post" action="/families/resources/addresses/<?= $address->status === 'ACTIVE' ? 'deactivate' : 'activate' ?>">
        <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
        <input type="hidden" name="family_id" value="<?= $escape($resources->familyId) ?>">
        <input type="hidden" name="family_address_id" value="<?= $escape($address->id) ?>">
        <button type="submit"><?= $address->status === 'ACTIVE' ? 'Desactivar' : 'Activar' ?> dirección</button>
    </form>
</article>
<?php endforeach; ?>
<?php endif; ?>

<h3 class="h5 mt-4">Asignaciones de direcciones</h3>
<h4 class="h6">Asignar dirección a representante</h4>
<form method="post" action="/families/resources/representatives/address">
    <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
    <input type="hidden" name="family_id" value="<?= $escape($resources->familyId) ?>">
    <label>Representante
        <select name="representative_id" required>
            <?php foreach ($activeRepresentatives as $membership): ?>
            <option value="<?= $escape($membership->representativeId) ?>"><?= $escape($memberLabels->representative($membership->representativeId)) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Dirección
        <select name="family_address_id" required>
            <?php foreach ($activeAddresses as $address): ?>
            <option value="<?= $escape($address->id) ?>"><?= $escape($address->label) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Inicio <input name="started_at" type="datetime-local" required></label>
    <button type="submit"<?= $activeRepresentatives === [] || $activeAddresses === [] ? ' disabled' : '' ?>>Asignar dirección al representante</button>
</form>

<h4 class="h6">Asignar dirección a estudiante</h4>
<form method="post" action="/families/resources/students/address">
    <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
    <input type="hidden" name="family_id" value="<?= $escape($resources->familyId) ?>">
    <label>Estudiante
        <select name="student_id" required>
            <?php foreach ($activeStudents as $membership): ?>
            <option value="<?= $escape($membership->studentId) ?>"><?= $escape($memberLabels->student($membership->studentId)) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Dirección
        <select name="family_address_id" required>
            <?php foreach ($activeAddresses as $address): ?>
            <option value="<?= $escape($address->id) ?>"><?= $escape($address->label) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Inicio <input name="started_at" type="datetime-local" required></label>
    <button type="submit"<?= $activeStudents === [] || $activeAddresses === [] ? ' disabled' : '' ?>>Asignar dirección al estudiante</button>
</form>

<h3 class="h5">Historial de asignaciones de dirección</h3>
<?php foreach ($resources->representativeAddressAssignments as $assignment): ?>
<article class="app-data-card">
    <p><?= $escape($memberLabels->representative($assignment->representativeId)) ?> — <?= $escape($addressLabel($assignment->familyAddressId)) ?> — <?= $assignment->isActive ? 'Activa' : 'Histórica' ?></p>
    <p><?= $escape($timestamp($assignment->startedAt)) ?> hasta <?= $assignment->endedAt === null ? 'Sin finalizar' : $escape($timestamp($assignment->endedAt)) ?></p>
    <?php if ($assignment->isActive): ?>
    <form method="post" action="/families/resources/representatives/address/end">
        <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
        <input type="hidden" name="family_id" value="<?= $escape($resources->familyId) ?>">
        <input type="hidden" name="assignment_id" value="<?= $escape($assignment->id) ?>">
        <label>Fin <input name="ended_at" type="datetime-local" required></label>
        <button type="submit">Finalizar dirección del representante</button>
    </form>
    <?php endif; ?>
</article>
<?php endforeach; ?>
<?php foreach ($resources->studentAddressAssignments as $assignment): ?>
<article>
    <p><?= $escape($memberLabels->student($assignment->studentId)) ?> — <?= $escape($addressLabel($assignment->familyAddressId)) ?> — <?= $assignment->isActive ? 'Activa' : 'Histórica' ?></p>
    <p><?= $escape($timestamp($assignment->startedAt)) ?> hasta <?= $assignment->endedAt === null ? 'Sin finalizar' : $escape($timestamp($assignment->endedAt)) ?></p>
    <?php if ($assignment->isActive): ?>
    <form method="post" action="/families/resources/students/address/end">
        <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
        <input type="hidden" name="family_id" value="<?= $escape($resources->familyId) ?>">
        <input type="hidden" name="assignment_id" value="<?= $escape($assignment->id) ?>">
        <label>Fin <input name="ended_at" type="datetime-local" required></label>
        <button type="submit">Finalizar dirección del estudiante</button>
    </form>
    <?php endif; ?>
</article>
<?php endforeach; ?>
</section>

<section class="app-resource-section mb-5" id="contactos-emergencia" aria-labelledby="emergency-heading">
<h2 class="h3" id="emergency-heading">Contactos de emergencia</h2>
<?php if ($options->relationshipTypes === []): ?>
<p class="alert alert-warning" role="alert">No hay tipos de relación activos. Los formularios de contactos de emergencia y personas autorizadas están deshabilitados.</p>
<?php endif; ?>
<h3 class="h5">Crear contacto de emergencia</h3>
<form method="post" action="/families/resources/emergency-contacts/create">
    <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
    <input type="hidden" name="family_id" value="<?= $escape($resources->familyId) ?>">
    <label>Nombres <input name="names" value="<?= $escape($value('names')) ?>" required></label>
    <label>Tipo de relación
        <select name="relationship_type_id" required>
            <?php foreach ($options->relationshipTypes as $option): ?>
            <option value="<?= $escape($option->id) ?>"><?= $escape($option->name) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Teléfono móvil <input name="mobile_phone" value="<?= $escape($value('mobile_phone')) ?>" required></label>
    <label>Teléfono <input name="phone" value="<?= $escape($value('phone')) ?>"></label>
    <label>Correo electrónico <input name="email" type="email" value="<?= $escape($value('email')) ?>"></label>
    <label>Observaciones <textarea name="observations"><?= $escape($value('observations')) ?></textarea></label>
    <button type="submit"<?= $options->relationshipTypes === [] ? ' disabled' : '' ?>>Crear contacto de emergencia</button>
</form>

<h3 class="h5 mt-4">Contactos existentes y mantenimiento</h3>
<?php foreach ($resources->emergencyContacts as $contact): ?>
<article>
    <h3><?= $escape($contact->names) ?></h3>
    <dl>
        <dt>Relación</dt><dd><?= $escape($catalogName($options->relationshipTypes, $contact->relationshipTypeId)) ?></dd>
        <dt>Teléfono móvil</dt><dd><?= $escape($contact->mobilePhone) ?></dd>
        <dt>Teléfono</dt><dd><?= $escape($contact->phone ?? 'No registrado') ?></dd>
        <dt>Correo electrónico</dt><dd><?= $escape($contact->email ?? 'No registrado') ?></dd>
        <dt>Observaciones</dt><dd><?= $escape($contact->observations ?? 'No registradas') ?></dd>
        <dt>Estado</dt><dd><?= $escape($contact->status === 'ACTIVE' ? 'Activo' : 'Inactivo') ?></dd>
    </dl>
    <form method="post" action="/families/resources/emergency-contacts/update">
        <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
        <input type="hidden" name="family_id" value="<?= $escape($resources->familyId) ?>">
        <input type="hidden" name="family_emergency_contact_id" value="<?= $escape($contact->id) ?>">
        <label>Nombres <input name="names" value="<?= $escape($contact->names) ?>" required></label>
        <label>Tipo de relación
            <select name="relationship_type_id" required>
                <?php foreach ($options->relationshipTypes as $option): ?>
                <option value="<?= $escape($option->id) ?>"<?= $option->id === $contact->relationshipTypeId ? ' selected' : '' ?>><?= $escape($option->name) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Teléfono móvil <input name="mobile_phone" value="<?= $escape($contact->mobilePhone) ?>" required></label>
        <label>Teléfono <input name="phone" value="<?= $escape($contact->phone) ?>"></label>
        <label>Correo electrónico <input name="email" type="email" value="<?= $escape($contact->email) ?>"></label>
        <label>Observaciones <textarea name="observations"><?= $escape($contact->observations) ?></textarea></label>
        <button type="submit"<?= $options->relationshipTypes === [] ? ' disabled' : '' ?>>Guardar contacto de emergencia</button>
    </form>
    <form method="post" action="/families/resources/emergency-contacts/<?= $contact->status === 'ACTIVE' ? 'deactivate' : 'activate' ?>">
        <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
        <input type="hidden" name="family_id" value="<?= $escape($resources->familyId) ?>">
        <input type="hidden" name="family_emergency_contact_id" value="<?= $escape($contact->id) ?>">
        <button type="submit"><?= $contact->status === 'ACTIVE' ? 'Desactivar' : 'Activar' ?> contacto de emergencia</button>
    </form>
</article>
<?php endforeach; ?>

<h3 class="h5 mt-4">Asignaciones de contactos de emergencia</h3>
<h4 class="h6">Asignar contacto de emergencia</h4>
<form method="post" action="/families/resources/emergency-contacts/assign">
    <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
    <input type="hidden" name="family_id" value="<?= $escape($resources->familyId) ?>">
    <label>Contacto de emergencia
        <select name="family_emergency_contact_id" required>
            <?php foreach ($activeContacts as $contact): ?>
            <option value="<?= $escape($contact->id) ?>"><?= $escape($contact->names) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Estudiante
        <select name="student_id" required>
            <?php foreach ($activeStudents as $membership): ?>
            <option value="<?= $escape($membership->studentId) ?>"><?= $escape($memberLabels->student($membership->studentId)) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Prioridad <input name="priority" type="number" min="1"></label>
    <label>Inicio <input name="started_at" type="datetime-local" required></label>
    <button type="submit"<?= $activeContacts === [] || $activeStudents === [] ? ' disabled' : '' ?>>Asignar contacto de emergencia</button>
</form>

<h3 class="h5">Historial de contactos de emergencia</h3>
<?php foreach ($resources->emergencyContactAssignments as $assignment): ?>
<article>
    <p><?= $escape($contactName($assignment->familyEmergencyContactId)) ?> — <?= $escape($memberLabels->student($assignment->studentId)) ?> — Prioridad <?= $escape($assignment->priority ?? 'No registrada') ?> — <?= $assignment->isActive ? 'Activa' : 'Histórica' ?></p>
    <p><?= $escape($timestamp($assignment->startedAt)) ?> hasta <?= $assignment->endedAt === null ? 'Sin finalizar' : $escape($timestamp($assignment->endedAt)) ?></p>
    <?php if ($assignment->isActive): ?>
    <form method="post" action="/families/resources/emergency-contacts/end">
        <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
        <input type="hidden" name="family_id" value="<?= $escape($resources->familyId) ?>">
        <input type="hidden" name="assignment_id" value="<?= $escape($assignment->id) ?>">
        <label>Fin <input name="ended_at" type="datetime-local" required></label>
        <button type="submit">Finalizar asignación</button>
    </form>
    <?php endif; ?>
</article>
<?php endforeach; ?>
</section>

<section class="app-resource-section mb-5" id="retiros-autorizados" aria-labelledby="pickups-heading">
<h2 class="h3" id="pickups-heading">Personas autorizadas para retirar</h2>
<h3 class="h5">Crear persona autorizada</h3>
<form method="post" action="/families/resources/authorized-pickups/create">
    <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
    <input type="hidden" name="family_id" value="<?= $escape($resources->familyId) ?>">
    <label>Nombres <input name="names" value="<?= $escape($value('names')) ?>" required></label>
    <label>Tipo de relación
        <select name="relationship_type_id" required>
            <?php foreach ($options->relationshipTypes as $option): ?>
            <option value="<?= $escape($option->id) ?>"<?= (string) $option->id === (string) $value('relationship_type_id') ? ' selected' : '' ?>><?= $escape($option->name) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Teléfono móvil <input name="mobile_phone" value="<?= $escape($value('mobile_phone')) ?>" required></label>
    <label>Teléfono <input name="phone" value="<?= $escape($value('phone')) ?>"></label>
    <label>Tipo de documento (opcional)
        <select name="document_type_id">
            <option value="">Sin identificación</option>
            <?php foreach ($options->documentTypes as $option): ?>
            <option value="<?= $escape($option->id) ?>"<?= (string) $option->id === (string) $value('document_type_id') ? ' selected' : '' ?>><?= $escape($option->name) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Número de documento (opcional) <input name="document_number" value="<?= $escape($value('document_number')) ?>"></label>
    <label>Observaciones <textarea name="observations"><?= $escape($value('observations')) ?></textarea></label>
    <button type="submit"<?= $options->relationshipTypes === [] ? ' disabled' : '' ?>>Crear persona autorizada</button>
</form>
<?php if ($options->documentTypes === []): ?>
<p class="alert alert-info">No hay tipos de documento activos. La persona autorizada puede guardarse sin identificación.</p>
<?php endif; ?>

<h3 class="h5 mt-4">Personas existentes y mantenimiento</h3>
<?php foreach ($resources->authorizedPickups as $pickup): ?>
<article class="app-data-card">
    <h3><?= $escape($pickup->names) ?></h3>
    <dl>
        <dt>Relación</dt><dd><?= $escape($catalogName($options->relationshipTypes, $pickup->relationshipTypeId)) ?></dd>
        <dt>Teléfono móvil</dt><dd><?= $escape($pickup->mobilePhone) ?></dd>
        <dt>Teléfono</dt><dd><?= $escape($pickup->phone ?? 'No registrado') ?></dd>
        <dt>Tipo de documento</dt><dd><?= $escape($catalogName($options->documentTypes, $pickup->documentTypeId)) ?></dd>
        <dt>Número de documento</dt><dd><?= $escape($pickup->documentNumber ?? 'No registrado') ?></dd>
        <dt>Observaciones</dt><dd><?= $escape($pickup->observations ?? 'No registradas') ?></dd>
        <dt>Estado</dt><dd><?= $escape($pickup->status === 'ACTIVE' ? 'Activo' : 'Inactivo') ?></dd>
    </dl>
    <form method="post" action="/families/resources/authorized-pickups/update">
        <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
        <input type="hidden" name="family_id" value="<?= $escape($resources->familyId) ?>">
        <input type="hidden" name="family_authorized_pickup_id" value="<?= $escape($pickup->id) ?>">
        <label>Nombres <input name="names" value="<?= $escape($pickup->names) ?>" required></label>
        <label>Tipo de relación
            <select name="relationship_type_id" required>
                <?php foreach ($options->relationshipTypes as $option): ?>
                <option value="<?= $escape($option->id) ?>"<?= $option->id === $pickup->relationshipTypeId ? ' selected' : '' ?>><?= $escape($option->name) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Teléfono móvil <input name="mobile_phone" value="<?= $escape($pickup->mobilePhone) ?>" required></label>
        <label>Teléfono <input name="phone" value="<?= $escape($pickup->phone) ?>"></label>
        <label>Tipo de documento
            <select name="document_type_id">
                <option value="">Sin identificación</option>
                <?php foreach ($options->documentTypes as $option): ?>
                <option value="<?= $escape($option->id) ?>"<?= $option->id === $pickup->documentTypeId ? ' selected' : '' ?>><?= $escape($option->name) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Número de documento <input name="document_number" value="<?= $escape($pickup->documentNumber) ?>"></label>
        <label>Observaciones <textarea name="observations"><?= $escape($pickup->observations) ?></textarea></label>
        <button type="submit"<?= $options->relationshipTypes === [] ? ' disabled' : '' ?>>Guardar persona autorizada</button>
    </form>
    <form method="post" action="/families/resources/authorized-pickups/<?= $pickup->status === 'ACTIVE' ? 'deactivate' : 'activate' ?>">
        <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
        <input type="hidden" name="family_id" value="<?= $escape($resources->familyId) ?>">
        <input type="hidden" name="family_authorized_pickup_id" value="<?= $escape($pickup->id) ?>">
        <button type="submit"><?= $pickup->status === 'ACTIVE' ? 'Desactivar' : 'Activar' ?> persona autorizada</button>
    </form>
</article>
<?php endforeach; ?>

<h3 class="h5 mt-4">Asignaciones de retiros autorizados</h3>
<h4 class="h6">Asignar persona autorizada</h4>
<form method="post" action="/families/resources/authorized-pickups/assign">
    <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
    <input type="hidden" name="family_id" value="<?= $escape($resources->familyId) ?>">
    <label>Persona autorizada
        <select name="family_authorized_pickup_id" required>
            <?php foreach ($activePickups as $pickup): ?>
            <option value="<?= $escape($pickup->id) ?>"><?= $escape($pickup->names) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Estudiante
        <select name="student_id" required>
            <?php foreach ($activeStudents as $membership): ?>
            <option value="<?= $escape($membership->studentId) ?>"><?= $escape($memberLabels->student($membership->studentId)) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Inicio <input name="started_at" type="datetime-local" required></label>
    <button type="submit"<?= $activePickups === [] || $activeStudents === [] ? ' disabled' : '' ?>>Asignar persona autorizada</button>
</form>

<h3 class="h5">Historial de personas autorizadas</h3>
<?php foreach ($resources->authorizedPickupAssignments as $assignment): ?>
<article>
    <p><?= $escape($pickupName($assignment->familyAuthorizedPickupId)) ?> — <?= $escape($memberLabels->student($assignment->studentId)) ?> — <?= $assignment->isActive ? 'Activa' : 'Histórica' ?></p>
    <p><?= $escape($timestamp($assignment->startedAt)) ?> hasta <?= $assignment->endedAt === null ? 'Sin finalizar' : $escape($timestamp($assignment->endedAt)) ?></p>
    <?php if ($assignment->isActive): ?>
    <form method="post" action="/families/resources/authorized-pickups/end">
        <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
        <input type="hidden" name="family_id" value="<?= $escape($resources->familyId) ?>">
        <input type="hidden" name="assignment_id" value="<?= $escape($assignment->id) ?>">
        <label>Fin <input name="ended_at" type="datetime-local" required></label>
        <button type="submit">Finalizar asignación</button>
    </form>
    <?php endif; ?>
</article>
<?php endforeach; ?>
</section>

<div class="app-action-group">
    <a class="btn btn-outline-primary" href="/families/show?id=<?= $escape($resources->familyId) ?>">Volver a la familia</a>
    <a class="btn btn-outline-secondary" href="/families">Familias</a>
</div>
