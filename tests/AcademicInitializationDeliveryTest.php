<?php

declare(strict_types=1);

namespace Tests;

use App\Enrollment\Application\AcademicInitialization\PreviewAcademicInitialization;
use App\Enrollment\Http\AcademicInitializationApplyController;
use App\Enrollment\Http\AcademicInitializationController;
use App\Enrollment\Http\AcademicInitializationDeliverySession;
use App\Enrollment\Http\EnrollmentAdministrationMiddleware;
use App\Enrollment\Infrastructure\Filesystem\LocalAcademicInitializationTemporaryFileStore;
use App\IdentityAccess\Application\Contract\Clock;
use App\IdentityAccess\Application\GetAuthenticatedUser;
use Core\Http\Request;
use Core\View\View;
use DateTimeImmutable;
use RuntimeException;
use Tests\Support\TestRunner;

function registerAcademicInitializationDeliveryTests(TestRunner $runner): void
{
    $runner->add('Academic initialization preview grant is actor-bound state-bound single-use and expiring', function (): void {
        $session = new FakeSessionManager();
        $clock = new AcademicInitializationDeliveryClock(new DateTimeImmutable('2026-09-05 10:00:00+00:00'));
        $workflow = new AcademicInitializationDeliverySession($session, $clock);
        $fileDigest = hash('sha256', 'manifest');
        $stateDigest = hash('sha256', 'state');

        $token = $workflow->issuePreview(7, $fileDigest, $stateDigest, '2026-2027');
        assertSameValue(null, $workflow->consumePreview($token, 8));
        assertSameValue(null, $workflow->consumePreview($token, 7));

        $token = $workflow->issuePreview(7, $fileDigest, $stateDigest, '2026-2027');
        $grant = $workflow->consumePreview($token, 7);
        assertSameValue([$fileDigest, $stateDigest, '2026-2027'], [
            $grant?->fileDigest,
            $grant?->stateDigest,
            $grant?->academicPeriodCode,
        ]);
        assertSameValue(null, $workflow->consumePreview($token, 7));

        $token = $workflow->issuePreview(7, $fileDigest, $stateDigest, '2026-2027');
        $clock->instant = new DateTimeImmutable('2026-09-05 10:15:00+00:00');
        assertSameValue(null, $workflow->consumePreview($token, 7));
    });

    $runner->add('Academic initialization local CSV store is outside public bounded random and cleaned', function (): void {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'antares-academic-store-' . bin2hex(random_bytes(8));
        $source = $root . '-source.csv';
        file_put_contents($source, "institutional_code,grade_code,section_code\nSTU-001,EGB_1,A\n");
        $store = new LocalAcademicInitializationTemporaryFileStore(
            $root,
            dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public',
            static fn (string $from, string $to): bool => copy($from, $to),
        );
        try {
            $path = $store->store([
                'error' => UPLOAD_ERR_OK,
                'tmp_name' => $source,
                'size' => filesize($source),
                'name' => '..\\manifest.csv',
            ]);
            assertSameValue(true, is_file($path));
            assertSameValue(1, preg_match('/\A[a-f0-9]{64}\.csv\z/D', basename($path)));
            assertSameValue(false, str_contains($path, 'manifest'));
            $store->delete($path);
            assertSameValue(false, file_exists($path));
        } finally {
            @unlink($source);
            if (is_dir($root)) {
                @rmdir($root);
            }
        }
    });

    $runner->add('Academic initialization routes preserve exact admin gate POST mutation and no schema surface', function (): void {
        $routes = str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__) . '/routes/web.php'));
        foreach ([
            "\$router->get(\n    '/admin/academic-initialization',",
            "\$router->get(\n    '/admin/academic-initialization/result',",
            "\$router->post(\n    '/admin/academic-initialization/preview',",
            "\$router->post(\n    '/admin/academic-initialization/apply',",
        ] as $route) {
            assertSameValue(1, substr_count($routes, $route), $route);
        }
        assertSameValue(false, str_contains($routes, "\$router->get(\n    '/admin/academic-initialization/apply'"));
        assertSameValue(26, substr_count($routes, "    \$enrollmentAdministrationMiddleware,\n);"));

        foreach ([[null, null, 302], ['representative-22', 1, 403], ['admin', 1, 200]] as [$identifier, $userId, $status]) {
            $session = new FakeSessionManager();
            $session->userId = $userId;
            $middleware = new EnrollmentAdministrationMiddleware(new GetAuthenticatedUser(
                $session,
                new InMemoryUserRepository($identifier === null ? null : deliveryUser($identifier)),
            ));
            assertSameValue($status, e012EnrollmentMiddlewareStatus($middleware));
        }

        $source = implode("\n", [
            (string) file_get_contents(dirname(__DIR__) . '/app/Enrollment/Http/AcademicInitializationController.php'),
            (string) file_get_contents(dirname(__DIR__) . '/app/Enrollment/Http/AcademicInitializationApplyController.php'),
        ]);
        foreach (['PDO', 'SELECT ', 'INSERT ', 'UPDATE ', 'DELETE '] as $forbidden) {
            assertSameValue(false, str_contains($source, $forbidden), $forbidden);
        }
        assertSameValue(false, file_exists(dirname(__DIR__) . '/database/migrations/012_create_academic_initialization.php'));
    });

    $runner->add('Academic initialization Delivery enforces CSRF digest PRG replay and temporary cleanup', function (): void {
        $environment = academicInitializationEnvironment();
        $session = new FakeSessionManager();
        $session->userId = 7;
        $clock = new AcademicInitializationDeliveryClock(new DateTimeImmutable('2026-09-05 10:00:00+00:00'));
        $workflow = new AcademicInitializationDeliverySession($session, $clock);
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'antares-academic-delivery-' . bin2hex(random_bytes(8));
        $store = new LocalAcademicInitializationTemporaryFileStore(
            $root,
            dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public',
            static fn (string $from, string $to): bool => copy($from, $to),
        );
        $controller = new AcademicInitializationController(
            new PreviewAcademicInitialization($environment->preflight),
            $store,
            $workflow,
            new FakeDeliveryCsrf(),
            $session,
        );
        $apply = new AcademicInitializationApplyController(
            $environment->apply,
            $store,
            $workflow,
            new FakeDeliveryCsrf(),
            $session,
        );
        $source = academicInitializationManifest([['STU-001', 'EGB_1', 'A']]);

        try {
            deliveryRequest('POST', '/admin/academic-initialization/preview', [
                '_csrf_token' => 'invalid',
                'academic_period_code' => '2026-2027',
            ]);
            $_FILES = ['manifest' => academicInitializationUpload($source)];
            $controller->preview();
            assertSameValue(403, http_response_code());
            assertSameValue(0, $environment->enrollments->count());

            deliveryRequest('POST', '/admin/academic-initialization/preview', [
                '_csrf_token' => 'delivery-csrf',
                'academic_period_code' => '2026-2027',
            ]);
            $_FILES = ['manifest' => academicInitializationUpload($source)];
            $html = $controller->preview();
            assertSameValue(200, http_response_code());
            deliveryAssertContains('Crear DRAFT', $html);
            assertSameValue([], glob($root . DIRECTORY_SEPARATOR . '*') ?: []);
            $state = $session->get('_academic_initialization_preview');
            $token = is_array($state) ? ($state['token'] ?? null) : null;
            assertSameValue(true, is_string($token) && strlen($token) === 64);

            deliveryRequest('POST', '/admin/academic-initialization/apply', [
                '_csrf_token' => 'delivery-csrf',
                'preview_token' => $token,
            ]);
            $_FILES = ['manifest' => academicInitializationUpload($source)];
            assertSameValue('', $apply->apply());
            assertSameValue(303, http_response_code());
            assertSameValue(1, $environment->enrollments->count());
            assertSameValue([], glob($root . DIRECTORY_SEPARATOR . '*') ?: []);

            deliveryRequest('GET', '/admin/academic-initialization/result');
            $_FILES = [];
            $result = $controller->result();
            deliveryAssertContains('El lote se aplicó completamente', $result);
            deliveryAssertContains('DRAFT creados', $result);

            deliveryRequest('POST', '/admin/academic-initialization/apply', [
                '_csrf_token' => 'delivery-csrf',
                'preview_token' => $token,
            ]);
            $_FILES = ['manifest' => academicInitializationUpload($source)];
            $apply->apply();
            assertSameValue(303, http_response_code());
            assertSameValue(1, $environment->enrollments->count());
        } finally {
            $_FILES = [];
            @unlink($source);
            foreach (glob($root . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                @unlink($file);
            }
            if (is_dir($root)) {
                @rmdir($root);
            }
        }
    });

    $runner->add('Academic initialization views escape all manifest-derived output', function (): void {
        $html = View::render('academic-initialization.index', [
            'title' => 'Inicialización académica',
            'csrfToken' => 'csrf<&',
            'academicPeriodCode' => '2026-2027"><script>',
            'previewToken' => null,
            'errorMessage' => '<img src=x onerror=alert(1)>',
            'preview' => [
                'academic_period_code' => '<period>',
                'counts' => ['rows' => 1, 'create_draft' => 0, 'set_placement' => 0, 'already_correct' => 0, 'conflicts' => 1, 'issues' => 1],
                'items' => [[
                    'row' => 2,
                    'institutional_code' => '<script>alert(1)</script>',
                    'grade_code' => '<grade>',
                    'section_code' => '<section>',
                    'classification' => 'CONFLICT',
                    'message' => '<unsafe>',
                ]],
                'issues' => [[
                    'category' => '<category>', 'row' => 2, 'field' => '<field>', 'message' => '<message>',
                ]],
            ],
        ]);
        foreach (['&lt;script&gt;alert(1)&lt;/script&gt;', '&lt;unsafe&gt;', '&lt;message&gt;', 'csrf&lt;&amp;'] as $escaped) {
            deliveryAssertContains($escaped, $html);
        }
        assertSameValue(false, str_contains($html, '<script>alert(1)</script>'));
        assertSameValue(false, str_contains($html, '<img src=x onerror='));
    });
}

final class AcademicInitializationDeliveryClock implements Clock
{
    public function __construct(public DateTimeImmutable $instant)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->instant;
    }
}

/** @return array{error: int, tmp_name: string, size: int, name: string} */
function academicInitializationUpload(string $source): array
{
    $size = filesize($source);
    if (!is_int($size)) {
        throw new RuntimeException('Unable to size academic initialization fixture.');
    }

    return [
        'error' => UPLOAD_ERR_OK,
        'tmp_name' => $source,
        'size' => $size,
        'name' => 'academic-initialization.csv',
    ];
}
