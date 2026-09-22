<?php

declare(strict_types=1);

use App\Family\Application\RepresentativeResources\Dto\RepresentativeFamilyStudentOption;

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$resourceScreen = in_array($screen ?? null, ['addresses', 'emergency-contacts', 'authorized-pickups'], true)
    ? $screen
    : 'addresses';
$resourceTitles = [
    'addresses' => 'Direcciones',
    'emergency-contacts' => 'Contactos de emergencia',
    'authorized-pickups' => 'Personas autorizadas para retirar',
];
$returnSuffix = is_int($returnStudentId ?? null) ? '?student_id=' . $returnStudentId : '';
$value = static fn (string $key, mixed $fallback = ''): mixed =>
    array_key_exists($key, $values) ? $values[$key] : $fallback;
$timestamp = static fn (DateTimeImmutable $date): string => $date->format(DateTimeImmutable::ATOM);
$activeAddresses = array_values(array_filter(
    $resources->addresses,
    static fn (object $item): bool => $item->status === 'ACTIVE',
));
$activeContacts = array_values(array_filter(
    $resources->emergencyContacts,
    static fn (object $item): bool => $item->status === 'ACTIVE',
));
$activePickups = array_values(array_filter(
    $resources->authorizedPickups,
    static fn (object $item): bool => $item->status === 'ACTIVE',
));
$studentName = static function (int $studentId) use ($students): string {
    foreach ($students as $student) {
        if ($student instanceof RepresentativeFamilyStudentOption && $student->studentId === $studentId) {
            return $student->displayName;
        }
    }

    return 'Estudiante no disponible';
};
$addressLabel = static function (int $addressId) use ($resources): string {
    foreach ($resources->addresses as $address) {
        if ($address->id === $addressId) {
            return $address->label;
        }
    }

    return 'Dirección no disponible';
};
$contactName = static function (int $contactId) use ($resources): string {
    foreach ($resources->emergencyContacts as $contact) {
        if ($contact->id === $contactId) {
            return $contact->names;
        }
    }

    return 'Contacto no disponible';
};
$pickupName = static function (int $pickupId) use ($resources): string {
    foreach ($resources->authorizedPickups as $pickup) {
        if ($pickup->id === $pickupId) {
            return $pickup->names;
        }
    }

    return 'Persona no disponible';
};
?>
<?php
$breadcrumbItems = [
    ['label' => 'Inicio', 'url' => '/representative'],
    ['label' => 'Actualización de datos', 'url' => '/representative/data'],
    ['label' => $resourceTitles[$resourceScreen]],
];
require dirname(__DIR__) . '/components/breadcrumb.php';
?>
<header class="app-page-header">
    <h1><?= $escape($resourceTitles[$resourceScreen]) ?></h1>
    <p class="text-body-secondary">Gestiona este recurso de tu familia y sus asignaciones.</p>
</header>

<section class="app-context-banner" aria-labelledby="resources-family-heading">
    <div>
        <p class="text-body-secondary mb-1" id="resources-family-heading">Familia actual</p>
        <p class="h4 mb-0"><?= $escape($context->familyDisplayName) ?></p>
    </div>
</section>

<nav class="app-section-nav mb-4" aria-label="Tipos de recursos familiares">
    <a href="/representative/resources/addresses"<?= $resourceScreen === 'addresses' ? ' aria-current="page"' : '' ?>>Direcciones</a>
    <a href="/representative/resources/emergency-contacts"<?= $resourceScreen === 'emergency-contacts' ? ' aria-current="page"' : '' ?>>Contactos de emergencia</a>
    <a href="/representative/resources/authorized-pickups"<?= $resourceScreen === 'authorized-pickups' ? ' aria-current="page"' : '' ?>>Personas autorizadas para retirar</a>
</nav>

<?php if ($errors !== []): ?>
<div class="alert alert-danger" role="alert">
    <p>Revisa la información enviada.</p>
    <ul>
    <?php foreach ($errors as $error): ?>
        <li><?= $escape($error) ?></li>
    <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<?php if ($resourceScreen === 'addresses'): ?>
<section class="app-resource-section d-flex flex-column" id="direcciones" aria-labelledby="portal-addresses-heading">
<h2 id="portal-addresses-heading">Direcciones</h2>

<div class="<?= $resources->addresses === [] ? 'order-1' : 'order-3' ?>">
<h3 class="h4">Crear nueva dirección</h3>
<form class="app-form-section" method="post" action="/representative/resources/addresses/create<?= $escape($returnSuffix) ?>">
    <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
    <input type="hidden" name="family_id" value="<?= $escape($context->familyId) ?>">
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

<div class="<?= $resources->addresses === [] ? 'order-2' : 'order-1' ?>">
<?php if ($resources->addresses === []): ?>
<?php $emptyStateTitle = 'No hay direcciones registradas'; $emptyStateText = 'Crea una dirección para poder asignarla.'; require dirname(__DIR__) . '/components/empty-state.php'; ?>
<?php else: ?>
<h3 class="h4 mt-4">Recursos existentes</h3>
<?php foreach ($resources->addresses as $address): ?>
<article class="app-data-card">
    <h3><?= $escape($address->label) ?></h3>
    <p><?= $escape($address->mainStreet) ?><?= $address->streetNumber === null ? '' : ' ' . $escape($address->streetNumber) ?><?= $address->sector === null ? '' : ' — ' . $escape($address->sector) ?></p>
    <p><?php $statusCode = $address->status; require dirname(__DIR__) . '/components/status-badge.php'; ?></p>
    <div class="d-flex flex-wrap align-items-center gap-2">
    <details class="d-inline"><summary class="btn btn-link p-0">Editar</summary>
    <form method="post" action="/representative/resources/addresses/update<?= $escape($returnSuffix) ?>">
        <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
        <input type="hidden" name="family_id" value="<?= $escape($context->familyId) ?>">
        <input type="hidden" name="family_address_id" value="<?= $escape($address->id) ?>">
        <label>Etiqueta <input name="label" value="<?= $escape($address->label) ?>" required></label>
        <label>Calle principal <input name="main_street" value="<?= $escape($address->mainStreet) ?>" required></label>
        <label>Número <input name="street_number" value="<?= $escape($address->streetNumber) ?>"></label>
        <label>Calle secundaria <input name="secondary_street" value="<?= $escape($address->secondaryStreet) ?>"></label>
        <label>Sector <input name="sector" value="<?= $escape($address->sector) ?>"></label>
        <label>Referencia <input name="reference" value="<?= $escape($address->reference) ?>"></label>
        <label>Latitud <input name="latitude" inputmode="decimal" value="<?= $escape($address->latitude) ?>"></label>
        <label>Longitud <input name="longitude" inputmode="decimal" value="<?= $escape($address->longitude) ?>"></label>
        <button type="submit">Actualizar dirección</button>
    </form>
    </details>
    <span aria-hidden="true">·</span>
    <form class="d-inline m-0" method="post" action="/representative/resources/addresses/<?= $address->status === 'ACTIVE' ? 'deactivate' : 'activate' ?><?= $escape($returnSuffix) ?>">
        <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
        <input type="hidden" name="family_id" value="<?= $escape($context->familyId) ?>">
        <input type="hidden" name="family_address_id" value="<?= $escape($address->id) ?>">
        <button class="btn btn-link p-0" type="submit"><?= $address->status === 'ACTIVE' ? 'Desactivar' : 'Activar' ?></button>
    </form>
    </div>
</article>
<?php endforeach; ?>
<?php endif; ?>
</div>

<div class="<?= $resources->addresses === [] ? 'order-3' : 'order-2' ?>">
<h3 class="h4 mt-4">Asignaciones</h3>
<h4 class="h5">Asignar dirección al representante</h4>
<form class="app-form-section" method="post" action="/representative/resources/address<?= $escape($returnSuffix) ?>">
    <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
    <input type="hidden" name="family_id" value="<?= $escape($context->familyId) ?>">
    <label>Dirección
        <select name="family_address_id" required>
            <?php foreach ($activeAddresses as $address): ?>
            <option value="<?= $escape($address->id) ?>"><?= $escape($address->label) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <button type="submit"<?= $activeAddresses === [] ? ' disabled' : '' ?>>Cambiar o asignar mi dirección</button>
</form>

<h4 class="h5">Asignar dirección a estudiante</h4>
<form class="app-form-section" method="post" action="/representative/resources/students/address<?= $escape($returnSuffix) ?>">
    <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
    <input type="hidden" name="family_id" value="<?= $escape($context->familyId) ?>">
    <label>Estudiante
        <select name="student_id" required>
            <?php foreach ($students as $student): ?>
            <option value="<?= $escape($student->studentId) ?>"><?= $escape($student->displayName) ?></option>
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
    <button type="submit"<?= $students === [] || $activeAddresses === [] ? ' disabled' : '' ?>>Cambiar o asignar dirección al estudiante</button>
</form>

<h3 class="h4">Direcciones asignadas al representante</h3>
<?php if ($ownRepresentativeAddressAssignments === []): ?>
<?php $emptyStateTitle = 'Sin historial de direcciones'; $emptyStateText = 'No existen asignaciones para el representante.'; require dirname(__DIR__) . '/components/empty-state.php'; ?>
<?php endif; ?>
<?php foreach ($ownRepresentativeAddressAssignments as $assignment): ?>
<article class="app-data-card d-flex flex-wrap align-items-center gap-2">
    <span><?= $escape($addressLabel($assignment->familyAddressId)) ?></span><span aria-hidden="true">·</span>
    <span>Desde <?= $escape($timestamp($assignment->startedAt)) ?><?= $assignment->endedAt === null ? '' : ' hasta ' . $escape($timestamp($assignment->endedAt)) ?></span><span aria-hidden="true">·</span>
    <span><?= $assignment->isActive ? 'Activa' : 'Histórica' ?></span>
    <?php if ($assignment->isActive): ?>
    <span aria-hidden="true">·</span>
    <form class="d-inline m-0" method="post" action="/representative/resources/address/end<?= $escape($returnSuffix) ?>">
        <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
        <input type="hidden" name="family_id" value="<?= $escape($context->familyId) ?>">
        <input type="hidden" name="assignment_id" value="<?= $escape($assignment->id) ?>">
        <button class="btn btn-link p-0" type="submit">Eliminar asignación</button>
    </form>
    <?php endif; ?>
</article>
<?php endforeach; ?>

<h3 class="h4">Direcciones asignadas a estudiantes</h3>
<?php if ($studentAddressAssignments === []): ?>
<?php $emptyStateTitle = 'Sin historial de estudiantes'; $emptyStateText = 'No existen asignaciones de dirección para estudiantes.'; require dirname(__DIR__) . '/components/empty-state.php'; ?>
<?php endif; ?>
<?php foreach ($studentAddressAssignments as $assignment): ?>
<article class="app-data-card d-flex flex-wrap align-items-center gap-2">
    <span><?= $escape($studentName($assignment->studentId)) ?> · <?= $escape($addressLabel($assignment->familyAddressId)) ?></span><span aria-hidden="true">·</span>
    <span>Desde <?= $escape($timestamp($assignment->startedAt)) ?><?= $assignment->endedAt === null ? '' : ' hasta ' . $escape($timestamp($assignment->endedAt)) ?></span><span aria-hidden="true">·</span>
    <span><?= $assignment->isActive ? 'Activa' : 'Histórica' ?></span>
    <?php if ($assignment->isActive): ?>
    <span aria-hidden="true">·</span>
    <form class="d-inline m-0" method="post" action="/representative/resources/students/address/end<?= $escape($returnSuffix) ?>">
        <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
        <input type="hidden" name="family_id" value="<?= $escape($context->familyId) ?>">
        <input type="hidden" name="assignment_id" value="<?= $escape($assignment->id) ?>">
        <button class="btn btn-link p-0" type="submit">Eliminar asignación</button>
    </form>
    <?php endif; ?>
</article>
<?php endforeach; ?>
</div>
</section>
<?php endif; ?>

<?php if ($resourceScreen === 'emergency-contacts'): ?>
<section class="app-resource-section d-flex flex-column" id="contactos-emergencia" aria-labelledby="portal-emergency-heading">
<h2 id="portal-emergency-heading">Contactos de emergencia</h2>
<?php if ($options->relationshipTypes === []): ?>
<p class="alert alert-warning" role="alert">No hay tipos de relación activos. Los formularios de contactos y retiros autorizados están deshabilitados.</p>
<?php endif; ?>

<div class="<?= $resources->emergencyContacts === [] ? 'order-1' : 'order-3' ?>">
<h3 class="h4">Crear nuevo contacto de emergencia</h3>
<form class="app-form-section" method="post" action="/representative/resources/emergency-contacts/create<?= $escape($returnSuffix) ?>">
    <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
    <input type="hidden" name="family_id" value="<?= $escape($context->familyId) ?>">
    <label>Nombres <input name="names" value="<?= $escape($value('names')) ?>" required></label>
    <label>Tipo de relación
        <select name="relationship_type_id" required>
            <?php foreach ($options->relationshipTypes as $option): ?>
            <option value="<?= $escape($option->id) ?>"><?= $escape($option->name) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Teléfono móvil <input name="mobile_phone" value="<?= $escape($value('mobile_phone')) ?>" required></label>
    <label>Teléfono fijo <input name="phone" value="<?= $escape($value('phone')) ?>"></label>
    <label>Correo electrónico <input name="email" type="email" value="<?= $escape($value('email')) ?>"></label>
    <label>Observaciones <textarea name="observations"><?= $escape($value('observations')) ?></textarea></label>
    <button type="submit"<?= $options->relationshipTypes === [] ? ' disabled' : '' ?>>Crear contacto</button>
</form>
</div>

<div class="<?= $resources->emergencyContacts === [] ? 'order-2' : 'order-1' ?>">
<?php if ($resources->emergencyContacts === []): ?>
<?php $emptyStateTitle = 'No hay contactos de emergencia'; $emptyStateText = 'Crea un contacto para poder asignarlo a un estudiante.'; require dirname(__DIR__) . '/components/empty-state.php'; ?>
<?php endif; ?>
<h3 class="h4 mt-4">Recursos existentes</h3>
<?php foreach ($resources->emergencyContacts as $contact): ?>
<article class="app-data-card">
    <h3><?= $escape($contact->names) ?></h3>
    <dl>
        <dt>Teléfono móvil</dt><dd><?= $escape($contact->mobilePhone) ?></dd>
        <dt>Teléfono fijo</dt><dd><?= $escape($contact->phone ?? 'No informado') ?></dd>
        <dt>Estado</dt><dd><?php $statusCode = $contact->status; require dirname(__DIR__) . '/components/status-badge.php'; ?></dd>
    </dl>
    <div class="d-flex flex-wrap align-items-center gap-2">
    <details class="d-inline"><summary class="btn btn-link p-0">Editar</summary><form method="post" action="/representative/resources/emergency-contacts/update<?= $escape($returnSuffix) ?>">
        <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
        <input type="hidden" name="family_id" value="<?= $escape($context->familyId) ?>">
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
        <label>Teléfono fijo <input name="phone" value="<?= $escape($contact->phone) ?>"></label>
        <label>Correo electrónico <input name="email" type="email" value="<?= $escape($contact->email) ?>"></label>
        <label>Observaciones <textarea name="observations"><?= $escape($contact->observations) ?></textarea></label>
        <button type="submit"<?= $options->relationshipTypes === [] ? ' disabled' : '' ?>>Actualizar contacto</button>
    </form>
    </details>
    <span aria-hidden="true">·</span>
    <form class="d-inline m-0" method="post" action="/representative/resources/emergency-contacts/<?= $contact->status === 'ACTIVE' ? 'deactivate' : 'activate' ?><?= $escape($returnSuffix) ?>">
        <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
        <input type="hidden" name="family_id" value="<?= $escape($context->familyId) ?>">
        <input type="hidden" name="family_emergency_contact_id" value="<?= $escape($contact->id) ?>">
        <button class="btn btn-link p-0" type="submit"><?= $contact->status === 'ACTIVE' ? 'Desactivar' : 'Activar' ?></button>
    </form>
    </div>
</article>
<?php endforeach; ?>
</div>

<div class="<?= $resources->emergencyContacts === [] ? 'order-3' : 'order-2' ?>">
<h3 class="h4 mt-4">Asignaciones</h3>
<h4 class="h5">Asignar contacto de emergencia</h4>
<form class="app-form-section" method="post" action="/representative/resources/emergency-contacts/assign<?= $escape($returnSuffix) ?>">
    <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
    <input type="hidden" name="family_id" value="<?= $escape($context->familyId) ?>">
    <label>Contacto de emergencia
        <select name="family_emergency_contact_id" required>
            <?php foreach ($activeContacts as $contact): ?>
            <option value="<?= $escape($contact->id) ?>"><?= $escape($contact->names) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Estudiante
        <select name="student_id" required>
            <?php foreach ($students as $student): ?>
            <option value="<?= $escape($student->studentId) ?>"><?= $escape($student->displayName) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Prioridad <select name="priority"><option value="">Sin prioridad</option><?php for ($priority = 1; $priority <= 10; $priority++): ?><option value="<?= $priority ?>"><?= $priority ?></option><?php endfor; ?></select></label>
    <button type="submit"<?= $activeContacts === [] || $students === [] ? ' disabled' : '' ?>>Asignar contacto</button>
</form>

<h3 class="h4">Contactos de emergencia asignados</h3>
<?php if ($emergencyContactAssignments === []): ?>
<?php $emptyStateTitle = 'Sin historial de contactos'; $emptyStateText = 'No existen asignaciones de contactos de emergencia.'; require dirname(__DIR__) . '/components/empty-state.php'; ?>
<?php endif; ?>
<?php foreach ($emergencyContactAssignments as $assignment): ?>
<article class="app-data-card d-flex flex-wrap align-items-center gap-2">
    <span><?= $escape($contactName($assignment->familyEmergencyContactId)) ?> · <?= $escape($studentName($assignment->studentId)) ?> · Prioridad <?= $escape($assignment->priority ?? 'No informada') ?></span><span aria-hidden="true">·</span>
    <span>Desde <?= $escape($timestamp($assignment->startedAt)) ?><?= $assignment->endedAt === null ? '' : ' hasta ' . $escape($timestamp($assignment->endedAt)) ?></span><span aria-hidden="true">·</span>
    <span><?= $assignment->isActive ? 'Activa' : 'Histórica' ?></span>
    <?php if ($assignment->isActive): ?>
    <span aria-hidden="true">·</span>
    <form class="d-inline m-0" method="post" action="/representative/resources/emergency-contacts/end<?= $escape($returnSuffix) ?>">
        <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
        <input type="hidden" name="family_id" value="<?= $escape($context->familyId) ?>">
        <input type="hidden" name="assignment_id" value="<?= $escape($assignment->id) ?>">
        <button class="btn btn-link p-0" type="submit">Eliminar asignación</button>
    </form>
    <?php endif; ?>
</article>
<?php endforeach; ?>
</div>
</section>
<?php endif; ?>

<?php if ($resourceScreen === 'authorized-pickups'): ?>
<section class="app-resource-section d-flex flex-column" id="retiros-autorizados" aria-labelledby="portal-pickups-heading">
<h2 id="portal-pickups-heading">Personas autorizadas para retirar</h2>

<div class="<?= $resources->authorizedPickups === [] ? 'order-1' : 'order-3' ?>">
<h3 class="h4">Crear nueva persona autorizada</h3>
<form class="app-form-section" method="post" action="/representative/resources/authorized-pickups/create<?= $escape($returnSuffix) ?>">
    <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
    <input type="hidden" name="family_id" value="<?= $escape($context->familyId) ?>">
    <label>Nombres <input name="names" value="<?= $escape($value('names')) ?>" required></label>
    <label>Tipo de relación
        <select name="relationship_type_id" required>
            <?php foreach ($options->relationshipTypes as $option): ?>
            <option value="<?= $escape($option->id) ?>"><?= $escape($option->name) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Teléfono móvil <input name="mobile_phone" value="<?= $escape($value('mobile_phone')) ?>" required></label>
    <label>Teléfono fijo <input name="phone" value="<?= $escape($value('phone')) ?>"></label>
    <label>Tipo de documento (opcional)
        <select name="document_type_id">
            <option value="">Sin identificación</option>
            <?php foreach ($options->documentTypes as $option): ?>
            <option value="<?= $escape($option->id) ?>"><?= $escape($option->name) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Número de documento (opcional) <input name="document_number" value="<?= $escape($value('document_number')) ?>"></label>
    <label>Observaciones <textarea name="observations"><?= $escape($value('observations')) ?></textarea></label>
    <button type="submit"<?= $options->relationshipTypes === [] ? ' disabled' : '' ?>>Crear persona autorizada</button>
</form>
</div>
<?php if ($options->documentTypes === []): ?>
<p>No hay tipos de documento activos. La persona autorizada puede guardarse sin identificación.</p>
<?php endif; ?>

<div class="<?= $resources->authorizedPickups === [] ? 'order-2' : 'order-1' ?>">
<?php if ($resources->authorizedPickups === []): ?>
<?php $emptyStateTitle = 'No hay personas autorizadas'; $emptyStateText = 'Crea una persona para poder asignarla a un estudiante.'; require dirname(__DIR__) . '/components/empty-state.php'; ?>
<?php endif; ?>
<h3 class="h4 mt-4">Recursos existentes</h3>
<?php foreach ($resources->authorizedPickups as $pickup): ?>
<article class="app-data-card">
    <h3><?= $escape($pickup->names) ?></h3>
    <dl>
        <dt>Teléfono móvil</dt><dd><?= $escape($pickup->mobilePhone) ?></dd>
        <dt>Teléfono fijo</dt><dd><?= $escape($pickup->phone ?? 'No informado') ?></dd>
        <dt>Estado</dt><dd><?php $statusCode = $pickup->status; require dirname(__DIR__) . '/components/status-badge.php'; ?></dd>
    </dl>
    <div class="d-flex flex-wrap align-items-center gap-2">
    <details class="d-inline"><summary class="btn btn-link p-0">Editar</summary><form method="post" action="/representative/resources/authorized-pickups/update<?= $escape($returnSuffix) ?>">
        <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
        <input type="hidden" name="family_id" value="<?= $escape($context->familyId) ?>">
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
        <label>Teléfono fijo <input name="phone" value="<?= $escape($pickup->phone) ?>"></label>
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
        <button type="submit"<?= $options->relationshipTypes === [] ? ' disabled' : '' ?>>Actualizar persona autorizada</button>
    </form>
    </details>
    <span aria-hidden="true">·</span>
    <form class="d-inline m-0" method="post" action="/representative/resources/authorized-pickups/<?= $pickup->status === 'ACTIVE' ? 'deactivate' : 'activate' ?><?= $escape($returnSuffix) ?>">
        <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
        <input type="hidden" name="family_id" value="<?= $escape($context->familyId) ?>">
        <input type="hidden" name="family_authorized_pickup_id" value="<?= $escape($pickup->id) ?>">
        <button class="btn btn-link p-0" type="submit"><?= $pickup->status === 'ACTIVE' ? 'Desactivar' : 'Activar' ?></button>
    </form>
    </div>
</article>
<?php endforeach; ?>
</div>

<div class="<?= $resources->authorizedPickups === [] ? 'order-3' : 'order-2' ?>">
<h3 class="h4 mt-4">Asignaciones</h3>
<h4 class="h5">Asignar persona autorizada</h4>
<form class="app-form-section" method="post" action="/representative/resources/authorized-pickups/assign<?= $escape($returnSuffix) ?>">
    <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
    <input type="hidden" name="family_id" value="<?= $escape($context->familyId) ?>">
    <label>Persona autorizada
        <select name="family_authorized_pickup_id" required>
            <?php foreach ($activePickups as $pickup): ?>
            <option value="<?= $escape($pickup->id) ?>"><?= $escape($pickup->names) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Estudiante
        <select name="student_id" required>
            <?php foreach ($students as $student): ?>
            <option value="<?= $escape($student->studentId) ?>"><?= $escape($student->displayName) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <button type="submit"<?= $activePickups === [] || $students === [] ? ' disabled' : '' ?>>Asignar persona autorizada</button>
</form>

<h3 class="h4">Personas autorizadas asignadas</h3>
<?php if ($authorizedPickupAssignments === []): ?>
<?php $emptyStateTitle = 'Sin historial de retiros'; $emptyStateText = 'No existen asignaciones de personas autorizadas.'; require dirname(__DIR__) . '/components/empty-state.php'; ?>
<?php endif; ?>
<?php foreach ($authorizedPickupAssignments as $assignment): ?>
<article class="app-data-card d-flex flex-wrap align-items-center gap-2">
    <span><?= $escape($pickupName($assignment->familyAuthorizedPickupId)) ?> · <?= $escape($studentName($assignment->studentId)) ?></span><span aria-hidden="true">·</span>
    <span>Desde <?= $escape($timestamp($assignment->startedAt)) ?><?= $assignment->endedAt === null ? '' : ' hasta ' . $escape($timestamp($assignment->endedAt)) ?></span><span aria-hidden="true">·</span>
    <span><?= $assignment->isActive ? 'Activa' : 'Histórica' ?></span>
    <?php if ($assignment->isActive): ?>
    <span aria-hidden="true">·</span>
    <form class="d-inline m-0" method="post" action="/representative/resources/authorized-pickups/end<?= $escape($returnSuffix) ?>">
        <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
        <input type="hidden" name="family_id" value="<?= $escape($context->familyId) ?>">
        <input type="hidden" name="assignment_id" value="<?= $escape($assignment->id) ?>">
        <button class="btn btn-link p-0" type="submit">Eliminar asignación</button>
    </form>
    <?php endif; ?>
</article>
<?php endforeach; ?>
</div>
</section>
<?php endif; ?>

<div class="app-action-group mt-4">
    <a class="btn btn-outline-secondary" href="/representative/data">Volver a Actualización de datos</a>
    <?php if ($returnSuffix !== ''): ?>
    <a class="btn btn-link" href="/representative/enrollment<?= $escape($returnSuffix) ?>">Volver al resumen de matrícula</a>
    <?php endif; ?>
</div>
