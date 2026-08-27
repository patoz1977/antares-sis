<?php

declare(strict_types=1);

include __DIR__ . '/_navigation.php';
?>
<h1>Basic Enrollment Reports</h1>
<p>Select an AcademicPeriod, then open one of the five read-only reports.</p>
<ul>
<?php foreach ($reports as $url => $label): ?>
    <li><a href="<?= $escape($url . $periodQuery) ?>"><?= $escape($label) ?></a></li>
<?php endforeach; ?>
</ul>
