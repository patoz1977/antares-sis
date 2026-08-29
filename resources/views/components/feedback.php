<?php

declare(strict_types=1);

$messages = [];
foreach ([
    ['value' => $successMessage ?? null, 'class' => 'success', 'role' => 'status'],
    ['value' => $errorMessage ?? null, 'class' => 'warning', 'role' => 'alert'],
    ['value' => $flashMessage ?? null, 'class' => 'warning', 'role' => 'alert'],
] as $candidate) {
    if (is_string($candidate['value']) && $candidate['value'] !== '') {
        $messages[] = $candidate;
    }
}
?>
<?php foreach ($messages as $message): ?>
<div class="alert alert-<?= $message['class'] ?> alert-dismissible fade show" role="<?= $message['role'] ?>">
    <?= $escape($message['value']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
</div>
<?php endforeach; ?>
