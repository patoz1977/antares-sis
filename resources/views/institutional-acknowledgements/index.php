<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$field = static fn (string $key, mixed $fallback = ''): string => $escape($values[$key] ?? $fallback);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $escape($title ?? 'Institutional Acknowledgements') ?></title>
</head>
<body>
    <h1>Institutional Acknowledgements</h1>

    <?php if (($successMessage ?? null) !== null): ?>
    <p role="status"><?= $escape($successMessage) ?></p>
    <?php endif; ?>

    <?php if (($errors ?? []) !== []): ?>
    <div role="alert">
        <ul>
            <?php foreach ($errors as $error): ?>
            <li><?= $escape($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <form method="get" action="/institutional-acknowledgements">
        <label for="academic-period">Academic Period</label>
        <select id="academic-period" name="academic_period_id" required>
            <option value="">Select an Academic Period</option>
            <?php foreach ($periods as $period): ?>
            <option value="<?= $escape($period->id) ?>"<?= ($selectedPeriod?->id ?? null) === $period->id ? ' selected' : '' ?>>
                <?= $escape($period->code . ' — ' . $period->name . ' (' . $period->startsOn . ' to ' . $period->endsOn . ')') ?>
            </option>
            <?php endforeach; ?>
        </select>
        <button type="submit">Open</button>
    </form>

    <?php if (($selectedPeriod ?? null) !== null): ?>
    <h2><?= $escape($selectedPeriod->code . ' — ' . $selectedPeriod->name) ?></h2>
    <p><?= $escape($selectedPeriod->startsOn . ' to ' . $selectedPeriod->endsOn) ?></p>

    <section aria-labelledby="create-requirement-heading">
        <h2 id="create-requirement-heading">Create Requirement</h2>
        <form method="post" action="/institutional-acknowledgements/requirements/create">
            <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken ?? '') ?>">
            <input type="hidden" name="academic_period_id" value="<?= $escape($selectedPeriod->id) ?>">
            <label>Title <input name="title" maxlength="200" required value="<?= $field('title') ?>"></label>
            <label>URL <input name="url" maxlength="500" required value="<?= $field('url') ?>"></label>
            <label>Official Reference <input name="official_reference" maxlength="255" value="<?= $field('official_reference') ?>"></label>
            <label>Status
                <select name="status" required>
                    <option value="">Select a Status</option>
                    <option value="ACTIVE"<?= ($values['status'] ?? '') === 'ACTIVE' ? ' selected' : '' ?>>ACTIVE</option>
                    <option value="INACTIVE"<?= ($values['status'] ?? '') === 'INACTIVE' ? ' selected' : '' ?>>INACTIVE</option>
                </select>
            </label>
            <button type="submit">Create Requirement</button>
        </form>
    </section>

    <section aria-labelledby="requirements-heading">
        <h2 id="requirements-heading">Configured Requirements</h2>
        <?php if ($requirements === []): ?>
        <p>No Requirements are configured for this Academic Period.</p>
        <?php endif; ?>

        <?php foreach ($requirements as $requirement): ?>
        <article>
            <h3><?= $escape($requirement->title) ?></h3>
            <p>Status: <?= $escape($requirement->status) ?></p>
            <p>URL: <?= $escape($requirement->url) ?></p>
            <p>Official Reference: <?= $escape($requirement->officialReference ?? '—') ?></p>

            <form method="post" action="/institutional-acknowledgements/requirements/update">
                <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken ?? '') ?>">
                <input type="hidden" name="academic_period_id" value="<?= $escape($selectedPeriod->id) ?>">
                <input type="hidden" name="requirement_id" value="<?= $escape($requirement->id) ?>">
                <label>Title <input name="title" maxlength="200" required value="<?= $escape($requirement->title) ?>"></label>
                <label>URL <input name="url" maxlength="500" required value="<?= $escape($requirement->url) ?>"></label>
                <label>Official Reference <input name="official_reference" maxlength="255" value="<?= $escape($requirement->officialReference ?? '') ?>"></label>
                <button type="submit">Update Requirement</button>
            </form>

            <form method="post" action="/institutional-acknowledgements/requirements/<?= $requirement->status === 'ACTIVE' ? 'deactivate' : 'activate' ?>">
                <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken ?? '') ?>">
                <input type="hidden" name="academic_period_id" value="<?= $escape($selectedPeriod->id) ?>">
                <input type="hidden" name="requirement_id" value="<?= $escape($requirement->id) ?>">
                <button type="submit"><?= $requirement->status === 'ACTIVE' ? 'Deactivate' : 'Activate' ?> Requirement</button>
            </form>
        </article>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>

    <p><a href="/">Back to Dashboard</a></p>
    <form method="post" action="/logout">
        <input type="hidden" name="_csrf_token" value="<?= $escape($csrfToken ?? '') ?>">
        <button type="submit">Sign out</button>
    </form>
</body>
</html>
