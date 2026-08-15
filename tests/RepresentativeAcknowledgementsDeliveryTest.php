<?php

declare(strict_types=1);

namespace Tests;

use App\AcademicCore\Domain\AcademicPeriodStatus;
use App\InstitutionalDocuments\Http\RepresentativeAcknowledgementController;
use Tests\Support\TestRunner;

function registerRepresentativeAcknowledgementsDeliveryTests(TestRunner $runner): void
{
    $runner->add('E009 Representative acknowledgements exposes exactly one authenticated GET and POST', function (): void {
        $routes = (string) file_get_contents(dirname(__DIR__) . '/routes/web.php');
        assertSameValue(1, substr_count($routes, "'/representative/acknowledgements',"));
        assertSameValue(1, substr_count($routes, "'/representative/acknowledgements/complete',"));
        assertSameValue(2, substr_count($routes, '[$representativeAcknowledgementController,'));
        $slice = substr($routes, (int) strpos($routes, "'/representative/acknowledgements',"));
        $slice = substr($slice, 0, (int) strpos($slice, "'/representative/family',"));
        assertSameValue(2, substr_count($slice, 'AuthenticationMiddleware::class'));
        foreach (['AdministrationMiddleware', 'academic_period_id', 'representative_id'] as $forbidden) {
            assertSameValue(false, str_contains($slice, $forbidden), $forbidden);
        }
    });

    $runner->add('E009 Representative acknowledgement GET rejects a non-Representative', function (): void {
        $fixture = representativeAcknowledgementDeliveryFixture(withRepresentative: false);
        $html = $fixture['controller']->index();

        assertSameValue(403, http_response_code());
        deliveryAssertContains('Institutional Acknowledgements unavailable', $html);
        assertSameValue(false, str_contains($html, 'representative_id'));
    });

    $runner->add('E009 Representative acknowledgement GET renders safe pending requirements', function (): void {
        $fixture = representativeAcknowledgementDeliveryFixture(requirements: [
            representativeAcknowledgementRequirement(
                10,
                5,
                '<script>Review & "one"</script>',
                'https://example.test/a?x=1&y=2',
                'Official <A> & "B"',
            ),
            representativeAcknowledgementRequirement(11, 5, 'Unsafe script', 'javascript:alert(1)'),
            representativeAcknowledgementRequirement(12, 5, 'Unsafe data', 'data:text/html,test'),
        ]);
        $html = $fixture['controller']->index();

        assertSameValue(200, http_response_code());
        deliveryAssertContains('Academic Period 2026-2027', $html);
        assertSameValue(3, substr_count($html, 'name="acknowledged_requirement_ids[]"'));
        deliveryAssertContains('&lt;script&gt;Review &amp; &quot;one&quot;&lt;/script&gt;', $html);
        deliveryAssertContains('Official &lt;A&gt; &amp; &quot;B&quot;', $html);
        deliveryAssertContains('href="https://example.test/a?x=1&amp;y=2"', $html);
        deliveryAssertContains('rel="noopener noreferrer"', $html);
        assertSameValue(false, str_contains($html, 'href="javascript:'));
        assertSameValue(false, str_contains($html, 'href="data:'));
        assertSameValue(false, str_contains($html, 'name="academic_period_id"'));
        assertSameValue(false, str_contains($html, 'name="representative_id"'));
    });

    $runner->add('E009 Representative acknowledgement GET handles no period zero requirements and completion', function (): void {
        $noPeriod = representativeAcknowledgementDeliveryFixture(periods: []);
        $noPeriodHtml = $noPeriod['controller']->index();
        deliveryAssertContains('No active Academic Period is currently configured.', $noPeriodHtml);
        assertSameValue(false, str_contains($noPeriodHtml, 'acknowledged_requirement_ids'));

        $empty = representativeAcknowledgementDeliveryFixture();
        $emptyHtml = $empty['controller']->index();
        deliveryAssertContains('No institutional acknowledgements are required', $emptyHtml);
        assertSameValue(false, str_contains($emptyHtml, '/complete'));
        assertSameValue(0, $empty['services']['completions']->saveCount);

        $completed = representativeAcknowledgementDeliveryFixture(requirements: [
            representativeAcknowledgementRequirement(10),
        ]);
        $completed['services']['complete']->handle([10]);
        $completedHtml = $completed['controller']->index();
        deliveryAssertContains('<strong>Completed</strong>', $completedHtml);
        deliveryAssertContains('2026-08-14 20:21:22 UTC', $completedHtml);
        assertSameValue(false, str_contains($completedHtml, 'Confirm I have reviewed'));
    });

    $runner->add('E009 Representative acknowledgement POST requires CSRF and uses 303 PRG once', function (): void {
        $fixture = representativeAcknowledgementDeliveryFixture(requirements: [
            representativeAcknowledgementRequirement(10),
            representativeAcknowledgementRequirement(11),
        ]);

        deliveryRequest('POST', '/representative/acknowledgements/complete', [
            '_csrf_token' => 'invalid',
            'acknowledged_requirement_ids' => ['10', '11'],
        ]);
        $invalid = $fixture['controller']->complete();
        assertSameValue(403, http_response_code());
        deliveryAssertContains('could not be verified', $invalid);
        assertSameValue(0, $fixture['services']['completions']->saveCount);

        deliveryRequest('POST', '/representative/acknowledgements/complete', [
            '_csrf_token' => 'delivery-csrf',
            'acknowledged_requirement_ids' => ['11', '10'],
        ]);
        assertSameValue('', $fixture['controller']->complete());
        assertSameValue(303, http_response_code());
        assertSameValue(1, $fixture['services']['completions']->saveCount);
        deliveryAssertContains('completed successfully', $fixture['controller']->index());

        deliveryRequest('POST', '/representative/acknowledgements/complete', [
            '_csrf_token' => 'delivery-csrf',
            'acknowledged_requirement_ids' => ['10', '11'],
        ]);
        assertSameValue('', $fixture['controller']->complete());
        assertSameValue(303, http_response_code());
        assertSameValue(1, $fixture['services']['completions']->saveCount);
    });

    $runner->add('E009 Representative acknowledgement POST rejects stale requirement and period sets', function (): void {
        $changed = representativeAcknowledgementDeliveryFixture(requirements: [
            representativeAcknowledgementRequirement(10),
        ]);
        $changed['controller']->index();
        $changed['services']['requirements']->save(representativeAcknowledgementRequirement(11));
        representativeAcknowledgementDeliveryPost($changed['controller'], [10]);
        assertSameValue(303, http_response_code());
        assertSameValue(0, $changed['services']['completions']->saveCount);
        deliveryAssertContains('requirements changed', $changed['controller']->index());

        $switched = representativeAcknowledgementDeliveryFixture(
            requirements: [
                representativeAcknowledgementRequirement(20, 5),
                representativeAcknowledgementRequirement(30, 6),
            ],
            periods: [
                representativeAcknowledgementPeriod(5, AcademicPeriodStatus::Active),
                representativeAcknowledgementPeriod(6, AcademicPeriodStatus::Inactive, '2027-2028'),
            ],
        );
        $switched['controller']->index();
        $a = $switched['services']['periods']->findById(
            new \App\AcademicCore\Domain\ValueObject\AcademicPeriodId(5),
        );
        $b = $switched['services']['periods']->findById(
            new \App\AcademicCore\Domain\ValueObject\AcademicPeriodId(6),
        );
        $a?->deactivate();
        $b?->activate();
        $switched['services']['periods']->save($a);
        $switched['services']['periods']->save($b);
        representativeAcknowledgementDeliveryPost($switched['controller'], [20]);
        assertSameValue(0, $switched['services']['completions']->saveCount);
        assertSameValue(6, $switched['services']['resolve']->handle()->academicPeriodId);
    });

    $runner->add('E009 Representative Portal landing gates navigation without changing Family selection', function (): void {
        $pending = representativePortalFixture(
            acknowledgementRequirements: [representativeAcknowledgementRequirement(10)],
        );
        $pending['families']->seed(familyContextFamily(10, 'Family A', [33]));
        $pendingHtml = $pending['controller']->index();
        deliveryAssertContains('Institutional Acknowledgements are required', $pendingHtml);
        deliveryAssertContains('/representative/acknowledgements', $pendingHtml);
        assertSameValue(false, str_contains($pendingHtml, 'href="/representative/resources"'));

        $empty = representativePortalFixture();
        $empty['families']->seed(familyContextFamily(10, 'Family A', [33]));
        deliveryAssertContains('href="/representative/resources"', $empty['controller']->index());

        $noPeriod = representativePortalFixture(academicPeriods: []);
        $noPeriod['families']->seed(familyContextFamily(10, 'Family A', [33]));
        $noPeriodHtml = $noPeriod['controller']->index();
        deliveryAssertContains('No active Academic Period', $noPeriodHtml);
        assertSameValue(false, str_contains($noPeriodHtml, 'href="/representative/resources"'));

        $multiple = representativePortalFixture(
            acknowledgementRequirements: [representativeAcknowledgementRequirement(10)],
        );
        $multiple['families']->seed(familyContextFamily(10, 'Family A', [33]));
        $multiple['families']->seed(familyContextFamily(20, 'Family B', [33]));
        $multipleHtml = $multiple['controller']->index();
        deliveryAssertContains('Select a family', $multipleHtml);
        deliveryAssertContains('Institutional Acknowledgements are required', $multipleHtml);
    });

    $runner->add('E009 Representative acknowledgement Delivery remains thin and session-minimal', function (): void {
        $controller = (string) file_get_contents(
            dirname(__DIR__) . '/app/InstitutionalDocuments/Http/RepresentativeAcknowledgementController.php',
        );
        foreach (['PDO', 'Repository', 'SELECT ', 'INSERT ', 'UPDATE ', 'DELETE ',
            'GetAuthenticatedRepresentative', 'GetActiveAcademicPeriod'] as $forbidden) {
            assertSameValue(false, str_contains($controller, $forbidden), $forbidden);
        }
        foreach (['GetRepresentativeAcknowledgementPortalState',
            'CompleteAuthenticatedRepresentativeAcknowledgements', 'CsrfTokenManager'] as $required) {
            assertSameValue(true, str_contains($controller, $required), $required);
        }
        foreach (['academic_period_id', 'representative_id', 'requirement_ids', 'completion_id', 'satisfaction'] as $key) {
            assertSameValue(false, str_contains($controller, "session->put('" . $key), $key);
        }
        $view = (string) file_get_contents(
            dirname(__DIR__) . '/resources/views/representative-portal/acknowledgements.php',
        );
        assertSameValue(true, str_contains($view, 'htmlspecialchars'));
        assertSameValue(0, preg_match('/antares|ueant|colegio/i', $view));
    });
}

/** @param list<\App\InstitutionalDocuments\Domain\AcknowledgementRequirement> $requirements */
function representativeAcknowledgementDeliveryFixture(
    bool $withRepresentative = true,
    array $requirements = [],
    ?array $periods = null,
): array {
    $identity = familyContextAuthorizationFixture(withRepresentative: $withRepresentative);
    $services = representativeAcknowledgementTestServices(
        $identity['getRepresentative'],
        $requirements,
        $periods,
    );

    return [
        'identity' => $identity,
        'services' => $services,
        'controller' => new RepresentativeAcknowledgementController(
            $services['state'],
            $services['complete'],
            new FakeDeliveryCsrf(),
            $identity['session'],
        ),
    ];
}

/** @param list<int> $ids */
function representativeAcknowledgementDeliveryPost(
    RepresentativeAcknowledgementController $controller,
    array $ids,
): string {
    deliveryRequest('POST', '/representative/acknowledgements/complete', [
        '_csrf_token' => 'delivery-csrf',
        'acknowledged_requirement_ids' => array_map('strval', $ids),
    ]);

    return $controller->complete();
}
