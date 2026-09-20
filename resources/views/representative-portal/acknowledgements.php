<?php

declare(strict_types=1);

use App\InstitutionalDocuments\Application\RepresentativePortal\Dto\RepresentativeAcknowledgementPortalState;

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$portalState = ($state ?? null) instanceof RepresentativeAcknowledgementPortalState ? $state : null;
$safeLink = static function (string $url): bool {
    $scheme = parse_url($url, PHP_URL_SCHEME);

    return is_string($scheme) && in_array(strtolower($scheme), ['http', 'https'], true);
};
?>
<?php
$breadcrumbItems = [
    ['label' => 'Portal', 'url' => '/representative'],
    ['label' => 'Aceptaciones institucionales'],
];
require dirname(__DIR__) . '/components/breadcrumb.php';
?>

<header class="app-page-header">
    <h1>Aceptaciones institucionales</h1>
    <p class="text-body-secondary">Abre y revisa cada documento o recurso mostrado. Marca su casilla y confirma cuando hayas revisado todos los requisitos.</p>
</header>

<?php if ($portalState === null): ?>
<?php
$emptyStateTitle = 'No existe un período académico activo';
$emptyStateText = 'Las aceptaciones estarán disponibles cuando la institución configure un período activo.';
require dirname(__DIR__) . '/components/empty-state.php';
?>
<?php else: ?>
<section class="app-context-banner" aria-labelledby="acknowledgement-period-heading">
    <div>
        <p class="text-body-secondary mb-1" id="acknowledgement-period-heading">Período académico</p>
        <p class="h4 mb-1"><?= $escape($portalState->context->academicPeriodName) ?></p>
    </div>
    <?php $statusCode = strtoupper($portalState->status); require dirname(__DIR__) . '/components/status-badge.php'; ?>
</section>

<?php if ($portalState->status === 'completed'): ?>
<section class="app-data-card" aria-labelledby="acknowledgement-completed-heading">
    <h2 class="h4" id="acknowledgement-completed-heading">Confirmación completada</h2>
    <p>Completada el <?= $escape($portalState->completedAt?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s') ?? '') ?> UTC.</p>
    <p class="mb-0">No se requiere otra confirmación para este período académico.</p>
</section>
<?php elseif ($portalState->status === 'not_required'): ?>
<section class="app-data-card" aria-labelledby="acknowledgement-not-required-heading">
    <h2 class="h4" id="acknowledgement-not-required-heading">Sin requisitos pendientes</h2>
    <p class="mb-0">No existen aceptaciones institucionales requeridas para este período académico.</p>
</section>
<?php else: ?>
<section aria-labelledby="active-requirements-heading">
    <h2 class="h4" id="active-requirements-heading">Requisitos por revisar</h2>
    <p>Revisa cada requisito institucional vigente antes de confirmar.</p>
    <?php if ($portalState->activeRequirements === []): ?>
    <?php
    $emptyStateTitle = 'No hay requisitos activos';
    $emptyStateText = 'No existe contenido institucional pendiente de revisión.';
    require dirname(__DIR__) . '/components/empty-state.php';
    ?>
    <?php else: ?>
    <form method="post" action="/representative/acknowledgements/complete">
        <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken ?? '') ?>">
        <div class="vstack gap-3 mb-4">
        <?php foreach ($portalState->activeRequirements as $requirement): ?>
        <article class="app-data-card mb-0">
            <div class="form-check">
                <input
                    class="form-check-input"
                    id="requirement-<?= $escape($requirement->id) ?>"
                    type="checkbox"
                    name="acknowledged_requirement_ids[]"
                    value="<?= $escape($requirement->id) ?>"
                    required
                >
                <label class="form-check-label fw-semibold" for="requirement-<?= $escape($requirement->id) ?>">
                    <?= $escape($requirement->title) ?>
                </label>
            </div>
            <p class="mt-3 mb-2">
                <?php if ($safeLink($requirement->url)): ?>
                <a href="<?= $escape($requirement->url) ?>" target="_blank" rel="noopener noreferrer">
                    Abrir documento o referencia externa
                </a>
                <?php else: ?>
                <span class="text-break"><?= $escape($requirement->url) ?></span>
                <?php endif; ?>
            </p>
            <?php if ($requirement->officialReference !== null): ?>
            <p class="mb-0"><strong>Referencia oficial:</strong> <?= $escape($requirement->officialReference) ?></p>
            <?php endif; ?>
        </article>
        <?php endforeach; ?>
        </div>
        <div class="app-consequential-panel">
            <h2 class="h5">Confirmación importante</h2>
            <p>Confirma únicamente después de revisar todos los requisitos mostrados.</p>
            <button class="btn btn-primary" type="submit">Confirmar que revisé estos requisitos</button>
        </div>
    </form>
    <?php endif; ?>
</section>
<?php endif; ?>
<?php endif; ?>
