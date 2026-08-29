<?php

declare(strict_types=1);

use App\Family\Application\RepresentativeResources\Dto\RepresentativeFamilyStudentOption;

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
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
    ['label' => 'Portal', 'url' => '/representative'],
    ['label' => 'Recursos familiares'],
];
require dirname(__DIR__) . '/components/breadcrumb.php';
?>
<header class="app-page-header">
    <h1>Recursos familiares</h1>
    <p class="text-body-secondary">Gestiona direcciones, contactos de emergencia y personas autorizadas para retirar.</p>
</header>

<section class="app-context-banner" aria-labelledby="resources-family-heading">
    <div>
        <p class="text-body-secondary mb-1" id="resources-family-heading">Familia actual</p>
        <p class="h4 mb-0"><?= $escape($context->familyDisplayName) ?></p>
    </div>
    <?php if (($canChangeFamily ?? false) === true): ?>
    <a class="btn btn-outline-primary" href="/representative">Cambiar familia</a>
    <?php endif; ?>
</section>

<nav class="app-section-nav mb-4" aria-label="Tipos de recursos familiares">
    <a href="#direcciones">Direcciones</a>
    <a href="#contactos-emergencia">Contactos de emergencia</a>
    <a href="#retiros-autorizados">Retiros autorizados</a>
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

<section class="app-resource-section" id="direcciones" aria-labelledby="portal-addresses-heading">
<h2 id="portal-addresses-heading">Direcciones</h2>

<h3 class="h4">Crear dirección</h3>
<form class="app-form-section" method="post" action="/representative/resources/addresses/create">
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

<?php if ($resources->addresses === []): ?>
<?php $emptyStateTitle = 'No hay direcciones registradas'; $emptyStateText = 'Crea una dirección para poder asignarla.'; require dirname(__DIR__) . '/components/empty-state.php'; ?>
<?php else: ?>
<?php foreach ($resources->addresses as $address): ?>
<article class="app-data-card">
    <h3><?= $escape($address->label) ?></h3>
    <dl>
        <dt>Calle principal</dt><dd><?= $escape($address->mainStreet) ?></dd>
        <dt>Número</dt><dd><?= $escape($address->streetNumber ?? 'No informado') ?></dd>
        <dt>Calle secundaria</dt><dd><?= $escape($address->secondaryStreet ?? 'No informada') ?></dd>
        <dt>Sector</dt><dd><?= $escape($address->sector ?? 'No informado') ?></dd>
        <dt>Referencia</dt><dd><?= $escape($address->reference ?? 'No informada') ?></dd>
        <dt>Latitud</dt><dd><?= $escape($address->latitude ?? 'No informada') ?></dd>
        <dt>Longitud</dt><dd><?= $escape($address->longitude ?? 'No informada') ?></dd>
        <dt>Estado</dt><dd><?php $statusCode = $address->status; require dirname(__DIR__) . '/components/status-badge.php'; ?></dd>
    </dl>
    <form method="post" action="/representative/resources/addresses/update">
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
    <form method="post" action="/representative/resources/addresses/<?= $address->status === 'ACTIVE' ? 'deactivate' : 'activate' ?>">
        <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
        <input type="hidden" name="family_id" value="<?= $escape($context->familyId) ?>">
        <input type="hidden" name="family_address_id" value="<?= $escape($address->id) ?>">
        <button type="submit"><?= $address->status === 'ACTIVE' ? 'Desactivar' : 'Activar' ?> dirección</button>
    </form>
</article>
<?php endforeach; ?>
<?php endif; ?>

<h3 class="h4">Asignar dirección al representante</h3>
<form class="app-form-section" method="post" action="/representative/resources/address">
    <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
    <input type="hidden" name="family_id" value="<?= $escape($context->familyId) ?>">
    <label>Dirección
        <select name="family_address_id" required>
            <?php foreach ($activeAddresses as $address): ?>
            <option value="<?= $escape($address->id) ?>"><?= $escape($address->label) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Inicio <input name="started_at" type="datetime-local" required></label>
    <button type="submit"<?= $activeAddresses === [] ? ' disabled' : '' ?>>Asignar mi dirección</button>
</form>

<h3 class="h4">Asignar dirección a estudiante</h3>
<form class="app-form-section" method="post" action="/representative/resources/students/address">
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
    <label>Inicio <input name="started_at" type="datetime-local" required></label>
    <button type="submit"<?= $students === [] || $activeAddresses === [] ? ' disabled' : '' ?>>Asignar dirección al estudiante</button>
</form>

<h3 class="h4">Historial de direcciones del representante</h3>
<?php if ($ownRepresentativeAddressAssignments === []): ?>
<?php $emptyStateTitle = 'Sin historial de direcciones'; $emptyStateText = 'No existen asignaciones para el representante.'; require dirname(__DIR__) . '/components/empty-state.php'; ?>
<?php endif; ?>
<?php foreach ($ownRepresentativeAddressAssignments as $assignment): ?>
<article class="app-data-card">
    <p><?= $escape($addressLabel($assignment->familyAddressId)) ?> — <?= $assignment->isActive ? 'Activa' : 'Histórica' ?></p>
    <p><?= $escape($timestamp($assignment->startedAt)) ?> a <?= $assignment->endedAt === null ? 'Vigente' : $escape($timestamp($assignment->endedAt)) ?></p>
    <?php if ($assignment->isActive): ?>
    <form method="post" action="/representative/resources/address/end">
        <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
        <input type="hidden" name="family_id" value="<?= $escape($context->familyId) ?>">
        <input type="hidden" name="assignment_id" value="<?= $escape($assignment->id) ?>">
        <label>Fin <input name="ended_at" type="datetime-local" required></label>
        <button type="submit">Finalizar mi dirección</button>
    </form>
    <?php endif; ?>
</article>
<?php endforeach; ?>

<h3 class="h4">Historial de direcciones de estudiantes</h3>
<?php if ($studentAddressAssignments === []): ?>
<?php $emptyStateTitle = 'Sin historial de estudiantes'; $emptyStateText = 'No existen asignaciones de dirección para estudiantes.'; require dirname(__DIR__) . '/components/empty-state.php'; ?>
<?php endif; ?>
<?php foreach ($studentAddressAssignments as $assignment): ?>
<article class="app-data-card">
    <p><?= $escape($studentName($assignment->studentId)) ?> — <?= $escape($addressLabel($assignment->familyAddressId)) ?> — <?= $assignment->isActive ? 'Activa' : 'Histórica' ?></p>
    <p><?= $escape($timestamp($assignment->startedAt)) ?> a <?= $assignment->endedAt === null ? 'Vigente' : $escape($timestamp($assignment->endedAt)) ?></p>
    <?php if ($assignment->isActive): ?>
    <form method="post" action="/representative/resources/students/address/end">
        <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
        <input type="hidden" name="family_id" value="<?= $escape($context->familyId) ?>">
        <input type="hidden" name="assignment_id" value="<?= $escape($assignment->id) ?>">
        <label>Fin <input name="ended_at" type="datetime-local" required></label>
        <button type="submit">Finalizar dirección del estudiante</button>
    </form>
    <?php endif; ?>
</article>
<?php endforeach; ?>
</section>

<section class="app-resource-section" id="contactos-emergencia" aria-labelledby="portal-emergency-heading">
<h2 id="portal-emergency-heading">Contactos de emergencia</h2>
<?php if ($options->relationshipTypes === []): ?>
<p class="alert alert-warning" role="alert">No hay tipos de relación activos. Los formularios de contactos y retiros autorizados están deshabilitados.</p>
<?php endif; ?>

<h3 class="h4">Crear contacto de emergencia</h3>
<form class="app-form-section" method="post" action="/representative/resources/emergency-contacts/create">
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
    <label>Teléfono <input name="phone" value="<?= $escape($value('phone')) ?>"></label>
    <label>Correo electrónico <input name="email" type="email" value="<?= $escape($value('email')) ?>"></label>
    <label>Observaciones <textarea name="observations"><?= $escape($value('observations')) ?></textarea></label>
    <button type="submit"<?= $options->relationshipTypes === [] ? ' disabled' : '' ?>>Crear contacto</button>
</form>

<?php if ($resources->emergencyContacts === []): ?>
<?php $emptyStateTitle = 'No hay contactos de emergencia'; $emptyStateText = 'Crea un contacto para poder asignarlo a un estudiante.'; require dirname(__DIR__) . '/components/empty-state.php'; ?>
<?php endif; ?>
<?php foreach ($resources->emergencyContacts as $contact): ?>
<article class="app-data-card">
    <h3><?= $escape($contact->names) ?></h3>
    <dl>
        <dt>Teléfono móvil</dt><dd><?= $escape($contact->mobilePhone) ?></dd>
        <dt>Teléfono</dt><dd><?= $escape($contact->phone ?? 'No informado') ?></dd>
        <dt>Correo electrónico</dt><dd><?= $escape($contact->email ?? 'No informado') ?></dd>
        <dt>Observaciones</dt><dd><?= $escape($contact->observations ?? 'No informadas') ?></dd>
        <dt>Estado</dt><dd><?php $statusCode = $contact->status; require dirname(__DIR__) . '/components/status-badge.php'; ?></dd>
    </dl>
    <form method="post" action="/representative/resources/emergency-contacts/update">
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
        <label>Teléfono <input name="phone" value="<?= $escape($contact->phone) ?>"></label>
        <label>Correo electrónico <input name="email" type="email" value="<?= $escape($contact->email) ?>"></label>
        <label>Observaciones <textarea name="observations"><?= $escape($contact->observations) ?></textarea></label>
        <button type="submit"<?= $options->relationshipTypes === [] ? ' disabled' : '' ?>>Actualizar contacto</button>
    </form>
    <form method="post" action="/representative/resources/emergency-contacts/<?= $contact->status === 'ACTIVE' ? 'deactivate' : 'activate' ?>">
        <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
        <input type="hidden" name="family_id" value="<?= $escape($context->familyId) ?>">
        <input type="hidden" name="family_emergency_contact_id" value="<?= $escape($contact->id) ?>">
        <button type="submit"><?= $contact->status === 'ACTIVE' ? 'Desactivar' : 'Activar' ?> contacto</button>
    </form>
</article>
<?php endforeach; ?>

<h3 class="h4">Asignar contacto de emergencia</h3>
<form class="app-form-section" method="post" action="/representative/resources/emergency-contacts/assign">
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
    <label>Prioridad <input name="priority" type="number" min="1"></label>
    <label>Inicio <input name="started_at" type="datetime-local" required></label>
    <button type="submit"<?= $activeContacts === [] || $students === [] ? ' disabled' : '' ?>>Asignar contacto</button>
</form>

<h3 class="h4">Historial de contactos de emergencia</h3>
<?php if ($emergencyContactAssignments === []): ?>
<?php $emptyStateTitle = 'Sin historial de contactos'; $emptyStateText = 'No existen asignaciones de contactos de emergencia.'; require dirname(__DIR__) . '/components/empty-state.php'; ?>
<?php endif; ?>
<?php foreach ($emergencyContactAssignments as $assignment): ?>
<article class="app-data-card">
    <p><?= $escape($contactName($assignment->familyEmergencyContactId)) ?> — <?= $escape($studentName($assignment->studentId)) ?> — Prioridad <?= $escape($assignment->priority ?? 'No informada') ?> — <?= $assignment->isActive ? 'Activa' : 'Histórica' ?></p>
    <p><?= $escape($timestamp($assignment->startedAt)) ?> a <?= $assignment->endedAt === null ? 'Vigente' : $escape($timestamp($assignment->endedAt)) ?></p>
    <?php if ($assignment->isActive): ?>
    <form method="post" action="/representative/resources/emergency-contacts/end">
        <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
        <input type="hidden" name="family_id" value="<?= $escape($context->familyId) ?>">
        <input type="hidden" name="assignment_id" value="<?= $escape($assignment->id) ?>">
        <label>Fin <input name="ended_at" type="datetime-local" required></label>
        <button type="submit">Finalizar asignación del contacto</button>
    </form>
    <?php endif; ?>
</article>
<?php endforeach; ?>
</section>

<section class="app-resource-section" id="retiros-autorizados" aria-labelledby="portal-pickups-heading">
<h2 id="portal-pickups-heading">Personas autorizadas para retirar</h2>

<h3 class="h4">Crear persona autorizada</h3>
<form class="app-form-section" method="post" action="/representative/resources/authorized-pickups/create">
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
    <label>Teléfono <input name="phone" value="<?= $escape($value('phone')) ?>"></label>
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
<?php if ($options->documentTypes === []): ?>
<p>No hay tipos de documento activos. La persona autorizada puede guardarse sin identificación.</p>
<?php endif; ?>

<?php if ($resources->authorizedPickups === []): ?>
<?php $emptyStateTitle = 'No hay personas autorizadas'; $emptyStateText = 'Crea una persona para poder asignarla a un estudiante.'; require dirname(__DIR__) . '/components/empty-state.php'; ?>
<?php endif; ?>
<?php foreach ($resources->authorizedPickups as $pickup): ?>
<article class="app-data-card">
    <h3><?= $escape($pickup->names) ?></h3>
    <dl>
        <dt>Teléfono móvil</dt><dd><?= $escape($pickup->mobilePhone) ?></dd>
        <dt>Teléfono</dt><dd><?= $escape($pickup->phone ?? 'No informado') ?></dd>
        <dt>Número de documento</dt><dd><?= $escape($pickup->documentNumber ?? 'No informado') ?></dd>
        <dt>Observaciones</dt><dd><?= $escape($pickup->observations ?? 'No informadas') ?></dd>
        <dt>Estado</dt><dd><?php $statusCode = $pickup->status; require dirname(__DIR__) . '/components/status-badge.php'; ?></dd>
    </dl>
    <form method="post" action="/representative/resources/authorized-pickups/update">
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
        <button type="submit"<?= $options->relationshipTypes === [] ? ' disabled' : '' ?>>Actualizar persona autorizada</button>
    </form>
    <form method="post" action="/representative/resources/authorized-pickups/<?= $pickup->status === 'ACTIVE' ? 'deactivate' : 'activate' ?>">
        <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
        <input type="hidden" name="family_id" value="<?= $escape($context->familyId) ?>">
        <input type="hidden" name="family_authorized_pickup_id" value="<?= $escape($pickup->id) ?>">
        <button type="submit"><?= $pickup->status === 'ACTIVE' ? 'Desactivar' : 'Activar' ?> persona autorizada</button>
    </form>
</article>
<?php endforeach; ?>

<h3 class="h4">Asignar persona autorizada</h3>
<form class="app-form-section" method="post" action="/representative/resources/authorized-pickups/assign">
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
    <label>Inicio <input name="started_at" type="datetime-local" required></label>
    <button type="submit"<?= $activePickups === [] || $students === [] ? ' disabled' : '' ?>>Asignar persona autorizada</button>
</form>

<h3 class="h4">Historial de retiros autorizados</h3>
<?php if ($authorizedPickupAssignments === []): ?>
<?php $emptyStateTitle = 'Sin historial de retiros'; $emptyStateText = 'No existen asignaciones de personas autorizadas.'; require dirname(__DIR__) . '/components/empty-state.php'; ?>
<?php endif; ?>
<?php foreach ($authorizedPickupAssignments as $assignment): ?>
<article class="app-data-card">
    <p><?= $escape($pickupName($assignment->familyAuthorizedPickupId)) ?> — <?= $escape($studentName($assignment->studentId)) ?> — <?= $assignment->isActive ? 'Activa' : 'Histórica' ?></p>
    <p><?= $escape($timestamp($assignment->startedAt)) ?> a <?= $assignment->endedAt === null ? 'Vigente' : $escape($timestamp($assignment->endedAt)) ?></p>
    <?php if ($assignment->isActive): ?>
    <form method="post" action="/representative/resources/authorized-pickups/end">
        <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken) ?>">
        <input type="hidden" name="family_id" value="<?= $escape($context->familyId) ?>">
        <input type="hidden" name="assignment_id" value="<?= $escape($assignment->id) ?>">
        <label>Fin <input name="ended_at" type="datetime-local" required></label>
        <button type="submit">Finalizar asignación</button>
    </form>
    <?php endif; ?>
</article>
<?php endforeach; ?>
</section>

<div class="app-action-group mt-4">
    <a class="btn btn-outline-secondary" href="/representative">Volver al portal</a>
</div>
