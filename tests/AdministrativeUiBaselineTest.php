<?php

declare(strict_types=1);

namespace Tests;

use Tests\Support\TestRunner;

function registerAdministrativeUiBaselineTests(TestRunner $runner): void
{
    $runner->add('E014 Phase 3 administrative entry screens keep exact lookup and mutation contracts', function (): void {
        $persons = administrativeUiSource('resources/views/persons/index.php');
        $personForm = administrativeUiSource('resources/views/persons/form.php');
        $families = administrativeUiSource('resources/views/families/index.php');

        foreach (['Personas', 'Crear persona', 'Consultar persona por ID', 'action="/persons/show"', 'name="id"'] as $expected) {
            deliveryAssertContains($expected, $persons);
        }
        foreach (['/persons/create', '/persons/update', 'name="_csrf_token"', 'name="document_type_id"', 'name="status"'] as $expected) {
            deliveryAssertContains($expected, $personForm);
        }
        foreach (['Familias', 'Crear representante y familia', 'Consultar familia por ID', 'action="/families/show"', 'name="id"'] as $expected) {
            deliveryAssertContains($expected, $families);
        }
        foreach (['search', 'filter', 'pagination', 'autocomplete'] as $forbidden) {
            assertSameValue(false, str_contains(strtolower($persons . $families), $forbidden));
        }
    });

    $runner->add('E014 Phase 3 Family Resources and credentials retain protected POST forms', function (): void {
        $resources = administrativeUiSource('resources/views/families/resources.php');
        $credentials = administrativeUiSource('resources/views/representative-users/manage.php');

        foreach ([
            '/families/resources/addresses/create',
            '/families/resources/emergency-contacts/create',
            '/families/resources/authorized-pickups/create',
            'name="_csrf_token"',
            'Direcciones asignadas',
            'Contactos de emergencia asignados',
            'Personas autorizadas asignadas',
            'Eliminar asignación',
            'Personas autorizadas para retirar',
        ] as $expected) {
            deliveryAssertContains($expected, $resources);
        }
        foreach ([
            '/representative-users/create',
            '/representative-users/password',
            'autocomplete="new-password"',
            'Reemplazar contraseña',
            'name="_csrf_token"',
        ] as $expected) {
            deliveryAssertContains($expected, $credentials);
        }
        foreach (['password_hash', 'current_password', 'forgot-password'] as $forbidden) {
            assertSameValue(false, str_contains($credentials, $forbidden));
        }
    });

    $runner->add('E014 Phase 3 Acknowledgements and Enrollment actions preserve exact authority contracts', function (): void {
        $acknowledgements = administrativeUiSource('resources/views/institutional-acknowledgements/index.php');
        $review = administrativeUiSource('resources/views/enrollments/review.php');

        foreach ([
            'Confirmaciones institucionales',
            'name="academic_period_id"',
            '/institutional-acknowledgements/requirements/create',
            '/institutional-acknowledgements/requirements/update',
            'name="requirement_id"',
            'name="_csrf_token"',
        ] as $expected) {
            deliveryAssertContains($expected, $acknowledgements);
        }
        foreach ([
            'Datos actuales del SIS',
            'Información anual de la matrícula',
            '/enrollments/reopen',
            '/enrollments/complete',
            '/enrollments/cancel',
            'name="enrollment_id"',
            'name="_csrf_token"',
        ] as $expected) {
            deliveryAssertContains($expected, $review);
        }
        assertSameValue(false, str_contains($acknowledgements, 'href="<?= $escape($requirement->url)'));
        assertSameValue(false, str_contains($review, 'target_status'));
    });

    $runner->add('E014 Phase 3 reports keep five destinations responsive tables and exact CSV links', function (): void {
        $navigation = administrativeUiSource('resources/views/reports/enrollments/_navigation.php');
        foreach (['summary', 'students', 'directory', 'billing', 'medical'] as $report) {
            deliveryAssertContains("/reports/enrollments/{$report}", $navigation);
            $view = administrativeUiSource("resources/views/reports/enrollments/{$report}.php");
            deliveryAssertContains('report-table-wrap', $view);
            deliveryAssertContains('<caption>', $view);
            deliveryAssertContains("/reports/enrollments/{$report}/csv", $view);
        }
        deliveryAssertContains('name="academic_period_id"', $navigation);
        assertSameValue(false, str_contains($navigation, '<style>'));
    });

    $runner->add('E014 Phase 3 shared presentation is escaped semantic responsive and JavaScript independent', function (): void {
        $status = administrativeUiSource('resources/views/components/status-badge.php');
        $empty = administrativeUiSource('resources/views/components/empty-state.php');
        $breadcrumb = administrativeUiSource('resources/views/components/breadcrumb.php');
        $css = administrativeUiSource('public/css/app.css');

        foreach (['Borrador', 'Enviada', 'Completada', 'Cancelada', '$escape($statusLabel)'] as $expected) {
            deliveryAssertContains($expected, $status);
        }
        deliveryAssertContains('role="status"', $empty);
        deliveryAssertContains('aria-current="page"', $breadcrumb);
        foreach (['.app-form-section', '.app-data-list', '.app-action-group', '.app-empty-state', '.report-table-wrap', '@media (max-width: 575.98px)'] as $expected) {
            deliveryAssertContains($expected, $css);
        }
        assertSameValue(false, str_contains($status . $empty . $breadcrumb, '<script'));
    });
}

function administrativeUiSource(string $relativePath): string
{
    $source = file_get_contents(dirname(__DIR__) . '/' . $relativePath);
    if (!is_string($source)) {
        throw new \RuntimeException('Administrative UI source could not be read: ' . $relativePath);
    }

    return $source;
}
