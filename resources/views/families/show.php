<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$timestamp = static fn (DateTimeImmutable $value): string => $value->format(DateTimeImmutable::ATOM);
?>
<h1>Family details</h1>

<?php if (is_string($successMessage ?? null) && $successMessage !== ''): ?>
<p><?= $escape($successMessage) ?></p>
<?php endif; ?>

<h2>Family</h2>
<dl>
    <dt>ID</dt><dd><?= $escape($family->id) ?></dd>
    <dt>Display name</dt><dd><?= $escape($family->displayName) ?></dd>
    <dt>Status</dt><dd><?= $escape($family->status->value) ?></dd>
</dl>

<h2>Representative memberships</h2>
<?php if ($family->representatives === []): ?>
<p>No Representative memberships.</p>
<?php else: ?>
<?php foreach ($family->representatives as $membership): ?>
<section>
    <h3><?= $membership->isPrimary && $membership->isActive ? 'Active primary Representative' : 'Representative membership' ?></h3>
    <dl>
        <dt>Membership ID</dt><dd><?= $escape($membership->id) ?></dd>
        <dt>Representative ID</dt><dd><?= $escape($membership->representativeId) ?></dd>
        <dt>Relationship type ID</dt><dd><?= $escape($membership->relationshipTypeId) ?></dd>
        <dt>Primary</dt><dd><?= $membership->isPrimary ? 'Yes' : 'No' ?></dd>
        <dt>Membership state</dt><dd><?= $membership->isActive ? 'Active' : 'History' ?></dd>
        <dt>Started at</dt><dd><?= $escape($timestamp($membership->startedAt)) ?></dd>
        <dt>Ended at</dt><dd><?= $membership->endedAt === null ? 'Not ended' : $escape($timestamp($membership->endedAt)) ?></dd>
    </dl>
</section>
<?php endforeach; ?>
<?php endif; ?>

<h2>Student memberships</h2>
<?php if ($family->students === []): ?>
<p>No Student memberships.</p>
<?php else: ?>
<?php foreach ($family->students as $membership): ?>
<section>
    <h3>Student membership</h3>
    <dl>
        <dt>Membership ID</dt><dd><?= $escape($membership->id) ?></dd>
        <dt>Student ID</dt><dd><?= $escape($membership->studentId) ?></dd>
        <dt>Membership state</dt><dd><?= $membership->isActive ? 'Active' : 'History' ?></dd>
        <dt>Started at</dt><dd><?= $escape($timestamp($membership->startedAt)) ?></dd>
        <dt>Ended at</dt><dd><?= $membership->endedAt === null ? 'Not ended' : $escape($timestamp($membership->endedAt)) ?></dd>
    </dl>
</section>
<?php endforeach; ?>
<?php endif; ?>

<p><a href="/families/students/create?family_id=<?= $escape($family->id) ?>">Add Student</a></p>
<p><a href="/families">Back to Families</a></p>
