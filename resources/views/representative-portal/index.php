<?php

declare(strict_types=1);

use App\IdentityAccess\Application\AuthorizedFamily;
use App\IdentityAccess\Application\FamilyContext;
use App\InstitutionalDocuments\Application\RepresentativePortal\Dto\RepresentativeAcknowledgementPortalState;

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$families = is_array($authorizedFamilies ?? null) ? $authorizedFamilies : [];
$currentContext = ($context ?? null) instanceof FamilyContext ? $context : null;
$showSelector = count($families) > 1;
$acknowledgements = ($acknowledgementState ?? null) instanceof RepresentativeAcknowledgementPortalState
    ? $acknowledgementState
    : null;
?>
<header class="app-page-header">
    <h1>Portal de representantes</h1>
    <p class="text-body-secondary">Gestiona la información autorizada de tu familia y sus matrículas.</p>
</header>

<?php if ($currentContext !== null): ?>
<section class="app-context-banner" aria-labelledby="family-context-heading">
    <div>
        <p class="text-body-secondary mb-1" id="family-context-heading">Familia actual</p>
        <p class="h4 mb-0"><?= $escape($currentContext->familyDisplayName) ?></p>
    </div>
    <?php if ($showSelector): ?>
    <a class="btn btn-outline-primary" href="#family-selector">Cambiar familia</a>
    <?php endif; ?>
</section>
<?php elseif (($requiresSelection ?? false) === true): ?>
<div class="alert alert-info" role="status">Selecciona una familia autorizada para continuar.</div>
<?php endif; ?>

<div class="row g-4 mb-4">
    <div class="col-12 col-lg-5">
        <section class="app-data-card h-100" aria-labelledby="acknowledgements-status-heading">
            <div class="d-flex flex-wrap gap-2 justify-content-between align-items-start">
                <div>
                    <h2 class="h4" id="acknowledgements-status-heading">Aceptaciones institucionales</h2>
                    <?php if ($acknowledgements === null): ?>
                    <p class="mb-0">No existe un período académico activo configurado.</p>
                    <?php elseif ($acknowledgements->status === 'pending'): ?>
                    <p>Debes revisar las aceptaciones institucionales antes de mantener la información familiar.</p>
                    <a class="btn btn-primary" href="/representative/acknowledgements">Revisar aceptaciones</a>
                    <?php elseif ($acknowledgements->status === 'completed'): ?>
                    <p>Completadas para <?= $escape($acknowledgements->context->academicPeriodName) ?>.</p>
                    <a class="btn btn-outline-primary" href="/representative/acknowledgements">Ver aceptaciones</a>
                    <?php else: ?>
                    <p class="mb-0">No se requieren aceptaciones para <?= $escape($acknowledgements->context->academicPeriodName) ?>.</p>
                    <?php endif; ?>
                </div>
                <?php if ($acknowledgements !== null): ?>
                <?php $statusCode = strtoupper($acknowledgements->status); require dirname(__DIR__) . '/components/status-badge.php'; ?>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <?php if ($currentContext !== null): ?>
    <div class="col-12 col-lg-7">
        <section aria-labelledby="portal-destinations-heading">
            <h2 class="h4" id="portal-destinations-heading">¿Qué deseas hacer?</h2>
            <div class="row g-3">
                <div class="col-12 col-md-6">
                    <a class="card app-module-card text-decoration-none" href="/representative/enrollment">
                        <span class="card-body">
                            <span class="app-module-icon mb-3"><i class="bi bi-journal-check" aria-hidden="true"></i></span>
                            <span class="h5 d-block">Matrícula</span>
                            <span class="text-body-secondary">Actualiza información y revisa la matrícula de tus estudiantes.</span>
                        </span>
                    </a>
                </div>
                <?php if ($acknowledgements?->satisfied === true): ?>
                <div class="col-12 col-md-6">
                    <a class="card app-module-card text-decoration-none" href="/representative/resources">
                        <span class="card-body">
                            <span class="app-module-icon mb-3"><i class="bi bi-people" aria-hidden="true"></i></span>
                            <span class="h5 d-block">Recursos familiares</span>
                            <span class="text-body-secondary">Gestiona direcciones, contactos de emergencia y retiros autorizados.</span>
                        </span>
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </section>
    </div>
    <?php endif; ?>
</div>

<?php if ($showSelector): ?>
<section class="app-form-section app-content-narrow" id="family-selector" aria-labelledby="family-selector-heading">
    <h2 class="h4" id="family-selector-heading"><?= $currentContext === null ? 'Seleccionar familia' : 'Cambiar familia' ?></h2>
    <p class="text-body-secondary">Elige la familia con la que deseas continuar.</p>
    <form method="post" action="/representative/family">
        <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken ?? '') ?>">
        <fieldset>
            <legend class="h6">Familias autorizadas</legend>
            <div class="vstack gap-2 mb-3">
            <?php foreach ($families as $family): ?>
                <?php if ($family instanceof AuthorizedFamily): ?>
                <div class="form-check">
                    <input
                        class="form-check-input"
                        id="family-<?= $escape($family->familyId) ?>"
                        type="radio"
                        name="family_id"
                        value="<?= $escape($family->familyId) ?>"
                        <?= $currentContext?->familyId === $family->familyId ? 'checked' : '' ?>
                        required
                    >
                    <label class="form-check-label" for="family-<?= $escape($family->familyId) ?>">
                        <?= $escape($family->displayName) ?>
                    </label>
                </div>
                <?php endif; ?>
            <?php endforeach; ?>
            </div>
        </fieldset>
        <button class="btn btn-primary" type="submit">Usar esta familia</button>
    </form>
</section>
<?php endif; ?>
