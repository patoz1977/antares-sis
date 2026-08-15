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
<h1>Institutional Acknowledgements</h1>

<?php if (is_string($successMessage ?? null)): ?>
<p role="status"><?= $escape($successMessage) ?></p>
<?php endif; ?>
<?php if (is_string($errorMessage ?? null)): ?>
<p role="alert"><?= $escape($errorMessage) ?></p>
<?php endif; ?>

<?php if ($portalState === null): ?>
<p>No active Academic Period is currently configured.</p>
<?php else: ?>
<section>
    <h2>Academic Period</h2>
    <p><strong><?= $escape($portalState->context->academicPeriodName) ?></strong></p>
    <p><?= $escape($portalState->context->academicPeriodCode) ?></p>
    <p><?= $escape($portalState->context->startsOn) ?> to <?= $escape($portalState->context->endsOn) ?></p>
</section>

<?php if ($portalState->status === 'completed'): ?>
<p><strong>Completed</strong></p>
<p>Completed at: <?= $escape($portalState->completedAt?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s') ?? '') ?> UTC</p>
<p>No further confirmation is required for this Academic Period.</p>
<?php elseif ($portalState->status === 'not_required'): ?>
<p><strong>Completed</strong></p>
<p>No institutional acknowledgements are required for this Academic Period.</p>
<?php else: ?>
<p>Review every current institutional requirement before updating family data.</p>
<form method="post" action="/representative/acknowledgements/complete">
    <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken ?? '') ?>">
    <?php foreach ($portalState->activeRequirements as $requirement): ?>
    <article>
        <label>
            <input
                type="checkbox"
                name="acknowledged_requirement_ids[]"
                value="<?= $escape($requirement->id) ?>"
                required
            >
            <?= $escape($requirement->title) ?>
        </label>
        <p>
            <?php if ($safeLink($requirement->url)): ?>
            <a href="<?= $escape($requirement->url) ?>" target="_blank" rel="noopener noreferrer"><?= $escape($requirement->url) ?></a>
            <?php else: ?>
            <?= $escape($requirement->url) ?>
            <?php endif; ?>
        </p>
        <?php if ($requirement->officialReference !== null): ?>
        <p>Official reference: <?= $escape($requirement->officialReference) ?></p>
        <?php endif; ?>
    </article>
    <?php endforeach; ?>
    <button type="submit">Confirm I have reviewed these requirements</button>
</form>
<?php endif; ?>
<?php endif; ?>

<p><a href="/representative">Back to Representative Portal</a></p>

<form method="post" action="/logout">
    <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken ?? '') ?>">
    <button type="submit">Sign out</button>
</form>
