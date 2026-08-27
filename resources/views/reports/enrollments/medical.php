<?php

declare(strict_types=1);

include __DIR__ . '/_navigation.php';
?>
<h1>Student Medical Information</h1>
<?php if (!$selectionRequired): ?>
<p><a href="<?= $escape('/reports/enrollments/medical/csv' . $periodQuery) ?>">Export CSV</a></p>
<?php if ($dataset === []): ?>
<p role="status">No active Students are available.</p>
<?php else: ?>
<div class="report-table-wrap">
<table class="report-table">
    <thead><tr><th>Grade</th><th>Section</th><th>Student</th><th>Status</th><th>Has Medical Condition</th><th>Medical Condition Detail</th><th>Has Allergies</th><th>Allergy Detail</th><th>Takes Permanent Medication</th><th>Medication Name</th><th>Requires Special Care</th><th>Special Care Detail</th><th>Has Medical Insurance</th><th>Insurance Provider</th><th>Pediatrician Name</th><th>Pediatrician Phone</th><th>Observations</th></tr></thead>
    <tbody>
<?php foreach ($dataset as $row): ?>
        <tr><td><?= $display($row->gradeName) ?></td><td><?= $display($row->sectionName) ?></td><td><?= $studentName($row->studentSurnames, $row->studentNames) ?></td><td><?= $escape($row->status->value) ?></td><td><?= $boolean($row->hasMedicalCondition) ?></td><td><?= $display($row->medicalConditionDetail) ?></td><td><?= $boolean($row->hasAllergies) ?></td><td><?= $display($row->allergyDetail) ?></td><td><?= $boolean($row->takesPermanentMedication) ?></td><td><?= $display($row->medicationName) ?></td><td><?= $boolean($row->requiresSpecialCare) ?></td><td><?= $display($row->specialCareDetail) ?></td><td><?= $boolean($row->hasMedicalInsurance) ?></td><td><?= $display($row->insuranceProvider) ?></td><td><?= $display($row->pediatricianName) ?></td><td><?= $display($row->pediatricianPhone) ?></td><td><?= $display($row->observations) ?></td></tr>
<?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
<?php endif; ?>
