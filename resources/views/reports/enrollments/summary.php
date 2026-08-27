<?php

declare(strict_types=1);

include __DIR__ . '/_navigation.php';
?>
<h1>Enrollment Summary</h1>
<?php if (!$selectionRequired): ?>
<p><a href="<?= $escape('/reports/enrollments/summary/csv' . $periodQuery) ?>">Export CSV</a></p>
<p>Total: <strong><?= $escape($dataset->total) ?></strong></p>
<?php if ($dataset->rows === []): ?>
<p role="status">No Enrollment records are available for this AcademicPeriod.</p>
<?php else: ?>
<div class="report-table-wrap">
<table class="report-table">
    <thead><tr><th>Status</th><th>Grade</th><th>Section</th><th>Count</th></tr></thead>
    <tbody>
<?php foreach ($dataset->rows as $row): ?>
        <tr><td><?= $escape($row->status->value) ?></td><td><?= $display($row->gradeName) ?></td><td><?= $display($row->sectionName) ?></td><td><?= $escape($row->count) ?></td></tr>
<?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
<?php endif; ?>
