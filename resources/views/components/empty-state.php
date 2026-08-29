<?php

declare(strict_types=1);

$emptyStateTitle = is_string($emptyStateTitle ?? null) ? $emptyStateTitle : 'No hay información disponible';
$emptyStateText = is_string($emptyStateText ?? null) ? $emptyStateText : '';
?>
<div class="app-empty-state" role="status">
    <i class="bi bi-inbox" aria-hidden="true"></i>
    <p class="fw-semibold mb-1"><?= $escape($emptyStateTitle) ?></p>
    <?php if ($emptyStateText !== ''): ?>
    <p class="text-body-secondary mb-0"><?= $escape($emptyStateText) ?></p>
    <?php endif; ?>
</div>
