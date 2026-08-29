<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$optional = static fn (mixed $value): string => $value === null || $value === '' ? 'No registrado' : (string) $value;
$statusCode = $person->status->value;
?>
<header class="app-page-header d-flex flex-column flex-md-row justify-content-between gap-3 align-items-md-start">
    <div>
        <p class="text-uppercase fw-semibold text-primary mb-2">Personas</p>
        <h1 class="display-6 fw-bold mb-2">Detalle de persona</h1>
        <p class="text-body-secondary mb-0">Información personal y de contacto registrada.</p>
    </div>
    <div class="app-action-group">
        <a class="btn btn-primary" href="/persons/edit?id=<?= $escape($person->id) ?>">Editar persona</a>
        <a class="btn btn-outline-secondary" href="/persons">Volver</a>
    </div>
</header>

<div class="row g-4">
    <section class="col-lg-7" aria-labelledby="person-identity-heading">
        <div class="app-data-card h-100">
            <h2 class="h4" id="person-identity-heading">Identidad y datos personales</h2>
            <dl class="app-data-list">
                <dt>Nombre completo</dt>
                <dd><?= $escape(trim(implode(' ', array_filter([$person->firstName, $person->middleName, $person->firstSurname, $person->secondSurname])))) ?></dd>
                <dt>ID interno</dt><dd><?= $escape($person->id) ?></dd>
                <dt>Tipo de documento (ID)</dt><dd><?= $escape($optional($person->documentTypeId)) ?></dd>
                <dt>Número de documento</dt><dd><?= $escape($optional($person->documentNumber)) ?></dd>
                <dt>Fecha de nacimiento</dt><dd><?= $escape($person->birthDate->format('Y-m-d')) ?></dd>
                <dt>Sexo (ID)</dt><dd><?= $escape($person->sexId) ?></dd>
                <dt>Estado civil (ID)</dt><dd><?= $escape($optional($person->maritalStatusId)) ?></dd>
                <dt>Nivel educativo (ID)</dt><dd><?= $escape($optional($person->educationLevelId)) ?></dd>
            </dl>
        </div>
    </section>
    <section class="col-lg-5" aria-labelledby="person-contact-heading">
        <div class="app-data-card h-100">
            <h2 class="h4" id="person-contact-heading">Contacto y estado</h2>
            <dl class="app-data-list">
                <dt>Correo electrónico</dt><dd><?= $escape($optional($person->email)) ?></dd>
                <dt>Teléfono móvil</dt><dd><?= $escape($optional($person->mobilePhone)) ?></dd>
                <dt>Teléfono fijo</dt><dd><?= $escape($optional($person->landlinePhone)) ?></dd>
                <dt>Estado</dt><dd><?php require dirname(__DIR__) . '/components/status-badge.php'; ?></dd>
            </dl>
        </div>
    </section>
</div>
