<?php

declare(strict_types=1);

include __DIR__ . '/_navigation.php';
?>
<h1>Student and Representative Directory</h1>
<?php if (!$selectionRequired): ?>
<p><a href="<?= $escape('/reports/enrollments/directory/csv' . $periodQuery) ?>">Export CSV</a></p>
<?php if ($selectedPeriod->status->value === 'INACTIVE'): ?>
<p class="report-notice">Enrollment status and placement correspond to the selected academic period. Contact and address information are current SIS data.</p>
<?php endif; ?>
<?php if ($dataset === []): ?>
<p role="status">No active Students are available.</p>
<?php else: ?>
<div class="report-table-wrap">
<table class="report-table">
    <thead><tr><th>Grade</th><th>Section</th><th>Student</th><th>Student identification</th><th>Primary Representative</th><th>Representative identification</th><th>Phones</th><th>Emails</th><th>Address</th><th>Status</th></tr></thead>
    <tbody>
<?php foreach ($dataset as $row): ?>
        <tr>
            <td><?= $display($row->gradeName) ?></td>
            <td><?= $display($row->sectionName) ?></td>
            <td><?= $studentName($row->studentSurnames, $row->studentNames) ?></td>
            <td><?= $display($row->studentIdentificationType) ?> / <?= $display($row->studentIdentificationNumber) ?></td>
            <td><?= $row->representativeSurnames === null && $row->representativeNames === null ? '—' : $studentName($row->representativeSurnames, $row->representativeNames) ?></td>
            <td><?= $display($row->representativeIdentificationType) ?> / <?= $display($row->representativeIdentificationNumber) ?></td>
            <td>Mobile: <?= $display($row->representativeMobilePhone) ?><br>Landline: <?= $display($row->representativeLandlinePhone) ?><br>Work: <?= $display($row->representativeWorkPhone) ?></td>
            <td>Personal: <?= $display($row->representativePersonalEmail) ?><br>Work: <?= $display($row->representativeWorkEmail) ?></td>
            <td><?= $display($row->studentAddress) ?></td>
            <td><?= $escape($row->status->value) ?></td>
        </tr>
<?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
<?php endif; ?>
