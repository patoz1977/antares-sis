<?php

declare(strict_types=1);
?>
<?php if (is_array($errors ?? null) && $errors !== []): ?>
<div class="alert alert-danger" role="alert" aria-labelledby="validation-summary-title">
    <p class="fw-semibold mb-2" id="validation-summary-title">Revisa la información indicada:</p>
    <ul class="mb-0">
        <?php foreach ($errors as $error): ?>
        <li><?= $escape($error) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>
