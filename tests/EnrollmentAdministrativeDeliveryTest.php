<?php

declare(strict_types=1);

namespace Tests;

use App\AcademicCore\Domain\AcademicPeriodStatus;
use App\Enrollment\Application\Administrative\CancelEnrollment;
use App\Enrollment\Application\Administrative\CompleteEnrollment;
use App\Enrollment\Application\Administrative\GetAdministrativeEnrollmentReview;
use App\Enrollment\Application\Administrative\GetAdministrativeEnrollmentReviewContext;
use App\Enrollment\Application\Administrative\ListSubmittedEnrollments;
use App\Enrollment\Application\Administrative\ReopenEnrollment;
use App\Enrollment\Application\Administrative\SubmittedEnrollmentIdQuery;
use App\Enrollment\Domain\EnrollmentStatus;
use App\Enrollment\Domain\ValueObject\EnrollmentId;
use App\Enrollment\Http\AdministrativeEnrollmentController;
use App\Enrollment\Http\EnrollmentAdministrationMiddleware;
use App\Enrollment\Infrastructure\Persistence\PdoSubmittedEnrollmentIdQuery;
use App\Family\Application\GetFamily;
use App\Family\Application\GetFamilyResources;
use App\IdentityAccess\Application\GetAuthenticatedUser;
use App\IdentityAccess\Application\Contract\CsrfTokenManager;
use App\IdentityAccess\Infrastructure\Session\SessionCsrfTokenManager;
use App\Person\Application\GetPerson;
use App\Representative\Application\GetRepresentative;
use App\Student\Application\GetStudent;
use Core\Http\Request;
use Core\Http\Response;
use Core\View\View;
use RuntimeException;
use Tests\Support\TestRunner;

function registerEnrollmentAdministrativeDeliveryTests(TestRunner $runner): void
{
    $runner->add('E012 Submitted query returns only positive Submitted IDs in deterministic order', function (): void {
        [$query, $pdo] = e012AdministrativeQueryFixture();

        assertSameValue([], $query->findSubmittedEnrollmentIds());
        $pdo->exec(
            "INSERT INTO enrollments (id, status_id, submitted_at) VALUES "
            . "(1, 10, NULL), (2, 11, '2026-08-24 10:00:00'), "
            . "(3, 11, '2026-08-24 11:00:00'), (4, 12, '2026-08-24 12:00:00'), "
            . "(5, 13, '2026-08-24 13:00:00'), (6, 14, '2026-08-24 14:00:00')"
        );
        $before = $pdo->query('SELECT id, status_id, submitted_at FROM enrollments ORDER BY id')?->fetchAll();

        assertSameValue([3, 2], $query->findSubmittedEnrollmentIds());
        assertSameValue($before, $pdo->query(
            'SELECT id, status_id, submitted_at FROM enrollments ORDER BY id'
        )?->fetchAll());
    });

    $runner->add('E012 Submitted query fails closed for incoherent physical Submitted state', function (): void {
        [$query, $pdo] = e012AdministrativeQueryFixture();
        $pdo->exec("INSERT INTO enrollments (id, status_id, submitted_at) VALUES (7, 11, NULL)");

        assertThrows(
            static fn () => $query->findSubmittedEnrollmentIds(),
            RuntimeException::class,
        );
    });

    $runner->add('E012 administrative list exposes minimal current selection data only', function (): void {
        $fixture = e012AdministrativeDeliveryFixture();
        $items = $fixture['list']->handle();

        assertSameValue(1, count($items));
        assertSameValue(900, $items[0]->enrollmentId);
        assertSameValue('Stored Complete Person Record', $items[0]->studentDisplayName);
        assertSameValue('Authorized Family', $items[0]->familyDisplayName);
        assertSameValue('Academic Period 2026-2027', $items[0]->academicPeriodDisplayName);
        assertSameValue('Grade 3', $items[0]->gradeDisplayName);
        assertSameValue('2026-08-20 12:00:00', $items[0]->submittedAt->format('Y-m-d H:i:s'));

        $empty = new ListSubmittedEnrollments(new E012SubmittedEnrollmentIds([]), $fixture['context']);
        assertSameValue([], $empty->handle());
    });

    $runner->add('E012 administrative review combines exact annual and current live SIS context', function (): void {
        $fixture = e012AdministrativeDeliveryFixture(periodActive: false);
        $review = $fixture['context']->handle(900);

        assertSameValue('SUBMITTED', $review->enrollment->status);
        assertSameValue('Representative Legal Name', $review->enrollment->billingInformation?->legalName);
        assertSameValue(44, $review->student->id);
        assertSameValue('Stored', $review->studentPerson->firstName);
        assertSameValue('Authorized Family', $review->familyDisplayName);
        assertSameValue('INACTIVE', $review->academicPeriod->status);
        assertSameValue('Grade 3', $review->grade?->name);
        assertSameValue('Section A', $review->section?->name);
        assertSameValue(1, count($review->currentRepresentatives));
        assertSameValue(true, $review->currentRepresentatives[0]->isPrimary);
        assertSameValue(33, $review->currentRepresentatives[0]->representative->id);
        assertSameValue('Current street', $review->currentFamilyResources->studentAddress?->mainStreet);
        assertSameValue('Emergency Contact', $review->currentFamilyResources->emergencyContacts[0]->names);
        assertSameValue('Authorized Pickup', $review->currentFamilyResources->authorizedPickups[0]->names);
    });

    $runner->add('E012 Enrollment administration middleware preserves the temporary exact admin gate', function (): void {
        $anonymousSession = new FakeSessionManager();
        $anonymous = new EnrollmentAdministrationMiddleware(new GetAuthenticatedUser(
            $anonymousSession,
            new InMemoryUserRepository(deliveryUser('admin')),
        ));
        assertSameValue(302, e012EnrollmentMiddlewareStatus($anonymous));

        foreach ([['operator', 403], ['admin', 200]] as [$identifier, $expected]) {
            $session = new FakeSessionManager();
            $session->userId = 1;
            $middleware = new EnrollmentAdministrationMiddleware(new GetAuthenticatedUser(
                $session,
                new InMemoryUserRepository(deliveryUser($identifier)),
            ));
            assertSameValue($expected, e012EnrollmentMiddlewareStatus($middleware));
        }
    });

    $runner->add('E012 administrative routes are exact protected and never mutate through GET', function (): void {
        $routes = str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__) . '/routes/web.php'));
        $expected = [
            "\$router->get(\n    '/enrollments',",
            "\$router->get(\n    '/enrollments/review',",
            "\$router->post(\n    '/enrollments/reopen',",
            "\$router->post(\n    '/enrollments/complete',",
            "\$router->post(\n    '/enrollments/cancel',",
        ];
        foreach ($expected as $route) {
            assertSameValue(1, substr_count($routes, $route), $route);
        }
        assertSameValue(
            22,
            substr_count($routes, "    \$enrollmentAdministrationMiddleware,\n);"),
        );
        foreach (['/admin/enrollments', '/enrollments/reopen?id=', '/enrollments/complete?id=', '/enrollments/cancel?id='] as $forbidden) {
            assertSameValue(false, str_contains($routes, $forbidden), $forbidden);
        }
    });

    $runner->add('E012 administrative list and review render escaped minimal and separated information', function (): void {
        $fixture = e012AdministrativeDeliveryFixture();
        deliveryRequest('GET', '/enrollments');
        $index = $fixture['controller']->index();
        deliveryAssertContains('Matrículas enviadas pendientes de revisión', $index);
        deliveryAssertContains('Stored Complete Person Record', $index);
        deliveryAssertContains('Authorized Family', $index);
        assertSameValue(false, str_contains($index, 'Representative Legal Name'));
        assertSameValue(false, str_contains($index, 'Current billing address'));
        assertSameValue(false, str_contains($index, 'Emergency Contact'));

        deliveryRequest('GET', '/enrollments/review?id=900', ['id' => '900']);
        $review = $fixture['controller']->review();
        foreach ([
            'Datos actuales del SIS', 'información viva y actual', 'Información anual de la matrícula',
            'Representantes familiares activos', 'Dirección actual del estudiante', 'Current street',
            'Contactos de emergencia', 'Personas autorizadas', 'Representative Legal Name',
            'Reabrir matrícula', 'Completar matrícula', 'Cancelar matrícula',
        ] as $expected) {
            deliveryAssertContains($expected, $review);
        }
        assertSameValue(3, substr_count($review, '<form method="post"'));
        assertSameValue(3, substr_count($review, 'name="_csrf_token"'));
        assertSameValue(3, substr_count($review, 'name="enrollment_id"'));
        foreach (['target_status', 'submitted_by', 'submitter', 'data submitted'] as $forbidden) {
            assertSameValue(false, stripos($review, $forbidden) !== false, $forbidden);
        }

        $escaped = View::render('enrollments.index', [
            'title' => 'Enrollment Administration',
            'items' => [new \App\Enrollment\Application\Administrative\Dto\SubmittedEnrollmentListItem(
                1,
                '<script>alert(1)</script>',
                'Family & Co',
                'Period',
                null,
                new \DateTimeImmutable('2026-08-24 12:00:00+00:00'),
            )],
        ]);
        deliveryAssertContains('&lt;script&gt;alert(1)&lt;/script&gt;', $escaped);
        assertSameValue(false, str_contains($escaped, '<script>alert(1)</script>'));
    });

    $runner->add('E012 administrative lifecycle actions use CSRF PRG flash and leave the Submitted queue', function (): void {
        foreach ([
            ['reopen', 'DRAFT', 'Enrollment reopened successfully.'],
            ['complete', 'COMPLETED', 'Enrollment completed successfully.'],
            ['cancel', 'CANCELLED', 'Enrollment cancelled successfully.'],
        ] as [$method, $expectedStatus, $message]) {
            $fixture = e012AdministrativeDeliveryFixture();
            deliveryRequest('POST', '/enrollments/' . $method, [
                '_csrf_token' => 'delivery-csrf',
                'enrollment_id' => '900',
            ]);
            assertSameValue('', $fixture['controller']->{$method}());
            assertSameValue(303, http_response_code());
            assertSameValue(
                $expectedStatus,
                $fixture['enrollments']->findById(new EnrollmentId(900))?->status()->value,
            );
            assertSameValue(
                $message,
                $fixture['session']->get('_flash_enrollment_administration_success'),
            );
            assertSameValue([], $fixture['list']->handle());
        }
    });

    $runner->add('E012 administrative lifecycle rejects missing invalid reused CSRF and forged fields', function (): void {
        foreach (['reopen', 'complete', 'cancel'] as $method) {
            foreach (['', 'invalid'] as $token) {
                $fixture = e012AdministrativeDeliveryFixture();
                deliveryRequest('POST', '/enrollments/' . $method, [
                    '_csrf_token' => $token,
                    'enrollment_id' => '900',
                ]);
                $html = $fixture['controller']->{$method}();
                assertSameValue(403, http_response_code());
                deliveryAssertContains('could not be verified', $html);
                assertSameValue('SUBMITTED', $fixture['enrollments']->findById(new EnrollmentId(900))?->status()->value);
            }
        }

        $session = new FakeSessionManager();
        $csrf = new SessionCsrfTokenManager($session);
        $fixture = e012AdministrativeDeliveryFixture($csrf, $session);
        $token = $csrf->token();
        deliveryRequest('POST', '/enrollments/reopen', [
            '_csrf_token' => $token,
            'enrollment_id' => '900',
        ]);
        assertSameValue('', $fixture['controller']->reopen());
        deliveryRequest('POST', '/enrollments/reopen', [
            '_csrf_token' => $token,
            'enrollment_id' => '900',
        ]);
        assertSameValue(403, e012CallAndStatus($fixture['controller'], 'reopen'));

        $forged = e012AdministrativeDeliveryFixture();
        deliveryRequest('POST', '/enrollments/complete', [
            '_csrf_token' => 'delivery-csrf',
            'enrollment_id' => '900',
            'target_status' => 'COMPLETED',
        ]);
        assertSameValue(422, e012CallAndStatus($forged['controller'], 'complete'));
        assertSameValue('SUBMITTED', $forged['enrollments']->findById(new EnrollmentId(900))?->status()->value);
    });

    $runner->add('E012 stale administrative POST preserves the first transition and reports safe conflict', function (): void {
        $fixture = e012AdministrativeDeliveryFixture();
        deliveryRequest('GET', '/enrollments/review?id=900', ['id' => '900']);
        $fixture['controller']->review();
        (new CompleteEnrollment(
            $fixture['enrollments'],
            $fixture['transactions'],
            new E010FakeClock(new \DateTimeImmutable('2026-08-24 15:00:00+00:00')),
        ))->handle(900);

        deliveryRequest('POST', '/enrollments/reopen', [
            '_csrf_token' => 'delivery-csrf',
            'enrollment_id' => '900',
        ]);
        assertSameValue('', $fixture['controller']->reopen());
        assertSameValue(303, http_response_code());
        assertSameValue('COMPLETED', $fixture['enrollments']->findById(new EnrollmentId(900))?->status()->value);
        assertSameValue(
            'Enrollment status changed. Review the current queue.',
            $fixture['session']->get('_flash_enrollment_administration_error'),
        );
    });

    $runner->add('E012 administrative Delivery maps unavailable and unexpected failures without disclosure', function (): void {
        $fixture = e012AdministrativeDeliveryFixture();
        deliveryRequest('GET', '/enrollments/review?id=invalid', ['id' => 'invalid']);
        $html = $fixture['controller']->review();
        assertSameValue(404, http_response_code());
        assertSameValue(false, str_contains($html, 'invalid'));

        deliveryRequest('POST', '/enrollments/complete', [
            '_csrf_token' => 'delivery-csrf',
            'enrollment_id' => '999',
        ]);
        $html = $fixture['controller']->complete();
        assertSameValue(404, http_response_code());
        assertSameValue(false, str_contains($html, '999'));

        $fixture['enrollments']->saveFailure = new RuntimeException('SQLSTATE private medical failure');
        deliveryRequest('POST', '/enrollments/complete', [
            '_csrf_token' => 'delivery-csrf',
            'enrollment_id' => '900',
        ]);
        $html = $fixture['controller']->complete();
        assertSameValue(500, http_response_code());
        assertSameValue(false, str_contains($html, 'SQLSTATE'));
        assertSameValue(false, str_contains($html, 'medical failure'));
    });

    $runner->add('E012 administrative Delivery wiring stays thin White Label and snapshot free', function (): void {
        $controller = (string) file_get_contents(
            dirname(__DIR__) . '/app/Enrollment/Http/AdministrativeEnrollmentController.php'
        );
        foreach (['new PDO', 'SELECT ', 'INSERT ', 'UPDATE ', 'DELETE ', '->reopen()', '->complete()', '->cancel()'] as $forbidden) {
            assertSameValue(false, str_contains($controller, $forbidden), $forbidden);
        }
        $source = '';
        foreach ([
            'app/Enrollment/Application/Administrative',
            'app/Enrollment/Http',
            'app/Enrollment/Infrastructure/Persistence/PdoSubmittedEnrollmentIdQuery.php',
            'resources/views/enrollments',
        ] as $path) {
            $full = dirname(__DIR__) . '/' . $path;
            if (is_file($full)) {
                $source .= (string) file_get_contents($full);
                continue;
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($full)) as $file) {
                if ($file->isFile()) {
                    $source .= (string) file_get_contents($file->getPathname());
                }
            }
        }
        foreach (['SubmissionSnapshot', 'serialized submission', 'submitted_by', 'Unidad Educativa'] as $forbidden) {
            assertSameValue(false, stripos($source, $forbidden) !== false, $forbidden);
        }
        assertSameValue(false, file_exists(dirname(__DIR__) . '/database/migrations/011_create_enrollment_delivery.php'));

        $dashboard = (string) file_get_contents(dirname(__DIR__) . '/resources/views/dashboard/index.php');
        deliveryAssertContains('Matrículas', $dashboard);
        deliveryAssertContains('canAccessPersons', $dashboard);
    });
}

/** @return array{PdoSubmittedEnrollmentIdQuery, \PDO} */
function e012AdministrativeQueryFixture(): array
{
    $manager = familySqliteManager();
    $pdo = $manager->connection();
    $pdo->exec(
        'CREATE TABLE status_types (id INTEGER PRIMARY KEY, code TEXT NOT NULL);'
        . 'CREATE TABLE statuses (id INTEGER PRIMARY KEY, status_type_id INTEGER NOT NULL, code TEXT NOT NULL);'
        . 'CREATE TABLE enrollments (id INTEGER PRIMARY KEY, status_id INTEGER NOT NULL, submitted_at TEXT NULL);'
        . "INSERT INTO status_types VALUES (1, 'ENROLLMENT_STATUS'), (2, 'GENERAL_STATUS');"
        . "INSERT INTO statuses VALUES (10, 1, 'DRAFT'), (11, 1, 'SUBMITTED'), "
        . "(12, 1, 'COMPLETED'), (13, 1, 'CANCELLED'), (14, 2, 'SUBMITTED');"
    );

    return [new PdoSubmittedEnrollmentIdQuery($manager), $pdo];
}

/** @return array<string, mixed> */
function e012AdministrativeDeliveryFixture(
    ?CsrfTokenManager $csrf = null,
    ?FakeSessionManager $session = null,
    bool $periodActive = true,
): array {
    $base = e012SubmissionFixture(status: EnrollmentStatus::Submitted);
    $periodRepository = $periodActive
        ? $base['periods']
        : new E010AcademicPeriodRepository(representativeAcknowledgementPeriod(5, AcademicPeriodStatus::Inactive));
    $context = new GetAdministrativeEnrollmentReviewContext(
        new GetAdministrativeEnrollmentReview($base['enrollments']),
        new GetStudent($base['students']),
        new GetPerson($base['persons']),
        new GetFamily($base['families']),
        new GetFamilyResources($base['families']),
        new GetRepresentative($base['representatives']),
        $periodRepository,
        e010AcademicReferences(),
    );
    $ids = new E012SubmittedEnrollmentIds([900]);
    $list = new ListSubmittedEnrollments($ids, $context);
    $session ??= new FakeSessionManager();
    $csrf ??= new FakeDeliveryCsrf();
    $clock = new E010FakeClock(new \DateTimeImmutable('2026-08-24 14:00:00+00:00'));

    $controller = new AdministrativeEnrollmentController(
        $list,
        $context,
        new ReopenEnrollment($base['enrollments'], $base['transactions']),
        new CompleteEnrollment($base['enrollments'], $base['transactions'], $clock),
        new CancelEnrollment($base['enrollments'], $base['transactions'], $clock),
        $csrf,
        $session,
    );

    return array_merge($base, [
        'context' => $context,
        'list' => $list,
        'controller' => $controller,
        'session' => $session,
    ]);
}

function e012EnrollmentMiddlewareStatus(EnrollmentAdministrationMiddleware $middleware): int
{
    http_response_code(200);
    $response = $middleware->handle(
        new Request(),
        static fn (Request $request): Response => (new Response())->status(200),
    );
    deliverySendResponse($response);

    return http_response_code();
}

function e012CallAndStatus(AdministrativeEnrollmentController $controller, string $method): int
{
    $controller->{$method}();

    return http_response_code();
}

final readonly class E012SubmittedEnrollmentIds implements SubmittedEnrollmentIdQuery
{
    /** @param list<int> $ids */
    public function __construct(private array $ids)
    {
    }

    public function findSubmittedEnrollmentIds(): array
    {
        return $this->ids;
    }
}
