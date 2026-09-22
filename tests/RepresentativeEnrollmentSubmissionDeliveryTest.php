<?php

declare(strict_types=1);

namespace Tests;

use App\Enrollment\Domain\EnrollmentStatus;
use App\Enrollment\Domain\ValueObject\AcademicPeriodId;
use App\Enrollment\Domain\ValueObject\StudentId;
use App\Enrollment\Http\RepresentativeEnrollmentSubmissionController;
use App\Enrollment\Http\RepresentativeEnrollmentSubmissionViewDataFactory;
use RuntimeException;
use Tests\Support\TestRunner;

function registerRepresentativeEnrollmentSubmissionDeliveryTests(TestRunner $runner): void
{
    $runner->add('E012 Delivery exposes only authenticated Representative review and Submit routes', function (): void {
        $routes = representativeEnrollmentNormalizedSource('routes/web.php');
        foreach (['/representative/enrollment/review', '/representative/enrollment/submit'] as $path) {
            assertSameValue(1, substr_count($routes, "'" . $path . "'"), $path);
        }
        $start = strpos($routes, "\$router->get(\n    '/representative/enrollment/review'");
        $end = strpos($routes, "\$router->get(\n    '/representative/resources'", is_int($start) ? $start : 0);
        $slice = is_int($start) && is_int($end) ? substr($routes, $start, $end - $start) : '';
        assertSameValue(2, substr_count($slice, 'AuthenticationMiddleware::class'));
        assertSameValue(1, substr_count($slice, "[\$representativeEnrollmentSubmissionController, 'review']"));
        assertSameValue(1, substr_count($slice, "[\$representativeEnrollmentSubmissionController, 'submit']"));
        foreach (['AdministrationMiddleware', '/complete', '/cancel', '/reopen'] as $forbidden) {
            assertSameValue(false, str_contains($slice, $forbidden), $forbidden);
        }
    });

    $runner->add('E012 complete Draft review renders authoritative current and annual information read-only', function (): void {
        $fixture = representativeEnrollmentSubmissionDeliveryFixture();
        $html = representativeEnrollmentSubmissionReview($fixture['controller']);

        assertSameValue(200, http_response_code());
        foreach ([
            'Revisar y enviar matrícula', 'Stored Complete Person Record',
            'Período académico', 'Estado de matrícula', 'Datos actuales',
            'Current street', 'Contactos de emergencia', 'Personas autorizadas para retirar', 'Información adicional',
            'Representative Legal Name', 'Preparación para el envío',
            'Todos los requisitos actuales para el envío están completos.', 'Enviar matrícula',
        ] as $expected) {
            deliveryAssertContains($expected, $html);
        }
        foreach (['Familia actual', 'Datos actuales del SIS', 'Recursos familiares actuales', 'Información anual de matrícula', 'Inicio (UTC)', 'Envío (UTC)'] as $removed) {
            assertSameValue(false, str_contains($html, $removed), $removed);
        }
        deliveryAssertContains('method="post" action="/representative/enrollment/submit"', $html);
        foreach (['_csrf_token', 'expected_family_id', 'expected_academic_period_id', 'student_id'] as $field) {
            deliveryAssertContains('name="' . $field . '"', $html);
        }
        foreach (['name="enrollment_id"', 'name="family_id"', '<input type="text"', '<textarea', '<select'] as $forbidden) {
            assertSameValue(false, str_contains($html, $forbidden), $forbidden);
        }
        assertSameValue(0, $fixture['enrollments']->saveCalls);
        assertSameValue(0, $fixture['clock']->calls);
    });

    $runner->add('E012 incomplete review exposes exact authoritative messages and no Submit control', function (): void {
        $fixture = representativeEnrollmentSubmissionDeliveryFixture(
            acknowledgementsSatisfied: false,
            stableAcknowledgementsSatisfied: false,
        );
        $html = representativeEnrollmentSubmissionReview($fixture['controller']);

        assertSameValue(200, http_response_code());
        deliveryAssertContains('Esta matrícula todavía no puede enviarse.', $html);
        deliveryAssertContains(e012Requirement(
            $fixture['review']->handle(44)->validation,
            'ACKNOWLEDGEMENTS',
        )->message, $html);
        deliveryAssertContains('/representative/acknowledgements', $html);
        assertSameValue(false, str_contains($html, '>Enviar matrícula<'));
    });

    $runner->add('E012 review exposes missing current Family resources from the authoritative validation', function (): void {
        $fixture = representativeEnrollmentSubmissionDeliveryFixture();
        $fixture['families']->seed(e011PortalFamily());
        $html = representativeEnrollmentSubmissionReview($fixture['controller']);
        $validation = $fixture['review']->handle(44)->validation;

        foreach (['STUDENT_ADDRESS', 'EMERGENCY_CONTACT', 'PICKUP_OR_LEAVE_ALONE'] as $code) {
            deliveryAssertContains(e012Requirement($validation, $code)->message, $html);
        }
        deliveryAssertContains('No hay una dirección asignada actualmente.', $html);
        assertSameValue(false, str_contains($html, '>Enviar matrícula<'));
    });

    $runner->add('E012 review handles absent active period and invalid Student proposals safely', function (): void {
        $noPeriod = e012SubmissionFixture(periodActive: false);
        $session = new FakeSessionManager();
        $controller = new RepresentativeEnrollmentSubmissionController(
            $noPeriod['review'],
            $noPeriod['submit'],
            new RepresentativeEnrollmentSubmissionViewDataFactory(e010AcademicReferences()),
            $noPeriod['acknowledgements']['state'],
            new FakeDeliveryCsrf(),
            $session,
        );
        $html = representativeEnrollmentSubmissionReview($controller);
        assertSameValue(422, http_response_code());
        deliveryAssertContains('No hay un contexto de envío de matrícula activo', $html);

        deliveryRequest('GET', '/representative/enrollment/review', []);
        $html = $controller->review();
        assertSameValue(422, http_response_code());
        deliveryAssertContains('Seleccione un estudiante', $html);
    });

    $runner->add('E012 submitted completed and cancelled reviews degrade to read-only without lifecycle controls', function (): void {
        foreach ([EnrollmentStatus::Submitted, EnrollmentStatus::Completed, EnrollmentStatus::Cancelled] as $status) {
            $fixture = representativeEnrollmentSubmissionDeliveryFixture(status: $status);
            $html = representativeEnrollmentSubmissionReview($fixture['controller']);

            $statusLabel = match ($status) {
                EnrollmentStatus::Submitted => 'Enviada',
                EnrollmentStatus::Completed => 'Completada',
                EnrollmentStatus::Cancelled => 'Cancelada',
                default => throw new RuntimeException('Unexpected lifecycle fixture.'),
            };
            deliveryAssertContains('>' . $statusLabel . '</span>', $html);
            $message = match ($status) {
                EnrollmentStatus::Submitted => 'Esta matrícula fue enviada',
                EnrollmentStatus::Completed => 'Esta matrícula fue completada',
                EnrollmentStatus::Cancelled => 'Esta matrícula fue cancelada',
                default => throw new RuntimeException('Unexpected lifecycle fixture.'),
            };
            deliveryAssertContains($message, $html);
            deliveryAssertContains('Datos actuales', $html);
            deliveryAssertContains('Dirección del estudiante', $html);
            assertSameValue(false, str_contains($html, 'Preparación para el envío'), $status->value);
            assertSameValue(false, str_contains($html, 'Esta matrícula todavía no puede enviarse.'), $status->value);
            assertSameValue(false, str_contains($html, '>Enviar matrícula<'), $status->value);
            foreach (['Complete Enrollment', 'Cancel Enrollment', 'Reopen Enrollment'] as $forbidden) {
                assertSameValue(false, str_contains($html, $forbidden), $forbidden);
            }
        }
    });

    $runner->add('E012 reopened Draft with prior SubmittedAt remains compatible with Resubmission', function (): void {
        $fixture = representativeEnrollmentSubmissionDeliveryFixture(status: EnrollmentStatus::Submitted);
        $enrollment = $fixture['enrollments']->findByStudentAndAcademicPeriod(
            new StudentId(44),
            new AcademicPeriodId(5),
        ) ?? throw new RuntimeException('Expected Enrollment fixture.');
        $enrollment->reopen();
        $fixture['enrollments']->save($enrollment);
        $fixture['enrollments']->resetObservations();

        $html = representativeEnrollmentSubmissionReview($fixture['controller']);
        deliveryAssertContains('>Borrador</span>', $html);
        assertSameValue(false, str_contains($html, '2026-08-20 12:00:00'));
        deliveryAssertContains('Reenviar matrícula', $html);
    });

    $runner->add('E012 Submit uses CSRF Application authority PRG and generic success flash', function (): void {
        $fixture = representativeEnrollmentSubmissionDeliveryFixture();
        $result = representativeEnrollmentSubmissionPost($fixture['controller'], array_merge(
            representativeEnrollmentContext(),
            ['enrollment_id' => '999', 'representative_id' => '999'],
        ));

        assertSameValue('', $result);
        assertSameValue(303, http_response_code());
        $persisted = $fixture['enrollments']->findByStudentAndAcademicPeriod(
            new StudentId(44),
            new AcademicPeriodId(5),
        );
        assertSameValue(EnrollmentStatus::Submitted, $persisted?->status());
        assertSameValue(1, $fixture['enrollments']->saveCalls);
        assertSameValue(1, $fixture['clock']->calls);
        assertSameValue(
            'Matrícula enviada correctamente.',
            $fixture['session']->get('_flash_representative_enrollment_submission_success'),
        );

        $html = representativeEnrollmentSubmissionReview($fixture['controller']);
        deliveryAssertContains('Matrícula enviada correctamente.', $html);
        deliveryAssertContains('>Enviada</span>', $html);
        assertSameValue(false, str_contains($html, '>Enviar matrícula<'));
        assertSameValue(false, str_contains($html, '999'));
    });

    $runner->add('E012 forged duplicate Submit is rejected by Application and returns to current Review', function (): void {
        $fixture = representativeEnrollmentSubmissionDeliveryFixture(status: EnrollmentStatus::Submitted);
        assertSameValue('', representativeEnrollmentSubmissionPost(
            $fixture['controller'],
            representativeEnrollmentContext(),
        ));
        assertSameValue(303, http_response_code());
        assertSameValue(0, $fixture['enrollments']->saveCalls);
        assertSameValue(0, $fixture['clock']->calls);

        $html = representativeEnrollmentSubmissionReview($fixture['controller']);
        deliveryAssertContains('La matrícula aún no está lista para enviarse.', $html);
        deliveryAssertContains('>Enviada</span>', $html);
        assertSameValue(false, str_contains($html, '>Enviar matrícula<'));
    });

    $runner->add('E012 forged incomplete Submit returns by PRG and recomputes current requirements', function (): void {
        $fixture = representativeEnrollmentSubmissionDeliveryFixture(
            acknowledgementsSatisfied: false,
            stableAcknowledgementsSatisfied: false,
        );
        assertSameValue('', representativeEnrollmentSubmissionPost(
            $fixture['controller'],
            representativeEnrollmentContext(),
        ));
        assertSameValue(303, http_response_code());
        assertSameValue(0, $fixture['enrollments']->saveCalls);
        assertSameValue(0, $fixture['clock']->calls);

        $html = representativeEnrollmentSubmissionReview($fixture['controller']);
        deliveryAssertContains('La matrícula aún no está lista para enviarse.', $html);
        deliveryAssertContains('Esta matrícula todavía no puede enviarse.', $html);
        assertSameValue(false, str_contains($html, '>Enviar matrícula<'));
    });

    $runner->add('E012 Delivery rejects CSRF malformed stale and cross-context proposals without disclosure', function (): void {
        $invalidCsrf = representativeEnrollmentSubmissionDeliveryFixture();
        $html = representativeEnrollmentSubmissionPost($invalidCsrf['controller'], [
            '_csrf_token' => 'invalid',
            'expected_family_id' => '77',
            'expected_academic_period_id' => '5',
            'student_id' => '44',
        ]);
        assertSameValue(403, http_response_code());
        deliveryAssertContains('No se pudo verificar la solicitud', $html);
        assertSameValue(0, $invalidCsrf['enrollments']->saveCalls);

        $malformed = representativeEnrollmentSubmissionDeliveryFixture();
        $html = representativeEnrollmentSubmissionPost($malformed['controller'], [
            '_csrf_token' => 'delivery-csrf',
            'expected_family_id' => ['77'],
            'expected_academic_period_id' => '5',
            'student_id' => '44',
        ]);
        assertSameValue(422, http_response_code());
        deliveryAssertContains('contexto de envío de matrícula no es válido', $html);

        foreach ([
            ['expected_family_id' => '78'],
            ['expected_academic_period_id' => '6'],
            ['student_id' => '999'],
        ] as $proposal) {
            $fixture = representativeEnrollmentSubmissionDeliveryFixture();
            $html = representativeEnrollmentSubmissionPost(
                $fixture['controller'],
                array_merge(representativeEnrollmentContext(), $proposal),
            );
            assertSameValue(409, http_response_code());
            deliveryAssertContains('contexto de la matrícula cambió', $html);
            assertSameValue(false, str_contains($html, '78'), 'family proposal leaked');
            assertSameValue(false, str_contains($html, '999'), 'student proposal leaked');
            assertSameValue(0, $fixture['enrollments']->saveCalls);
        }

        $crossFamilyGet = representativeEnrollmentSubmissionDeliveryFixture();
        deliveryRequest('GET', '/representative/enrollment/review?student_id=999', ['student_id' => '999']);
        $html = $crossFamilyGet['controller']->review();
        assertSameValue(403, http_response_code());
        assertSameValue(false, str_contains($html, '999'));
    });

    $runner->add('E012 unexpected persistence failure stays generic and does not enter flash', function (): void {
        $fixture = representativeEnrollmentSubmissionDeliveryFixture();
        $fixture['enrollments']->saveFailure = new RuntimeException('SQLSTATE secret Enrollment row');
        $html = representativeEnrollmentSubmissionPost(
            $fixture['controller'],
            representativeEnrollmentContext(),
        );

        assertSameValue(500, http_response_code());
        deliveryAssertContains('No se pudo enviar la matrícula.', $html);
        assertSameValue(false, str_contains($html, 'SQLSTATE'));
        assertSameValue(false, str_contains($html, 'secret Enrollment row'));
        assertSameValue(null, $fixture['session']->get('_flash_representative_enrollment_submission_error'));
    });

    $runner->add('E012 review navigation reuses E011 autosave flush and form works without JavaScript', function (): void {
        $portal = representativeEnrollmentNormalizedSource('resources/views/representative-portal/enrollment-summary.php');
        deliveryAssertContains('/representative/enrollment/review', $portal);
        deliveryAssertContains('Revisar y enviar matrícula', $portal);

        $script = representativeEnrollmentNormalizedSource('public/js/representative-enrollment.js');
        foreach (['a[data-enrollment-navigation]', 'await this.flushAll()', 'event.preventDefault()'] as $required) {
            deliveryAssertContains($required, $script);
        }

        $review = representativeEnrollmentNormalizedSource(
            'resources/views/representative-portal/enrollment-submission-review.php',
        );
        deliveryAssertContains('<form method="post" action="/representative/enrollment/submit">', $review);
        assertSameValue(false, str_contains($review, 'onclick='));
        assertSameValue(false, str_contains($review, '<script'));
    });

    $runner->add('E012 Delivery wiring stays thin and preserves phase exclusions', function (): void {
        $bootstrap = representativeEnrollmentNormalizedSource('bootstrap/app.php');
        foreach ([
            'RepresentativeEnrollmentSubmissionViewDataFactory::class',
            'RepresentativeEnrollmentSubmissionController::class',
            'GetRepresentativeEnrollmentSubmissionReview::class',
            'SubmitRepresentativeEnrollment::class',
        ] as $binding) {
            deliveryAssertContains($binding, $bootstrap);
        }

        $controller = representativeEnrollmentNormalizedSource(
            'app/Enrollment/Http/RepresentativeEnrollmentSubmissionController.php',
        );
        foreach (['new PDO', 'SELECT ', 'INSERT ', 'UPDATE ', 'DELETE ', 'Repository', '->complete(', '->cancel(', '->reopen('] as $forbidden) {
            assertSameValue(false, str_contains($controller, $forbidden), $forbidden);
        }
        assertSameValue(false, file_exists(dirname(__DIR__) . '/database/migrations/011_create_submission.php'));
    });
}

/** @return array<string, mixed> */
function representativeEnrollmentSubmissionDeliveryFixture(
    bool $acknowledgementsSatisfied = true,
    bool $stableAcknowledgementsSatisfied = true,
    EnrollmentStatus $status = EnrollmentStatus::Draft,
): array {
    $fixture = e012SubmissionFixture(
        acknowledgementsSatisfied: $acknowledgementsSatisfied,
        stableAcknowledgementsSatisfied: $stableAcknowledgementsSatisfied,
        status: $status,
    );
    $session = new FakeSessionManager();
    $controller = new RepresentativeEnrollmentSubmissionController(
        $fixture['review'],
        $fixture['submit'],
        new RepresentativeEnrollmentSubmissionViewDataFactory(e010AcademicReferences()),
        $fixture['acknowledgements']['state'],
        new FakeDeliveryCsrf(),
        $session,
    );
    $fixture['controller'] = $controller;
    $fixture['session'] = $session;

    return $fixture;
}

function representativeEnrollmentSubmissionReview(
    RepresentativeEnrollmentSubmissionController $controller,
    int $studentId = 44,
): string {
    deliveryRequest(
        'GET',
        '/representative/enrollment/review?student_id=' . $studentId,
        ['student_id' => (string) $studentId],
    );

    return $controller->review();
}

/** @param array<string, mixed> $input */
function representativeEnrollmentSubmissionPost(
    RepresentativeEnrollmentSubmissionController $controller,
    array $input,
): string {
    deliveryRequest('POST', '/representative/enrollment/submit', $input);

    return $controller->submit();
}
