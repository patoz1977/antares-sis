<?php

declare(strict_types=1);
?>
<ul class="navbar-nav me-auto mb-2 mb-lg-0">
    <?php foreach ($shell->navigation as $item): ?>
    <li class="nav-item">
        <a class="nav-link<?= $item['active'] ? ' active' : '' ?>" href="<?= $escape($item['href']) ?>"<?= $item['active'] ? ' aria-current="page"' : '' ?>>
            <i class="bi <?= $escape($item['icon']) ?>" aria-hidden="true"></i>
            <span><?= $escape($item['label']) ?></span>
        </a>
    </li>
    <?php endforeach; ?>
</ul>
<?php if ($shell->logoutCsrfToken !== null): ?>
<form class="d-flex" method="post" action="/logout">
    <input type="hidden" name="_csrf_token" value="<?= $escape($shell->logoutCsrfToken) ?>">
    <button class="btn btn-outline-light" type="submit">
        <i class="bi bi-box-arrow-right" aria-hidden="true"></i>
        <span>Cerrar sesión</span>
    </button>
</form>
<?php endif; ?>
