<?php

declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$families = is_array($result['families'] ?? null) ? $result['families'] : [];
$issues = is_array($result['issues'] ?? null) ? $result['issues'] : [];
?>
<header class="app-page-header">
    <p class="text-uppercase fw-semibold text-primary mb-2">Administración</p>
    <h1 class="display-6 fw-bold mb-2">Resultado de importación masiva</h1>
    <p class="lead text-body-secondary mb-0">Resultado seguro de la operación más reciente.</p>
</header>

<?php if ($result === null): ?>
<div class="alert alert-warning" role="alert">No existe un resultado vigente para mostrar.</div>
<?php else: ?>
    <?php if ($families !== []): ?>
    <div class="table-responsive mb-4">
        <table class="table table-striped align-middle">
            <caption>Resultado por familia.</caption>
            <thead><tr><th scope="col">Familia</th><th scope="col">Resultado</th><th scope="col">Detalle</th></tr></thead>
            <tbody>
                <?php foreach ($families as $family): ?>
                <tr>
                    <td><code><?= $escape($family['family_code'] ?? '') ?></code></td>
                    <td><?= $escape($family['label'] ?? '') ?></td>
                    <td><?= $escape($family['message'] ?? '') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php if ($issues !== []): ?>
    <div class="alert alert-warning" role="status">
        Revisa las observaciones. Ante un cambio concurrente, valida nuevamente el archivo antes de intentarlo.
    </div>
    <div class="table-responsive mb-3">
        <table class="table table-sm">
            <caption>Observaciones seguras.</caption>
            <thead><tr><th scope="col">Categoría</th><th scope="col">Hoja</th><th scope="col">Fila</th><th scope="col">Campo</th><th scope="col">Mensaje</th></tr></thead>
            <tbody>
                <?php foreach ($issues as $issue): ?>
                <tr>
                    <td><?= $escape($issue['category'] ?? '') ?></td>
                    <td><?= $escape($issue['sheet'] ?? '') ?></td>
                    <td><?= $escape($issue['row'] ?? '') ?></td>
                    <td><?= $escape($issue['field'] ?? '') ?></td>
                    <td><?= $escape($issue['message'] ?? '') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
<?php endif; ?>

<div class="d-flex flex-wrap gap-2">
    <a class="btn btn-primary" href="/admin/bulk-import">Nueva importación</a>
    <?php if (($hasReport ?? false) === true): ?>
    <a class="btn btn-outline-secondary" href="/admin/bulk-import/errors.csv">Descargar errores CSV</a>
    <?php endif; ?>
</div>
