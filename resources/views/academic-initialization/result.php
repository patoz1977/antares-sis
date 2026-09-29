<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$counts = is_array($result['counts'] ?? null) ? $result['counts'] : null;
$issues = is_array($result['issues'] ?? null) ? $result['issues'] : [];
?>
<header class="app-page-header">
    <p class="text-uppercase fw-semibold text-primary mb-2">Administración</p>
    <h1 class="display-6 fw-bold mb-2">Resultado de inicialización académica</h1>
</header>

<?php if ($result === null): ?>
<div class="alert alert-warning" role="alert">No existe un resultado vigente para mostrar.</div>
<?php elseif ($counts !== null): ?>
<div class="alert alert-success" role="status">
    El lote se aplicó completamente dentro de una única transacción.
</div>
<dl class="row">
    <dt class="col-sm-4">Filas</dt><dd class="col-sm-8"><?= $escape($counts['rows'] ?? 0) ?></dd>
    <dt class="col-sm-4">DRAFT creados</dt><dd class="col-sm-8"><?= $escape($counts['created_drafts'] ?? 0) ?></dd>
    <dt class="col-sm-4">Ubicaciones asignadas</dt><dd class="col-sm-8"><?= $escape($counts['placements_set'] ?? 0) ?></dd>
    <dt class="col-sm-4">Sin cambios</dt><dd class="col-sm-8"><?= $escape($counts['already_correct'] ?? 0) ?></dd>
</dl>
<?php else: ?>
<div class="alert alert-danger" role="alert">
    El lote no se aplicó. No se conservó ninguna escritura parcial.
</div>
<?php endif; ?>

<?php if ($issues !== []): ?>
<div class="table-responsive">
    <table class="table table-sm">
        <caption>Resultado seguro.</caption>
        <thead><tr><th>Categoría</th><th>Mensaje</th></tr></thead>
        <tbody>
            <?php foreach ($issues as $issue): ?>
            <tr><td><?= $escape($issue['category'] ?? '') ?></td><td><?= $escape($issue['message'] ?? '') ?></td></tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<a class="btn btn-primary" href="/admin/academic-initialization">Volver a inicialización académica</a>
