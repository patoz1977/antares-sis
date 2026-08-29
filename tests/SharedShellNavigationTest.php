<?php

declare(strict_types=1);

namespace Tests;

use App\Shared\Http\SharedShellDataFactory;
use App\Shared\Http\ShellViewData;
use App\Shared\Http\WhiteLabelBranding;
use Core\Http\Request;
use Core\View\View;
use Tests\Support\TestRunner;

function registerSharedShellNavigationTests(TestRunner $runner): void
{
    $runner->add('E014 shared shell renders Spanish local assets escaped White Label and no JavaScript dependency', function (): void {
        $branding = WhiteLabelBranding::fromConfig([
            'app_name' => 'Colegio <Seguro> & Uno',
            'app_logo_path' => '//remote.example/logo.svg',
            'app_favicon_path' => '/branding/favicon.svg',
            'app_primary_color' => '#12abEF',
            'app_asset_version' => 'phase2.1',
        ]);
        $shell = new ShellViewData($branding, 'public', [], null);
        View::setSharedDataResolver(static fn (): array => ['shell' => $shell]);

        try {
            $html = View::render('auth.login', [
                'title' => 'Acceso <privado>',
                'csrfToken' => 'csrf<&',
            ]);
        } finally {
            View::setSharedDataResolver(null);
        }

        foreach ([
            '<html lang="es">',
            'name="viewport"',
            '/vendor/bootstrap/5.3.8/css/bootstrap.min.css?v=phase2.1',
            '/vendor/bootstrap-icons/1.13.1/font/bootstrap-icons.min.css?v=phase2.1',
            '/css/app.css?v=phase2.1',
            '/vendor/bootstrap/5.3.8/js/bootstrap.bundle.min.js?v=phase2.1',
            '--app-primary: #12ABEF',
            '<noscript>',
            'Colegio &lt;Seguro&gt; &amp; Uno',
            'Acceso &lt;privado&gt;',
            'value="csrf&lt;&amp;"',
        ] as $expected) {
            deliveryAssertContains($expected, $html);
        }
        assertSameValue(false, str_contains($html, '<script>'));
        assertSameValue(false, str_contains($html, 'https://'));
        assertSameValue(false, str_contains($html, '//remote.example'));
        assertSameValue(1, substr_count($html, '<!doctype html>'));
        assertSameValue(1, substr_count($html, '<main '));
    });

    $runner->add('E014 White Label configuration fails closed for unsafe presentation values', function (): void {
        $branding = WhiteLabelBranding::fromConfig([
            'app_name' => '',
            'app_logo_path' => '/../secret.svg',
            'app_favicon_path' => 'https://remote.example/icon.svg',
            'app_primary_color' => 'red; background:url(x)',
            'app_asset_version' => '../latest',
        ]);

        assertSameValue('Sistema de Información Escolar', $branding->displayName);
        assertSameValue(null, $branding->logoPath);
        assertSameValue(null, $branding->faviconPath);
        assertSameValue('#0D6EFD', $branding->primaryColor);
        assertSameValue('e014-p2', $branding->assetVersion);
    });

    $runner->add('E014 administrator shell exposes only approved modules and prefix-aware active state', function (): void {
        $fixture = representativePortalLoginFixture(false, 'admin');
        $fixture['session']->userId = 11;
        deliveryRequest('GET', '/representative-users/manage');
        $shell = (new SharedShellDataFactory(
            $fixture['getUser'],
            $fixture['getRepresentative'],
            new FakeDeliveryCsrf(),
            WhiteLabelBranding::fromConfig([]),
        ))->forRequest(new Request());

        assertSameValue('admin', $shell->context);
        assertSameValue(
            ['Inicio', 'Personas', 'Familias', 'Confirmaciones institucionales', 'Matrículas', 'Reportes'],
            array_column($shell->navigation, 'label'),
        );
        assertSameValue('delivery-csrf', $shell->logoutCsrfToken);
        assertSameValue(true, shellItem($shell, 'Familias')['active']);
        assertSameValue(false, shellItem($shell, 'Inicio')['active']);
        assertSameValue(false, in_array('Recursos familiares', array_column($shell->navigation, 'label'), true));

        deliveryRequest('GET', '/persons-extra');
        $boundary = (new SharedShellDataFactory(
            $fixture['getUser'],
            $fixture['getRepresentative'],
            new FakeDeliveryCsrf(),
            WhiteLabelBranding::fromConfig([]),
        ))->forRequest(new Request());
        assertSameValue(false, shellItem($boundary, 'Personas')['active']);
    });

    $runner->add('E014 Representative shell exposes only Portal destinations and keeps admin modules absent', function (): void {
        $fixture = representativePortalLoginFixture(true, 'representative-22');
        $fixture['session']->userId = 11;
        deliveryRequest('GET', '/representative/enrollment/review');
        $shell = (new SharedShellDataFactory(
            $fixture['getUser'],
            $fixture['getRepresentative'],
            new FakeDeliveryCsrf(),
            WhiteLabelBranding::fromConfig([]),
        ))->forRequest(new Request());

        assertSameValue('representative', $shell->context);
        assertSameValue(
            ['Inicio', 'Matrícula', 'Recursos familiares', 'Confirmaciones'],
            array_column($shell->navigation, 'label'),
        );
        assertSameValue(true, shellItem($shell, 'Matrícula')['active']);
        foreach (['Personas', 'Familias', 'Reportes'] as $adminLabel) {
            assertSameValue(false, in_array($adminLabel, array_column($shell->navigation, 'label'), true));
        }
    });

    $runner->add('E014 Controller runtime composes anonymous admin and Representative shells without changing authority', function (): void {
        $anonymous = representativePortalLoginFixture(false, 'admin');
        $anonymousFactory = new SharedShellDataFactory(
            $anonymous['getUser'],
            $anonymous['getRepresentative'],
            new FakeDeliveryCsrf(),
            WhiteLabelBranding::fromConfig([]),
        );
        deliveryRequest('GET', '/login');
        View::setSharedDataResolver(static fn (): array => [
            'shell' => $anonymousFactory->forRequest(new Request()),
        ]);
        try {
            $anonymousHtml = $anonymous['authentication']->showLogin();
        } finally {
            View::setSharedDataResolver(null);
        }
        deliveryAssertContains('Iniciar sesión', $anonymousHtml);
        assertSameValue(false, str_contains($anonymousHtml, 'action="/logout"'));

        $admin = representativePortalLoginFixture(false, 'admin');
        $admin['session']->userId = 11;
        $adminFactory = new SharedShellDataFactory(
            $admin['getUser'],
            $admin['getRepresentative'],
            new FakeDeliveryCsrf(),
            WhiteLabelBranding::fromConfig([]),
        );
        deliveryRequest('GET', '/');
        View::setSharedDataResolver(static fn (): array => [
            'shell' => $adminFactory->forRequest(new Request()),
        ]);
        try {
            $adminHtml = $admin['authentication']->dashboard();
        } finally {
            View::setSharedDataResolver(null);
        }
        foreach (['Panel administrativo', 'href="/persons"', 'href="/families"', 'href="/enrollments"', 'href="/reports/enrollments"'] as $expected) {
            deliveryAssertContains($expected, $adminHtml);
        }
        assertSameValue(1, substr_count($adminHtml, 'action="/logout"'));
        assertSameValue(false, str_contains($adminHtml, 'href="/representative/enrollment"'));

        $representative = representativePortalLoginFixture(true, 'representative-22');
        $representative['session']->userId = 11;
        $representative['families']->seed(familyContextFamily(10, 'Familia Uno', [33]));
        $representativeFactory = new SharedShellDataFactory(
            $representative['getUser'],
            $representative['getRepresentative'],
            new FakeDeliveryCsrf(),
            WhiteLabelBranding::fromConfig([]),
        );
        deliveryRequest('GET', '/representative');
        View::setSharedDataResolver(static fn (): array => [
            'shell' => $representativeFactory->forRequest(new Request()),
        ]);
        try {
            $representativeHtml = $representative['portal']->index();
        } finally {
            View::setSharedDataResolver(null);
        }
        foreach (['Familia Uno', 'href="/representative/enrollment"', 'href="/representative/acknowledgements"'] as $expected) {
            deliveryAssertContains($expected, $representativeHtml);
        }
        assertSameValue(1, substr_count($representativeHtml, 'action="/logout"'));
        foreach (['href="/persons"', 'href="/families"', 'href="/reports/enrollments"'] as $excluded) {
            assertSameValue(false, str_contains($representativeHtml, $excluded));
        }
    });

    $runner->add('E014 anonymous login remains one role-neutral POST with shared feedback and no protected navigation', function (): void {
        $shell = new ShellViewData(WhiteLabelBranding::fromConfig([]), 'public', [], null);
        View::setSharedDataResolver(static fn (): array => ['shell' => $shell]);

        try {
            $html = View::render('auth.login', [
                'title' => 'Iniciar sesión',
                'csrfToken' => 'anonymous-csrf',
                'flashMessage' => 'Solicitud no válida.',
            ]);
        } finally {
            View::setSharedDataResolver(null);
        }

        assertSameValue(1, substr_count($html, 'method="post" action="/login"'));
        assertSameValue(0, substr_count($html, 'action="/logout"'));
        foreach (['name="username"', 'name="password"', 'Solicitud no válida.', 'role="alert"'] as $expected) {
            deliveryAssertContains($expected, $html);
        }
        foreach (['user_type', 'role_selector', 'href="/persons"', 'href="/representative"'] as $excluded) {
            assertSameValue(false, str_contains($html, $excluded));
        }
    });

    $runner->add('E014 pinned vendor assets are local complete and identified by approved versions', function (): void {
        $root = dirname(__DIR__);
        $assets = [
            '/public/vendor/bootstrap/5.3.8/css/bootstrap.min.css' => 'Bootstrap  v5.3.8',
            '/public/vendor/bootstrap/5.3.8/js/bootstrap.bundle.min.js' => 'Bootstrap v5.3.8',
            '/public/vendor/bootstrap-icons/1.13.1/font/bootstrap-icons.min.css' => 'Bootstrap Icons v1.13.1',
            '/public/vendor/bootstrap-icons/1.13.1/font/fonts/bootstrap-icons.woff' => null,
            '/public/vendor/bootstrap-icons/1.13.1/font/fonts/bootstrap-icons.woff2' => null,
        ];

        foreach ($assets as $path => $signature) {
            assertSameValue(true, is_file($root . $path), $path);
            assertSameValue(true, filesize($root . $path) > 0, $path);
            if (is_string($signature)) {
                deliveryAssertContains($signature, (string) file_get_contents($root . $path));
            }
        }
    });

    $runner->add('E014 full-document markup and logout ownership remain centralized in the shared layout', function (): void {
        $viewsRoot = dirname(__DIR__) . '/resources/views';
        $phpViews = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($viewsRoot));
        $fullDocuments = [];
        $logoutOwners = [];
        $mainOwners = [];

        foreach ($phpViews as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($viewsRoot) + 1));
            if (str_contains(strtolower($source), '<!doctype') || str_contains($source, '<html')) {
                $fullDocuments[] = $relative;
            }
            if (str_contains($source, 'action="/logout"')) {
                $logoutOwners[] = $relative;
            }
            if (str_contains($source, '<main ')) {
                $mainOwners[] = $relative;
            }
        }

        assertSameValue(['layouts/app.php'], $fullDocuments);
        assertSameValue(['components/navigation.php'], $logoutOwners);
        assertSameValue(['layouts/app.php'], $mainOwners);
    });
}

function shellItem(ShellViewData $shell, string $label): array
{
    foreach ($shell->navigation as $item) {
        if ($item['label'] === $label) {
            return $item;
        }
    }

    throw new \RuntimeException(sprintf('Shell item not found: %s', $label));
}
