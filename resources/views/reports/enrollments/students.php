<?php

declare(strict_types=1);

include __DIR__ . '/_navigation.php';
?>
<h1>Student Enrollment List</h1>
<?php if (!$selectionRequired): ?>
<p><a href="<?= $escape('/reports/enrollments/students/csv' . $periodQuery) ?>">Export CSV</a></p>
<?php if ($dataset === []): ?>
<p role="status">No active Students are available.</p>
<?php else: ?>
<div class="report-table-wrap">
<table class="report-table">
    <thead><tr><th>Grade</th><th>Section</th><th>Student</th><th>Status</th></tr></thead>
    <tbody>
<?php foreach ($dataset as $row): ?>
        <tr><td><?= $display($row->gradeName) ?></td><td><?= $display($row->sectionName) ?></td><td><?= $studentName($row->studentSurnames, $row->studentNames) ?></td><td><?= $escape($row->status->value) ?></td></tr>
<?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
<?php endif; ?>
