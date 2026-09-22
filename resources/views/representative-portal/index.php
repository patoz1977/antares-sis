<?php

declare(strict_types=1);

use App\IdentityAccess\Application\AuthorizedFamily;
use App\IdentityAccess\Application\FamilyContext;

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$families = is_array($authorizedFamilies ?? null) ? $authorizedFamilies : [];
$currentContext = ($context ?? null) instanceof FamilyContext ? $context : null;
$showSelector = count($families) > 1;
$familyMembers = is_array($members ?? null) ? $members : null;
?>
<header class="app-page-header">
    <h1>Bienvenido al portal de representantes</h1>
    <p class="text-body-secondary">Consulta tu familia y elige qué necesitas hacer.</p>
</header>

<?php if ($currentContext !== null): ?>
<section class="app-context-banner" aria-labelledby="family-context-heading">
    <div>
        <p class="text-body-secondary mb-1" id="family-context-heading">Familia actual</p>
        <p class="h4 mb-0"><?= $escape($currentContext->familyDisplayName) ?></p>
    </div>
</section>
<?php elseif (($requiresSelection ?? false) === true): ?>
<div class="alert alert-info" role="status">Selecciona una familia autorizada para continuar.</div>
<?php endif; ?>

<?php if ($showSelector): ?>
<?php if ($currentContext !== null): ?>
<details class="app-form-section app-content-narrow" id="family-selector">
    <summary class="btn btn-outline-primary">Cambiar familia</summary>
<?php else: ?>
<section class="app-form-section app-content-narrow" id="family-selector" aria-labelledby="family-selector-heading">
<?php endif; ?>
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
<?php if ($currentContext !== null): ?></details><?php else: ?></section><?php endif; ?>
<?php endif; ?>

<?php if ($currentContext !== null && $familyMembers !== null): ?>
<section class="app-data-card" aria-labelledby="family-members-heading">
    <h2 class="h4" id="family-members-heading">Tu familia</h2>
    <h3 class="h6">Tú</h3>
    <p><?= $escape($familyMembers['self']['name']) ?> — <?= $escape($familyMembers['self']['relationship']) ?></p>
    <?php if ($familyMembers['others'] !== []): ?>
    <h3 class="h6">Otros representantes</h3>
    <ul><?php foreach ($familyMembers['others'] as $member): ?>
        <li><?= $escape($member['name']) ?> — <?= $escape($member['relationship']) ?></li>
    <?php endforeach; ?></ul>
    <?php endif; ?>
    <h3 class="h6">Estudiantes</h3>
    <?php if ($familyMembers['students'] === []): ?>
    <p>No hay estudiantes activos en esta familia.</p>
    <?php else: ?>
    <ul><?php foreach ($familyMembers['students'] as $member): ?>
        <li><?= $escape($member['name']) ?></li>
    <?php endforeach; ?></ul>
    <?php endif; ?>
</section>

<section aria-labelledby="portal-destinations-heading">
    <h2 class="h4" id="portal-destinations-heading">¿Qué deseas hacer?</h2>
    <div class="row g-3">
        <div class="col-12 col-md-6">
            <a class="card app-module-card text-decoration-none h-100" href="/representative/data">
                <span class="card-body"><span class="h5 d-block">Actualización de datos</span>
                    <span class="text-body-secondary d-block">Mantén tus datos, los de tus estudiantes y los recursos de tu familia.</span>
                    <span class="btn btn-primary mt-3">Abrir Actualización de datos</span></span>
            </a>
        </div>
        <div class="col-12 col-md-6">
            <a class="card app-module-card text-decoration-none h-100" href="/representative/enrollment">
                <span class="card-body"><span class="h5 d-block">Matrícula</span>
                    <span class="text-body-secondary d-block">Revisa las aceptaciones y continúa la matrícula del período actual.</span>
                    <span class="btn btn-primary mt-3">Abrir Matrícula</span></span>
            </a>
        </div>
    </div>
</section>
<?php endif; ?>
