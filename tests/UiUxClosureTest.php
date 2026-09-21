<?php

declare(strict_types=1);

namespace Tests;

use Tests\Support\TestRunner;

function registerUiUxClosureTests(TestRunner $runner): void
{
    $runner->add('E014 Phase 5 keeps one accessible local shared document boundary', function (): void {
        $layout = uiClosureSource('resources/views/layouts/app.php');
        foreach ([
            '<html lang="es">',
            'name="viewport"',
            'Saltar al contenido principal',
            '<main id="main-content"',
            'tabindex="-1"',
            'aria-label="Navegación principal"',
            '/vendor/bootstrap/5.3.8/css/bootstrap.min.css',
            '/vendor/bootstrap-icons/1.13.1/font/bootstrap-icons.min.css',
            '/vendor/bootstrap/5.3.8/js/bootstrap.bundle.min.js',
            '/css/app.css',
        ] as $expected) {
            deliveryAssertContains($expected, $layout);
        }

        assertSameValue(2, substr_count($layout, '<style>'), 'controlled shell style blocks');
        deliveryAssertContains('<style>:root { --app-primary: <?= $escape($primaryColor) ?>; }</style>', $layout);
        deliveryAssertContains('<noscript><style>', $layout);
        assertSameValue(false, str_contains($layout, ' style='), 'inline style attribute');
        assertSameValue(false, str_contains($layout, '<script>'), 'inline JavaScript');
        assertSameValue(false, preg_match('/(?:src|href)=["\'](?:https?:)?\/\//i', $layout) === 1, 'remote asset');

        $viewsRoot = dirname(__DIR__) . '/resources/views';
        $fullDocuments = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($viewsRoot)) as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (str_contains(strtolower($source), '<!doctype') || str_contains(strtolower($source), '<html')) {
                $fullDocuments[] = str_replace('\\', '/', substr($file->getPathname(), strlen($viewsRoot) + 1));
            }
        }
        assertSameValue(['layouts/app.php'], $fullDocuments);

        foreach (uiClosureActiveViews() as $path) {
            $source = uiClosureSource('resources/views/' . $path);
            assertSameValue(false, preg_match('/<(?:html|head|body)\b/i', $source) === 1, $path . ': full document element');
            assertSameValue(false, str_contains(strtolower($source), '<style'), $path . ': style block');
            assertSameValue(false, str_contains(strtolower($source), ' style='), $path . ': style attribute');
            assertSameValue(false, preg_match('/<script\b(?![^>]*\bsrc=)/i', $source) === 1, $path . ': inline script');
            foreach (['onclick=', 'onchange=', 'onsubmit='] as $forbidden) {
                assertSameValue(false, str_contains(strtolower($source), $forbidden), $path . ': ' . $forbidden);
            }
            assertSameValue(false, preg_match('/(?:src|href)=["\'](?:https?:)?\/\//i', $source) === 1, $path . ': remote asset');
        }
    });

    $runner->add('E014 Phase 5 active screens keep headings visible labels and semantic tables', function (): void {
        foreach (uiClosureActiveViews() as $path) {
            $source = uiClosureSource('resources/views/' . $path);
            $expectedH1 = str_starts_with($path, 'reports/enrollments/') ? 0 : 1;
            assertSameValue($expectedH1, preg_match_all('/<h1\b/i', $source), $path . ': h1 count');
            assertSameValue([], uiClosureUnlabelledControls($source), $path . ': unlabelled controls');
            assertSameValue(false, preg_match('/tabindex=["\'][1-9][0-9]*["\']/i', $source) === 1, $path . ': positive tabindex');
        }

        $reportNavigation = uiClosureSource('resources/views/reports/enrollments/_navigation.php');
        assertSameValue(1, preg_match_all('/<h1\b/i', $reportNavigation), 'report shared h1');
        assertSameValue([], uiClosureUnlabelledControls($reportNavigation), 'report navigation labels');

        $tableViews = [];
        foreach (uiClosureActiveViews() as $path) {
            $source = uiClosureSource('resources/views/' . $path);
            if (!str_contains($source, '<table')) {
                continue;
            }
            $tableViews[] = $path;
            deliveryAssertContains('<caption', $source);
            assertSameValue(true, str_contains($source, 'table-responsive') || str_contains($source, 'report-table-wrap'), $path . ': responsive table wrapper');
            preg_match_all('/<th\b[^>]*>/i', $source, $headers);
            foreach ($headers[0] as $header) {
                deliveryAssertContains('scope="col"', $header);
            }
        }
        assertSameValue([
            'enrollments/index.php',
            'reports/enrollments/billing.php',
            'reports/enrollments/directory.php',
            'reports/enrollments/medical.php',
            'reports/enrollments/students.php',
            'reports/enrollments/summary.php',
        ], $tableViews);

        $status = uiClosureSource('resources/views/components/status-badge.php');
        foreach (['Activo', 'Inactivo', 'Borrador', 'Enviada', 'Completada', 'Cancelada', 'No iniciada', 'Pendiente', 'No requerida'] as $label) {
            deliveryAssertContains($label, $status);
        }
    });

    $runner->add('E014 Phase 5 responsive CSS preserves focus contrast reflow and disabled states', function (): void {
        $css = uiClosureSource('public/css/app.css');
        foreach ([
            '--app-primary-readable-surface: color-mix(in srgb, var(--app-primary) 44%, black)',
            'background: var(--app-primary-readable-surface)',
            '.app-resource-section button:disabled',
            '.app-resource-section button:focus-visible',
            'outline: 0.2rem solid #111827',
            'grid-template-columns: repeat(auto-fit, minmax(min(100%, 14rem), 1fr))',
            'overflow-x: auto',
            'overflow-wrap: anywhere',
            '@media (min-width: 768px)',
            '@media (max-width: 991.98px)',
            '@media (max-width: 575.98px)',
        ] as $expected) {
            deliveryAssertContains($expected, $css);
        }
        assertSameValue(false, preg_match('/outline\s*:\s*(?:none\b|0(?:\s*!important)?\s*[;}])/i', $css) === 1, 'focus removal');
        assertSameValue(false, str_contains($css, '!important'), 'application CSS important rule');
        assertSameValue(false, preg_match('/\b(?:html|body)\b[^{}]*\{[^}]*overflow-x\s*:\s*(?:auto|scroll|hidden)/is', $css) === 1, 'global overflow workaround');
        assertSameValue(true, uiClosureContrastRatio('#707070', '#FFFFFF') >= 4.5, 'worst-case primary text contrast');
    });

    $runner->add('E014 Phase 5 Spanish White Label and presentation security stay fail closed', function (): void {
        $sources = [];
        foreach (uiClosureActiveViews() as $path) {
            $sources[] = uiClosureSource('resources/views/' . $path);
        }
        foreach (['components/navigation.php', 'components/feedback.php', 'components/status-badge.php'] as $path) {
            $sources[] = uiClosureSource('resources/views/' . $path);
        }
        $visible = implode("\n", $sources);
        foreach ([
            'Family resources',
            'Current family',
            'Select a family',
            'Review and Submit Enrollment',
            'Representative Personal Information',
            'Emergency Contacts',
            'Authorized Pickups',
            'No active Academic Period',
            'Saving...',
            'Saved',
            'Save error',
        ] as $obsoleteEnglish) {
            assertSameValue(false, str_contains($visible, $obsoleteEnglish), $obsoleteEnglish);
        }
        foreach (['Colegio Antares', 'Unidad Educativa Antares', 'antares.edu.ec'] as $institutionIdentity) {
            assertSameValue(false, stripos($visible, $institutionIdentity) !== false, $institutionIdentity);
        }

        $acknowledgements = uiClosureSource('resources/views/institutional-acknowledgements/index.php');
        assertSameValue(false, str_contains($acknowledgements, " . ') — ' . \$period->status"), 'raw AcademicPeriod status label');
        deliveryAssertContains("\$period->status === 'ACTIVE' ? 'Activo' : 'Inactivo'", $acknowledgements);

        $representativeAcknowledgements = uiClosureSource('resources/views/representative-portal/acknowledgements.php');
        foreach (["['http', 'https']", '$escape($requirement->url)', 'rel="noopener noreferrer"'] as $expected) {
            deliveryAssertContains($expected, $representativeAcknowledgements);
        }
        assertSameValue(false, str_contains($acknowledgements, 'href="<?= $escape($requirement->url)'), 'unvalidated administrator link');

        $branding = uiClosureSource('app/Shared/Http/WhiteLabelBranding.php');
        foreach (['DEFAULT_DISPLAY_NAME', 'publicAssetPath', 'realpath', 'str_starts_with', "'/^#[0-9A-Fa-f]{6}$/D'"] as $expected) {
            deliveryAssertContains($expected, $branding);
        }
    });

    $runner->add('E014 Phase 5 keeps route ownership reports and progressive autosave contracts exact', function (): void {
        $routes = uiClosureSource('routes/web.php');
        assertSameValue(47, substr_count($routes, '$router->get('));
        assertSameValue(71, substr_count($routes, '$router->post('));

        foreach (['summary', 'students', 'directory', 'billing', 'medical'] as $report) {
            $view = uiClosureSource('resources/views/reports/enrollments/' . $report . '.php');
            deliveryAssertContains('report-table-wrap', $view);
            deliveryAssertContains('<caption', $view);
            deliveryAssertContains('/reports/enrollments/' . $report . '/csv', $view);
        }

        $enrollment = uiClosureSource('resources/views/representative-portal/enrollment.php');
        foreach ([
            '$portal->liveDataMaintenanceEnabled',
            '$portal->enrollmentDraftMaintenanceEnabled',
            'Datos actuales del SIS',
            'Información anual de matrícula',
            'data-enrollment-fallback-save',
            'data-enrollment-navigation',
            'app-readonly-panel',
        ] as $expected) {
            deliveryAssertContains($expected, $enrollment);
        }
        $placementStart = strpos($enrollment, 'id="placement-heading"');
        $placementEnd = strpos($enrollment, '</section>', is_int($placementStart) ? $placementStart : 0);
        $placement = is_int($placementStart) && is_int($placementEnd)
            ? substr($enrollment, $placementStart, $placementEnd - $placementStart)
            : '';
        foreach (['<form', '<input', '<select', '<textarea'] as $control) {
            assertSameValue(false, str_contains($placement, $control), 'AcademicPlacement ' . $control);
        }

        $script = uiClosureSource('public/js/representative-enrollment.js');
        foreach ([
            'const DEBOUNCE_MS = 900;',
            'state.revision += 1;',
            'this.requestQueue.then',
            'await this.flushAll()',
            "credentials: 'same-origin'",
            'keepalive: true',
            "'Guardando...'",
            "'Guardado'",
            "'Error al guardar'",
            'detail.disabled = !enabled',
            'detail.value = \'\'',
        ] as $expected) {
            deliveryAssertContains($expected, $script);
        }
        foreach (['localStorage', 'sessionStorage', 'innerHTML', 'insertAdjacentHTML', 'sendBeacon', '/api/'] as $forbidden) {
            assertSameValue(false, str_contains($script, $forbidden), $forbidden);
        }

        $layout = uiClosureSource('resources/views/layouts/app.php');
        deliveryAssertContains('<noscript><style>', $layout);
        deliveryAssertContains('display: block !important', $layout);
    });

    $runner->add('E014 Phase 5 active Views remain presentation only', function (): void {
        foreach (uiClosureActiveViews() as $path) {
            $source = uiClosureSource('resources/views/' . $path);
            foreach (['PDO', 'DELETE FROM', 'ConnectionManager', '->prepare(', '->query('] as $forbidden) {
                assertSameValue(false, stripos($source, $forbidden) !== false, $path . ': ' . $forbidden);
            }
            assertSameValue(false, preg_match('/\b(?:SELECT|INSERT|UPDATE)\b[\s\S]{0,200}\b(?:FROM|INTO|SET)\b/i', $source) === 1, $path . ': SQL statement');
        }
    });
}

function uiClosureActiveViews(): array
{
    return [
        'auth/forgot-password.php',
        'auth/login.php',
        'dashboard/index.php',
        'enrollments/index.php',
        'enrollments/review.php',
        'families/create-representative.php',
        'families/create-student.php',
        'families/index.php',
        'families/not-found.php',
        'families/resources.php',
        'families/show.php',
        'institutional-acknowledgements/index.php',
        'persons/form.php',
        'persons/index.php',
        'persons/not-found.php',
        'persons/show.php',
        'reports/enrollments/billing.php',
        'reports/enrollments/directory.php',
        'reports/enrollments/index.php',
        'reports/enrollments/medical.php',
        'reports/enrollments/students.php',
        'reports/enrollments/summary.php',
        'representative-portal/acknowledgements.php',
        'representative-portal/data.php',
        'representative-portal/enrollment-hub.php',
        'representative-portal/enrollment-summary.php',
        'representative-portal/enrollment-submission-review.php',
        'representative-portal/enrollment.php',
        'representative-portal/forbidden.php',
        'representative-portal/index.php',
        'representative-portal/no-family.php',
        'representative-portal/resources.php',
        'representative-users/manage.php',
        'representative-users/not-found.php',
        'representative-users/session-expired.php',
    ];
}

function uiClosureUnlabelledControls(string $source): array
{
    $markup = preg_replace('/<\?(?:php|=)?[\s\S]*?\?>/', 'PHP', $source);
    if (!is_string($markup)) {
        throw new \RuntimeException('Unable to normalize View source.');
    }
    $withoutImplicitLabels = preg_replace('/<label\b[^>]*>[\s\S]*?<\/label>/i', '', $markup);
    if (!is_string($withoutImplicitLabels)) {
        throw new \RuntimeException('Unable to inspect View labels.');
    }

    preg_match_all('/<(?:input|select|textarea)\b[^>]*>/i', $withoutImplicitLabels, $controls);
    $unlabelled = [];
    foreach ($controls[0] as $control) {
        if (preg_match('/\btype=["\']hidden["\']/i', $control) === 1) {
            continue;
        }
        if (preg_match('/\bid=["\']([^"\']+)["\']/i', $control, $id) !== 1
            || preg_match('/<label\b[^>]*\bfor=["\']' . preg_quote($id[1], '/') . '["\']/i', $markup) !== 1) {
            $unlabelled[] = preg_replace('/\s+/', ' ', $control);
        }
    }

    return $unlabelled;
}

function uiClosureContrastRatio(string $foreground, string $background): float
{
    $luminance = static function (string $color): float {
        $channels = [hexdec(substr($color, 1, 2)), hexdec(substr($color, 3, 2)), hexdec(substr($color, 5, 2))];
        $linear = array_map(static function (int $channel): float {
            $value = $channel / 255;

            return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, $channels);

        return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
    };

    $first = $luminance($foreground);
    $second = $luminance($background);

    return (max($first, $second) + 0.05) / (min($first, $second) + 0.05);
}

function uiClosureSource(string $relativePath): string
{
    $source = file_get_contents(dirname(__DIR__) . '/' . $relativePath);
    if (!is_string($source)) {
        throw new \RuntimeException('UI/UX closure source could not be read: ' . $relativePath);
    }

    return $source;
}
