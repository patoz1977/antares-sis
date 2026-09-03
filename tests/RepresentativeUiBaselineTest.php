<?php

declare(strict_types=1);

namespace Tests;

use Tests\Support\TestRunner;

function registerRepresentativeUiBaselineTests(TestRunner $runner): void
{
    $runner->add('E014 Phase 4 Portal preserves authorized Family selection and safe Spanish states', function (): void {
        $portal = representativeUiSource('resources/views/representative-portal/index.php');
        $noFamily = representativeUiSource('resources/views/representative-portal/no-family.php');
        $forbidden = representativeUiSource('resources/views/representative-portal/forbidden.php');

        foreach ([
            'Portal de representantes', 'Familia actual', 'Aceptaciones institucionales',
            'Matrícula', 'Recursos familiares', 'action="/representative/family"',
            'name="_csrf_token"', 'name="family_id"', 'Seleccionar familia',
        ] as $expected) {
            deliveryAssertContains($expected, $portal);
        }
        deliveryAssertContains('No existe una familia autorizada disponible', $noFamily);
        deliveryAssertContains('No puedes acceder al contexto solicitado', $forbidden);
        foreach ([$portal, $noFamily, $forbidden] as $source) {
            deliveryAssertContains('htmlspecialchars', $source);
            assertSameValue(false, str_contains($source, 'name="representative_id"'));
        }
    });

    $runner->add('E014 Phase 4 acknowledgements and review retain exact consequential POST contracts', function (): void {
        $acknowledgements = representativeUiSource('resources/views/representative-portal/acknowledgements.php');
        $review = representativeUiSource('resources/views/representative-portal/enrollment-submission-review.php');

        foreach ([
            'Aceptaciones institucionales', 'action="/representative/acknowledgements/complete"',
            'name="acknowledged_requirement_ids[]"', 'name="_csrf_token"',
            "['http', 'https']", 'target="_blank" rel="noopener noreferrer"',
        ] as $expected) {
            deliveryAssertContains($expected, $acknowledgements);
        }
        foreach ([
            'Revisar y enviar matrícula', 'Datos actuales del SIS', 'Recursos familiares actuales',
            'Información anual de matrícula', 'Preparación para el envío',
            'action="/representative/enrollment/submit"', 'name="expected_family_id"',
            'name="expected_academic_period_id"', 'name="student_id"', 'name="_csrf_token"',
        ] as $expected) {
            deliveryAssertContains($expected, $review);
        }
        foreach (['name="enrollment_id"', 'name="representative_id"', 'target_status'] as $forbidden) {
            assertSameValue(false, str_contains($review, $forbidden), $forbidden);
        }
    });

    $runner->add('E014 Phase 4 Enrollment separates live and annual ownership without changing autosave', function (): void {
        $enrollment = representativeUiSource('resources/views/representative-portal/enrollment.php');
        $script = representativeUiSource('public/js/representative-enrollment.js');

        foreach ([
            '$portal->liveDataMaintenanceEnabled', '$portal->enrollmentDraftMaintenanceEnabled',
            'Datos actuales del SIS', 'Información anual de matrícula', 'Ubicación académica',
            'app-readonly-panel', 'data-enrollment-autosave', 'data-enrollment-fallback-save',
            'data-enrollment-navigation', 'data-medical-controller',
        ] as $expected) {
            deliveryAssertContains($expected, $enrollment);
        }
        $placementStart = strpos($enrollment, 'id="placement-heading"');
        $placementEnd = strpos($enrollment, '</section>', is_int($placementStart) ? $placementStart : 0);
        $placement = is_int($placementStart) && is_int($placementEnd)
            ? substr($enrollment, $placementStart, $placementEnd - $placementStart)
            : '';
        foreach (['<form', '<input', '<select', '<textarea'] as $editableControl) {
            assertSameValue(false, str_contains($placement, $editableControl), $editableControl);
        }
        foreach ([
            '/representative/enrollment/open',
            '/representative/enrollment/representative/personal',
            '/representative/enrollment/representative/contact',
            '/representative/enrollment/representative/employment',
            '/representative/enrollment/student/personal',
            '/representative/enrollment/student/billing',
            '/representative/enrollment/student/medical',
            '/representative/enrollment/student/transport',
            '/representative/enrollment/student/leave-alone',
        ] as $action) {
            assertSameValue(1, substr_count($enrollment, 'action="' . $action . '"'), $action);
        }
        foreach ([
            'const DEBOUNCE_MS = 900;', "credentials: 'same-origin'", 'new window.FormData(form)',
            'await this.flushAll()', "'Guardando...'", "'Guardado'", "'Error al guardar'",
        ] as $expected) {
            deliveryAssertContains($expected, $script);
        }
        foreach (['localStorage', 'sessionStorage', 'sendBeacon', '/api/', 'enrollment_id'] as $forbidden) {
            assertSameValue(false, str_contains($script, $forbidden), $forbidden);
        }
    });

    $runner->add('E014 Phase 4 Family Resources keeps exact identity fields and lifecycle endpoints', function (): void {
        $resources = representativeUiSource('resources/views/representative-portal/resources.php');

        foreach ([
            'Recursos familiares', 'Direcciones', 'Contactos de emergencia',
            'Personas autorizadas para retirar', 'name="_csrf_token"', 'name="family_id"',
            '/representative/resources/addresses/create', '/representative/resources/addresses/update',
            '/representative/resources/address', '/representative/resources/students/address',
            '/representative/resources/emergency-contacts/create',
            '/representative/resources/emergency-contacts/assign',
            '/representative/resources/authorized-pickups/create',
            '/representative/resources/authorized-pickups/assign',
        ] as $expected) {
            deliveryAssertContains($expected, $resources);
        }
        foreach ([
            'name="family_address_id"', 'name="family_emergency_contact_id"',
            'name="family_authorized_pickup_id"', 'name="assignment_id"', 'name="student_id"',
        ] as $identityField) {
            deliveryAssertContains($identityField, $resources);
        }
        assertSameValue(false, str_contains($resources, 'name="representative_id"'));
    });

    $runner->add('E014 Phase 4 presentation stays shared local responsive and route neutral', function (): void {
        $views = [
            representativeUiSource('resources/views/representative-portal/index.php'),
            representativeUiSource('resources/views/representative-portal/no-family.php'),
            representativeUiSource('resources/views/representative-portal/forbidden.php'),
            representativeUiSource('resources/views/representative-portal/acknowledgements.php'),
            representativeUiSource('resources/views/representative-portal/enrollment.php'),
            representativeUiSource('resources/views/representative-portal/enrollment-submission-review.php'),
            representativeUiSource('resources/views/representative-portal/resources.php'),
        ];
        foreach ($views as $view) {
            assertSameValue(false, str_contains($view, '<html'), 'shared shell ownership');
            assertSameValue(false, str_contains($view, '<style'), 'inline style block');
            assertSameValue(false, str_contains($view, ' style='), 'inline style attribute');
            assertSameValue(false, str_contains($view, '<script>'), 'inline script block');
            assertSameValue(false, str_contains($view, 'onclick='), 'inline event handler');
            assertSameValue(false, preg_match('/(?:src|href)="https?:\/\//i', $view) === 1, 'remote asset');
        }
        $allViews = implode("\n", $views);
        foreach ([
            'Family resources', 'Current family', 'Select a family',
            'Review and Submit Enrollment', 'Representative Personal Information',
            'Save Personal Information', 'Create Address', 'Emergency Contacts',
            'Authorized Pickups', 'No active Academic Period',
        ] as $obsoleteVisibleText) {
            assertSameValue(false, str_contains($allViews, $obsoleteVisibleText), $obsoleteVisibleText);
        }

        $css = representativeUiSource('public/css/app.css');
        foreach ([
            '.app-context-banner', '.app-section-nav', '.app-progress-grid',
            '.app-autosave-status', '.app-readonly-panel', '.app-resource-section',
            '@media (max-width: 575.98px)',
        ] as $expected) {
            deliveryAssertContains($expected, $css);
        }

        $routes = representativeUiSource('routes/web.php');
        assertSameValue(36, substr_count($routes, '$router->get('));
        assertSameValue(71, substr_count($routes, '$router->post('));
    });
}

function representativeUiSource(string $relativePath): string
{
    $source = file_get_contents(dirname(__DIR__) . '/' . $relativePath);
    if (!is_string($source)) {
        throw new \RuntimeException('Representative UI source could not be read: ' . $relativePath);
    }

    return $source;
}
