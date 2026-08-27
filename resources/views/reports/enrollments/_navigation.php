<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$display = static fn (mixed $value): string => $value === null || $value === ''
    ? '—'
    : htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$studentName = static fn (?string $surnames, ?string $names): string => htmlspecialchars(
    trim((string) $surnames . ' ' . (string) $names),
    ENT_QUOTES,
    'UTF-8',
);
$boolean = static fn (?bool $value): string => $value === null ? '—' : ($value ? 'Yes' : 'No');
$reports = [
    '/reports/enrollments/summary' => 'Summary',
    '/reports/enrollments/students' => 'Students',
    '/reports/enrollments/directory' => 'Directory',
    '/reports/enrollments/billing' => 'Billing',
    '/reports/enrollments/medical' => 'Medical',
];
?>
<style>
    .report-table { border-collapse: collapse; min-width: 100%; }
    .report-table th, .report-table td { border: 1px solid #bbb; padding: .45rem; text-align: left; vertical-align: top; }
    .report-table-wrap { max-width: 100%; overflow-x: auto; }
    .report-nav { display: flex; flex-wrap: wrap; gap: .75rem; margin: 1rem 0; }
    .report-notice { border-left: .25rem solid #777; padding: .5rem .75rem; }
</style>
<p><a href="/">Dashboard</a> &rsaquo; <a href="/reports/enrollments">Basic Enrollment Reports</a></p>
<nav class="report-nav" aria-label="Enrollment reports">
<?php foreach ($reports as $url => $label): ?>
    <a href="<?= $escape($url . $periodQuery) ?>"><?= $escape($label) ?></a>
<?php endforeach; ?>
</nav>

<form method="get" action="<?= $escape($reportPath) ?>">
    <label for="academic_period_id">AcademicPeriod</label>
    <select id="academic_period_id" name="academic_period_id" required>
        <option value="">Select an AcademicPeriod</option>
<?php foreach ($periods as $period): ?>
        <option value="<?= $escape($period->id) ?>"<?= $selectedPeriodId === $period->id ? ' selected' : '' ?>><?= $escape($period->code . ' — ' . $period->name . ($period->status->value === 'ACTIVE' ? ' (Active)' : '')) ?></option>
<?php endforeach; ?>
    </select>
    <button type="submit">View report</button>
</form>

<?php if ($selectionRequired): ?>
<p class="report-notice" role="status">Choose an AcademicPeriod to generate this report.</p>
<?php else: ?>
<p>Selected AcademicPeriod: <strong><?= $escape($selectedPeriod->code . ' — ' . $selectedPeriod->name) ?></strong> (<?= $escape($selectedPeriod->status->value) ?>)</p>
<?php endif; ?>
