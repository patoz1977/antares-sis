<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$timestamp = static fn (DateTimeImmutable $value): string => $value->format(DateTimeImmutable::ATOM);
?>
<header class="app-page-header d-flex flex-column flex-lg-row justify-content-between gap-3 align-items-lg-start">
    <div>
        <p class="text-uppercase fw-semibold text-primary mb-2">Familias</p>
        <h1 class="display-6 fw-bold mb-2"><?= $escape($family->displayName) ?></h1>
        <p class="text-body-secondary mb-0">Consulta las personas asociadas y administra los recursos de esta familia.</p>
    </div>
    <div class="app-action-group">
        <a class="btn btn-primary" href="/families/students/create?family_id=<?= $escape($family->id) ?>">Agregar estudiante</a>
        <a class="btn btn-outline-primary" href="/families/resources?family_id=<?= $escape($family->id) ?>">Administrar recursos</a>
        <a class="btn btn-outline-secondary" href="/families">Volver</a>
    </div>
</header>

<section class="app-data-card" aria-labelledby="family-identity-heading">
    <h2 class="h4" id="family-identity-heading">Identidad familiar</h2>
    <dl class="app-data-list">
        <dt>Nombre visible</dt><dd><?= $escape($family->displayName) ?></dd>
        <dt>Estado</dt><dd><?php $statusCode = $family->status->value; require dirname(__DIR__) . '/components/status-badge.php'; ?></dd>
    </dl>
</section>

<section class="mb-5" aria-labelledby="family-representatives-heading">
<h2 class="h3" id="family-representatives-heading">Representantes y membresías</h2>
<?php if ($family->representatives === []): ?>
<?php $emptyStateTitle = 'No hay representantes asociados'; $emptyStateText = 'Esta familia no tiene membresías de representante registradas.'; require dirname(__DIR__) . '/components/empty-state.php'; ?>
<?php else: ?>
<div class="row g-4">
<?php foreach ($family->representatives as $membership): ?>
<div class="col-lg-6">
<article class="app-data-card h-100">
    <h3 class="h5"><?= $membership->isPrimary && $membership->isActive ? 'Representante principal activo' : 'Membresía de representante' ?></h3>
    <dl class="app-data-list">
        <dt>Representante</dt><dd><?= $escape($memberLabels->representative($membership->representativeId)) ?></dd>
        <dt>Relación</dt><dd><?= $escape($memberLabels->relationship($membership->relationshipTypeId)) ?></dd>
        <dt>Principal</dt><dd><?= $membership->isPrimary ? 'Sí' : 'No' ?></dd>
        <dt>Vigencia</dt><dd><?= $membership->isActive ? 'Activa' : 'Histórica' ?></dd>
        <dt>Inicio</dt><dd><?= $escape($timestamp($membership->startedAt)) ?></dd>
        <dt>Fin</dt><dd><?= $membership->endedAt === null ? 'Sin finalizar' : $escape($timestamp($membership->endedAt)) ?></dd>
    </dl>
    <a class="btn btn-outline-primary" href="/representative-users/manage?representative_id=<?= $escape($membership->representativeId) ?>">Administrar usuario del representante</a>
</article>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
</section>

<section class="mb-4" aria-labelledby="family-students-heading">
<h2 class="h3" id="family-students-heading">Estudiantes y membresías</h2>
<?php if ($family->students === []): ?>
<?php $emptyStateTitle = 'No hay estudiantes asociados'; $emptyStateText = 'Agrega un estudiante para crear su membresía familiar.'; require dirname(__DIR__) . '/components/empty-state.php'; ?>
<?php else: ?>
<div class="row g-4">
<?php foreach ($family->students as $membership): ?>
<div class="col-lg-6">
<article class="app-data-card h-100">
    <h3 class="h5">Membresía de estudiante</h3>
    <dl class="app-data-list">
        <dt>Estudiante</dt><dd><?= $escape($memberLabels->student($membership->studentId)) ?></dd>
        <dt>Vigencia</dt><dd><?= $membership->isActive ? 'Activa' : 'Histórica' ?></dd>
        <dt>Inicio</dt><dd><?= $escape($timestamp($membership->startedAt)) ?></dd>
        <dt>Fin</dt><dd><?= $membership->endedAt === null ? 'Sin finalizar' : $escape($timestamp($membership->endedAt)) ?></dd>
    </dl>
</article>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
</section>
