<?php

declare(strict_types=1);

namespace Tests;

use App\IdentityAccess\Application\AuthenticateUser;
use App\IdentityAccess\Application\AuthenticationPolicy;
use App\IdentityAccess\Application\GetAuthenticatedRepresentative;
use App\IdentityAccess\Application\GetAuthenticatedUser;
use App\IdentityAccess\Application\LogoutUser;
use App\IdentityAccess\Domain\UserStatus;
use App\IdentityAccess\Http\AuthenticationController;
use App\IdentityAccess\Http\ReportingAccessMiddleware;
use App\IdentityAccess\Infrastructure\Security\NativePasswordHasher;
use App\Representative\Domain\Representative;
use App\Representative\Domain\RepresentativeStatus;
use App\Representative\Domain\ValueObject\PersonId;
use App\Representative\Domain\ValueObject\RepresentativeId;
use App\Shared\Http\SharedShellDataFactory;
use App\Shared\Http\WhiteLabelBranding;
use Core\Http\Request;
use Core\Http\Response;
use Core\View\View;
use Tests\Support\TestRunner;

function registerInstitutionalReportingAccessPhase3Tests(TestRunner $runner): void
{
    $runner->add('Institutional report middleware authorizes only the persisted capability', function (): void {
        [$reporter, $reporterCalls] = phase3RunReportMiddleware('reporting-user', true);
        assertSameValue(200, $reporter['status']);
        assertSameValue('report-handler', $reporter['content']);
        assertSameValue(1, $reporterCalls());

        [$ordinary, $ordinaryCalls] = phase3RunReportMiddleware('ordinary-user', false);
        assertSameValue(403, $ordinary['status']);
        assertSameValue(0, $ordinaryCalls());
        deliveryAssertContains('Acceso no permitido', $ordinary['content']);
        deliveryAssertContains('No tienes permiso para acceder a esta página.', $ordinary['content']);
        assertSameValue(false, str_contains($ordinary['content'], 'REPORTS_VIEW_ALL'));

        [$adminWithoutCapability, $adminCalls] = phase3RunReportMiddleware('admin', false);
        assertSameValue(403, $adminWithoutCapability['status']);
        assertSameValue(0, $adminCalls());

        [$nonAdminWithCapability, $nonAdminCalls] = phase3RunReportMiddleware('not-admin', true);
        assertSameValue(200, $nonAdminWithCapability['status']);
        assertSameValue(1, $nonAdminCalls());

        $source = (string) file_get_contents(
            dirname(__DIR__) . '/app/IdentityAccess/Http/ReportingAccessMiddleware.php'
        );
        deliveryAssertContains("header('Cache-Control: no-store')", $source);
        assertSameValue(false, str_contains($source, 'loginIdentifier'));
        assertSameValue(false, str_contains($source, 'PDO'));
        assertSameValue(false, str_contains($source, 'user_capabilities'));
    });

    $runner->add('Institutional report middleware fails closed for missing and disabled effective Users', function (): void {
        $missingRepository = new InMemoryUserRepository(null);
        $missingSession = new FakeSessionManager();
        $missingSession->userId = 1;
        $missing = new ReportingAccessMiddleware(
            new GetAuthenticatedUser($missingSession, $missingRepository),
            testReportAccessPolicy($missingRepository, true),
        );
        $missingCalls = 0;
        $missingResult = phase3InvokeMiddleware($missing, '/reports/enrollments', $missingCalls);
        assertSameValue(302, $missingResult['status']);
        assertSameValue(0, $missingCalls);

        $disabledRepository = new InMemoryUserRepository(reportingAccessUser(1, UserStatus::Disabled));
        $disabledSession = new FakeSessionManager();
        $disabledSession->userId = 1;
        $disabled = new ReportingAccessMiddleware(
            new GetAuthenticatedUser($disabledSession, $disabledRepository),
            testReportAccessPolicy($disabledRepository, true),
        );
        $disabledCalls = 0;
        $disabledResult = phase3InvokeMiddleware($disabled, '/reports/enrollments', $disabledCalls);
        assertSameValue(302, $disabledResult['status']);
        assertSameValue(0, $disabledCalls);
    });

    $runner->add('Institutional report HTML and CSV routes share one exact authorization chain', function (): void {
        $routes = str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__) . '/routes/web.php'));
        $reportPaths = phase3ReportPaths();

        foreach ($reportPaths as $path) {
            $needle = "\$router->get(\n    '" . $path . "',";
            assertSameValue(1, substr_count($routes, $needle), $path);
            $start = strpos($routes, $needle);
            assertSameValue(true, is_int($start), $path);
            $block = substr($routes, (int) $start, 260);
            deliveryAssertContains('$reportingAccessMiddleware', $block);
            assertSameValue(false, str_contains($block, '$enrollmentAdministrationMiddleware'), $path);
            assertSameValue(false, str_contains($routes, "\$router->post(\n    '" . $path . "',"), $path);
        }

        deliveryAssertContains(
            "\$reportingAccessMiddleware = [\n    AuthenticationMiddleware::class,\n    ReportingAccessMiddleware::class,\n];",
            $routes,
        );
        assertSameValue(13, count($reportPaths));
        assertSameValue(50, substr_count($routes, '$router->get('));
        assertSameValue(75, substr_count($routes, '$router->post('));
        assertSameValue(false, str_contains($routes, '/admin/reports'));
    });

    $runner->add('Enrollment administration keeps its exact fifteen non-report route registrations', function (): void {
        $routes = str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__) . '/routes/web.php'));
        $administrativeRoutes = [
            ['get', '/admin/bulk-import'],
            ['get', '/admin/bulk-import/template'],
            ['get', '/admin/bulk-import/result'],
            ['get', '/admin/bulk-import/errors.csv'],
            ['post', '/admin/bulk-import/preview'],
            ['post', '/admin/bulk-import/apply'],
            ['get', '/admin/academic-initialization'],
            ['get', '/admin/academic-initialization/result'],
            ['post', '/admin/academic-initialization/preview'],
            ['post', '/admin/academic-initialization/apply'],
            ['get', '/enrollments'],
            ['get', '/enrollments/review'],
            ['post', '/enrollments/reopen'],
            ['post', '/enrollments/complete'],
            ['post', '/enrollments/cancel'],
        ];

        foreach ($administrativeRoutes as [$method, $path]) {
            $needle = "\$router->{$method}(\n    '" . $path . "',";
            assertSameValue(1, substr_count($routes, $needle), $method . ' ' . $path);
            $start = strpos($routes, $needle);
            assertSameValue(true, is_int($start), $path);
            deliveryAssertContains(
                '$enrollmentAdministrationMiddleware',
                substr($routes, (int) $start, 260),
            );
        }
        assertSameValue(15, count($administrativeRoutes));
    });

    $runner->add('Root dispatch preserves Representative and Admin precedence before reporting capability', function (): void {
        $representative = phase3AuthenticationFixture(true, 'representative-22', true);
        $representative['session']->userId = 1;
        http_response_code(200);
        assertSameValue('', $representative['authentication']->dashboard());
        assertSameValue(302, http_response_code());

        $admin = phase3AuthenticationFixture(false, 'admin', true);
        $admin['session']->userId = 1;
        http_response_code(200);
        $adminHtml = $admin['authentication']->dashboard();
        assertSameValue(200, http_response_code());
        deliveryAssertContains('Panel administrativo', $adminHtml);

        $reporter = phase3AuthenticationFixture(false, 'reporting-user', true);
        $reporter['session']->userId = 1;
        http_response_code(200);
        assertSameValue('', $reporter['authentication']->dashboard());
        assertSameValue(302, http_response_code());

        $ordinary = phase3AuthenticationFixture(false, 'ordinary-user', false);
        $ordinary['session']->userId = 1;
        http_response_code(200);
        $ordinaryHtml = $ordinary['authentication']->dashboard();
        assertSameValue(200, http_response_code());
        deliveryAssertContains('La sesión se inició correctamente.', $ordinaryHtml);
        assertSameValue(false, str_contains($ordinaryHtml, 'href="/persons"'));

        $source = (string) file_get_contents(
            dirname(__DIR__) . '/app/IdentityAccess/Http/AuthenticationController.php'
        );
        assertSameValue(
            true,
            strpos($source, 'getAuthenticatedRepresentative->handle()')
                < strpos($source, "loginIdentifier !== 'admin'"),
        );
        assertSameValue(false, str_contains($source, 'UserCapabilityLookup'));
    });

    $runner->add('Reporting-only shell exposes only Reports and the existing logout boundary', function (): void {
        $reporter = phase3AuthenticationFixture(false, 'reporting-user', true);
        $reporter['session']->userId = 1;
        deliveryRequest('GET', '/reports/enrollments/medical');
        $shell = (new SharedShellDataFactory(
            $reporter['getUser'],
            $reporter['getRepresentative'],
            new FakeDeliveryCsrf(),
            WhiteLabelBranding::fromConfig([]),
            testReportAccessPolicy($reporter['users'], true),
        ))->forRequest(new Request());

        assertSameValue('reporting', $shell->context);
        assertSameValue(['Reportes'], array_column($shell->navigation, 'label'));
        assertSameValue(['/reports/enrollments'], array_column($shell->navigation, 'href'));
        assertSameValue(true, $shell->navigation[0]['active']);
        assertSameValue('delivery-csrf', $shell->logoutCsrfToken);

        View::setSharedDataResolver(static fn (): array => ['shell' => $shell]);
        try {
            $html = View::render('dashboard.index', [
                'title' => 'Inicio',
                'csrfToken' => 'delivery-csrf',
                'canAccessPersons' => false,
            ]);
        } finally {
            View::setSharedDataResolver(null);
        }

        deliveryAssertContains('class="navbar-brand d-flex align-items-center gap-2 text-wrap" href="/reports/enrollments"', $html);
        assertSameValue(1, substr_count($html, 'action="/logout"'));
        foreach ([
            'href="/persons"',
            'href="/families"',
            'href="/admin/bulk-import"',
            'href="/admin/academic-initialization"',
            'href="/institutional-acknowledgements"',
            'href="/enrollments"',
            'href="/representative"',
        ] as $excluded) {
            assertSameValue(false, str_contains($html, $excluded), $excluded);
        }
    });

    $runner->add('Report authorization remains outside controllers Views sessions and datasets', function (): void {
        foreach (['/reports/enrollments/medical', '/reports/enrollments/medical/csv'] as $path) {
            [$result, $calls] = phase3RunReportMiddleware('ordinary-user', false, $path);
            assertSameValue(403, $result['status']);
            assertSameValue(0, $calls(), $path);
        }

        $controller = (string) file_get_contents(
            dirname(__DIR__) . '/app/Enrollment/Http/EnrollmentReportingController.php'
        );
        $views = glob(dirname(__DIR__) . '/resources/views/reports/enrollments/*.php') ?: [];
        assertSameValue(false, str_contains($controller, 'REPORTS_VIEW_ALL'));
        assertSameValue(false, str_contains($controller, 'CanAccessAllReports'));
        foreach ($views as $view) {
            $source = (string) file_get_contents($view);
            assertSameValue(false, str_contains($source, 'REPORTS_VIEW_ALL'), $view);
            assertSameValue(false, str_contains($source, 'CanAccessAllReports'), $view);
        }

        $changedRuntime = [
            'app/IdentityAccess/Http/ReportingAccessMiddleware.php',
            'app/IdentityAccess/Http/AuthenticationController.php',
            'app/Shared/Http/SharedShellDataFactory.php',
            'resources/views/layouts/app.php',
            'routes/web.php',
            'bootstrap/app.php',
        ];
        foreach ($changedRuntime as $path) {
            $source = (string) file_get_contents(dirname(__DIR__) . '/' . $path);
            assertSameValue(false, str_contains($source, "['capabilities']"), $path);
            assertSameValue(false, str_contains($source, '$_SESSION'), $path);
        }
    });
}

/** @return array<string, mixed> */
function phase3AuthenticationFixture(
    bool $withRepresentative,
    string $loginIdentifier,
    bool $canAccessAllReports,
): array {
    $users = new InMemoryUserRepository(deliveryUser($loginIdentifier));
    $session = new FakeSessionManager();
    $getUser = new GetAuthenticatedUser($session, $users);
    $representative = $withRepresentative
        ? new Representative(
            new RepresentativeId(33),
            new PersonId(1),
            null,
            RepresentativeStatus::Active,
        )
        : null;
    $getRepresentative = new GetAuthenticatedRepresentative(
        $getUser,
        new RepresentativeAccessResolutionTest($representative),
    );
    $events = new FakeSecurityEvents();
    $authenticate = new AuthenticateUser(
        $users,
        new NativePasswordHasher(),
        $session,
        new ImmediateTransactionManager(),
        new FrozenClock(deliveryToday()),
        $events,
        new AuthenticationPolicy(5, 900),
    );

    return [
        'authentication' => new AuthenticationController(
            $authenticate,
            new LogoutUser($session, $events),
            $getUser,
            $getRepresentative,
            new FakeDeliveryCsrf(),
            $session,
            testReportAccessPolicy($users, $canAccessAllReports),
        ),
        'session' => $session,
        'users' => $users,
        'getUser' => $getUser,
        'getRepresentative' => $getRepresentative,
    ];
}

/** @return array{0: array{status: int, content: string}, 1: callable(): int} */
function phase3RunReportMiddleware(
    string $loginIdentifier,
    bool $allowed,
    string $path = '/reports/enrollments',
): array {
    $repository = new InMemoryUserRepository(deliveryUser($loginIdentifier));
    $session = new FakeSessionManager();
    $session->userId = 1;
    $middleware = new ReportingAccessMiddleware(
        new GetAuthenticatedUser($session, $repository),
        testReportAccessPolicy($repository, $allowed),
    );
    $calls = 0;
    $result = phase3InvokeMiddleware($middleware, $path, $calls);

    return [$result, static function () use (&$calls): int {
        return $calls;
    }];
}

/** @return array{status: int, content: string} */
function phase3InvokeMiddleware(
    ReportingAccessMiddleware $middleware,
    string $path,
    int &$calls,
): array {
    deliveryRequest('GET', $path);
    http_response_code(200);
    $response = $middleware->handle(
        new Request(),
        static function (Request $request) use (&$calls): Response {
            $calls++;

            return (new Response())->content('report-handler');
        },
    );

    ob_start();
    $response->send();
    $content = (string) ob_get_clean();

    return [
        'status' => http_response_code(),
        'content' => $content,
    ];
}

/** @return list<string> */
function phase3ReportPaths(): array
{
    return [
        '/reports/enrollments',
        '/reports/enrollments/summary',
        '/reports/enrollments/summary/csv',
        '/reports/enrollments/students',
        '/reports/enrollments/students/csv',
        '/reports/enrollments/directory',
        '/reports/enrollments/directory/csv',
        '/reports/enrollments/billing',
        '/reports/enrollments/billing/csv',
        '/reports/enrollments/medical',
        '/reports/enrollments/medical/csv',
        '/reports/enrollments/inspection',
        '/reports/enrollments/inspection/csv',
    ];
}
