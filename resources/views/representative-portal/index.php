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
<h1>Representative Portal</h1>

<section>
    <h2>Institutional Acknowledgements</h2>
    <?php if ($acknowledgements === null): ?>
    <p>No active Academic Period is currently configured.</p>
    <?php elseif ($acknowledgements->status === 'pending'): ?>
    <p>Institutional Acknowledgements are required before you can update family data.</p>
    <p><a href="/representative/acknowledgements">Review Institutional Acknowledgements</a></p>
    <?php elseif ($acknowledgements->status === 'completed'): ?>
    <p>Completed for <?= $escape($acknowledgements->context->academicPeriodName) ?>.</p>
    <p><a href="/representative/acknowledgements">View Institutional Acknowledgements</a></p>
    <?php else: ?>
    <p>No institutional acknowledgements are required for <?= $escape($acknowledgements->context->academicPeriodName) ?>.</p>
    <?php endif; ?>
</section>

<?php if ($currentContext !== null): ?>
<p>Current family: <strong><?= $escape($currentContext->familyDisplayName) ?></strong></p>
<p>Your authorized family context is ready.</p>
<p><a href="/representative/enrollment">Enrollment / Student information</a></p>
<?php if ($acknowledgements?->satisfied === true): ?>
<p><a href="/representative/resources">Manage family resources</a></p>
<?php endif; ?>
<?php elseif (($requiresSelection ?? false) === true): ?>
<p>Select a family to continue.</p>
<?php endif; ?>

<?php if ($showSelector): ?>
<h2><?= $currentContext === null ? 'Select a family' : 'Change family' ?></h2>
<form method="post" action="/representative/family">
    <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken ?? '') ?>">
    <?php foreach ($families as $family): ?>
        <?php if ($family instanceof AuthorizedFamily): ?>
        <div>
            <label>
                <input
                    type="radio"
                    name="family_id"
                    value="<?= $escape($family->familyId) ?>"
                    <?= $currentContext?->familyId === $family->familyId ? 'checked' : '' ?>
                    required
                >
                <?= $escape($family->displayName) ?>
            </label>
        </div>
        <?php endif; ?>
    <?php endforeach; ?>
    <button type="submit">Use family</button>
</form>
<?php endif; ?>

<form method="post" action="/logout">
    <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken ?? '') ?>">
    <button type="submit">Sign out</button>
</form>
