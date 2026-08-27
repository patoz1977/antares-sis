<?php

declare(strict_types=1);

include __DIR__ . '/_navigation.php';
?>
<h1>Student Billing Information</h1>
<?php if (!$selectionRequired): ?>
<p><a href="<?= $escape('/reports/enrollments/billing/csv' . $periodQuery) ?>">Export CSV</a></p>
<?php if ($dataset === []): ?>
<p role="status">No active Students are available.</p>
<?php else: ?>
<div class="report-table-wrap">
<table class="report-table">
    <thead><tr><th>Grade</th><th>Section</th><th>Student</th><th>Status</th><th>Identification Type</th><th>Identification Number</th><th>Legal Name</th><th>Billing Address</th><th>Billing Email</th><th>Phone</th></tr></thead>
    <tbody>
<?php foreach ($dataset as $row): ?>
        <tr><td><?= $display($row->gradeName) ?></td><td><?= $display($row->sectionName) ?></td><td><?= $studentName($row->studentSurnames, $row->studentNames) ?></td><td><?= $escape($row->status->value) ?></td><td><?= $display($row->identificationType) ?></td><td><?= $display($row->identificationNumber) ?></td><td><?= $display($row->legalName) ?></td><td><?= $display($row->billingAddress) ?></td><td><?= $display($row->billingEmail) ?></td><td><?= $display($row->phone) ?></td></tr>
<?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
<?php endif; ?>
