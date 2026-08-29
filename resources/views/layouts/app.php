<?php

declare(strict_types=1);

use App\Shared\Http\ShellViewData;

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$shell = ($layoutData['shell'] ?? null) instanceof ShellViewData ? $layoutData['shell'] : null;
$displayName = $shell?->branding->displayName ?? 'Sistema de Información Escolar';
$logoPath = $shell?->branding->logoPath;
$faviconPath = $shell?->branding->faviconPath;
$primaryColor = $shell?->branding->primaryColor ?? '#0D6EFD';
$assetVersion = $shell?->branding->assetVersion ?? 'e014-p2';
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title><?= $escape($title) ?> | <?= $escape($displayName) ?></title>
    <?php if (is_string($faviconPath)): ?>
    <link rel="icon" href="<?= $escape($faviconPath) ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="/vendor/bootstrap/5.3.8/css/bootstrap.min.css?v=<?= $escape($assetVersion) ?>">
    <link rel="stylesheet" href="/vendor/bootstrap-icons/1.13.1/font/bootstrap-icons.min.css?v=<?= $escape($assetVersion) ?>">
    <link rel="stylesheet" href="/css/app.css?v=<?= $escape($assetVersion) ?>">
    <style>:root { --app-primary: <?= $escape($primaryColor) ?>; }</style>
    <noscript><style>.app-navbar .navbar-toggler { display: none; } .app-navbar .navbar-collapse { display: block !important; }</style></noscript>
</head>
<body class="bg-body-tertiary">
    <a class="visually-hidden-focusable skip-link" href="#main-content">Saltar al contenido principal</a>
    <header class="app-header shadow-sm">
        <nav class="navbar navbar-expand-lg app-navbar" aria-label="Navegación principal">
            <div class="container-fluid px-3 px-lg-4">
                <a class="navbar-brand d-flex align-items-center gap-2 text-wrap" href="<?= $shell?->context === 'representative' ? '/representative' : '/' ?>">
                    <?php if (is_string($logoPath)): ?>
                    <img class="app-brand-logo" src="<?= $escape($logoPath) ?>" alt="">
                    <?php else: ?>
                    <span class="app-brand-mark" aria-hidden="true"><i class="bi bi-mortarboard-fill"></i></span>
                    <?php endif; ?>
                    <span><?= $escape($displayName) ?></span>
                </a>
                <?php if ($shell !== null && ($shell->navigation !== [] || $shell->logoutCsrfToken !== null)): ?>
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#app-navigation" aria-controls="app-navigation" aria-expanded="false" aria-label="Mostrar navegación">
                    <span class="navbar-toggler-icon"></span>
                </button>
                <div class="collapse navbar-collapse" id="app-navigation">
                    <?php require dirname(__DIR__) . '/components/navigation.php'; ?>
                </div>
                <?php endif; ?>
            </div>
        </nav>
    </header>

    <main id="main-content" class="container py-4 py-lg-5" tabindex="-1">
        <?php require dirname(__DIR__) . '/components/feedback.php'; ?>
        <?= $content ?>
    </main>

    <script src="/vendor/bootstrap/5.3.8/js/bootstrap.bundle.min.js?v=<?= $escape($assetVersion) ?>" defer></script>
</body>
</html>
