<?php

declare(strict_types=1);

use App\IdentityAccess\Application\AuthorizedFamily;
use App\IdentityAccess\Application\FamilyContext;

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$families = is_array($authorizedFamilies ?? null) ? $authorizedFamilies : [];
$currentContext = ($context ?? null) instanceof FamilyContext ? $context : null;
$showSelector = count($families) > 1;
?>
<h1>Representative Portal</h1>

<?php if ($currentContext !== null): ?>
<p>Current family: <strong><?= $escape($currentContext->familyDisplayName) ?></strong></p>
<p>Your authorized family context is ready.</p>
<p><a href="/representative/resources">Manage family resources</a></p>
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
