<?php

declare(strict_types=1);

$breadcrumbItems = is_array($breadcrumbItems ?? null) ? $breadcrumbItems : [];
?>
<?php if ($breadcrumbItems !== []): ?>
<nav aria-label="Migas de pan">
    <ol class="breadcrumb">
        <?php foreach ($breadcrumbItems as $index => $item): ?>
        <?php $isCurrent = $index === array_key_last($breadcrumbItems); ?>
        <li class="breadcrumb-item<?= $isCurrent ? ' active' : '' ?>"<?= $isCurrent ? ' aria-current="page"' : '' ?>>
            <?php if (!$isCurrent && is_string($item['url'] ?? null)): ?>
            <a href="<?= $escape($item['url']) ?>"><?= $escape($item['label'] ?? '') ?></a>
            <?php else: ?>
            <?= $escape($item['label'] ?? '') ?>
            <?php endif; ?>
        </li>
        <?php endforeach; ?>
    </ol>
</nav>
<?php endif; ?>
