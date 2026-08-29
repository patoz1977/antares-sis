<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$formatInstant = static fn (DateTimeImmutable $value): string =>
    $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$items = is_array($items ?? null) ? $items : [];
?>
    <header class="mb-4">
        <h1>Enrollment Administration</h1>
        <p>Operational queue of Enrollments currently awaiting administrative review.</p>
        <p><a href="/">Back to dashboard</a></p>
    </header>

    <?php if ($items === []): ?>
    <p>No Submitted Enrollments are currently awaiting review.</p>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table">
            <caption>Submitted Enrollment queue</caption>
            <thead>
                <tr>
                    <th scope="col">Student</th>
                    <th scope="col">Current Family</th>
                    <th scope="col">Academic Period</th>
                    <th scope="col">Grade</th>
                    <th scope="col">Submitted at (UTC)</th>
                    <th scope="col">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                <tr>
                    <td><?= $escape($item->studentDisplayName) ?></td>
                    <td><?= $escape($item->familyDisplayName) ?></td>
                    <td><?= $escape($item->academicPeriodDisplayName) ?></td>
                    <td><?= $escape($item->gradeDisplayName ?? 'Not assigned') ?></td>
                    <td><?= $escape($formatInstant($item->submittedAt)) ?></td>
                    <td><a href="/enrollments/review?id=<?= $escape($item->enrollmentId) ?>">Review</a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
