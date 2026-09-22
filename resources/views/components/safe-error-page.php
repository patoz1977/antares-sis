<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<section class="app-content-narrow app-data-card" aria-labelledby="safe-error-heading">
    <p class="text-body-secondary mb-2">Error <?= $escape($errorStatus) ?></p>
    <h1 class="h3" id="safe-error-heading"><?= $escape($title) ?></h1>
    <p class="alert alert-warning" role="alert"><?= $escape($errorMessage) ?></p>
    <a class="btn btn-outline-primary" href="<?= $escape($returnUrl) ?>"><?= $escape($returnLabel) ?></a>
</section>
