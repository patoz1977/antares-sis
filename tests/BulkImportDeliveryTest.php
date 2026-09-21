<?php

declare(strict_types=1);

namespace Tests;

use App\BulkImport\Application\Delivery\BulkImportDeliverySession;
use App\BulkImport\Application\Exception\BulkImportWorkbookRejected;
use App\BulkImport\Application\PreviewBulkImport;
use App\BulkImport\Application\Contract\BulkImportWorkbookReader;
use App\BulkImport\Http\BulkImportApplyController;
use App\BulkImport\Http\BulkImportController;
use App\BulkImport\Http\BulkImportErrorCsvWriter;
use App\BulkImport\Http\BulkImportTemplateController;
use App\BulkImport\Http\BulkImportTemplateFile;
use App\BulkImport\Infrastructure\Filesystem\LocalBulkImportTemporaryFileStore;
use App\Enrollment\Http\EnrollmentAdministrationMiddleware;
use App\Family\Domain\FamilyStatus;
use App\Family\Http\FamilyFormOption;
use App\Family\Http\FamilyFormOptions;
use App\Family\Http\FamilyFormOptionsProvider;
use App\IdentityAccess\Application\GetAuthenticatedUser;
use App\Person\Http\PersonFormOption;
use App\Person\Http\PersonFormOptions;
use App\Person\Http\PersonFormOptionsProvider;
use App\Shared\Http\SharedShellDataFactory;
use App\Shared\Http\WhiteLabelBranding;
use Core\Http\Request;
use Core\View\View;
use DateTimeImmutable;
use Tests\Support\TestRunner;

function registerBulkImportDeliveryTests(TestRunner $runner): void
{
    $runner->add('E015 Phase 7 token is opaque actor-bound single-use and expires exactly at fifteen minutes', function (): void {
        $session = new FakeSessionManager();
        $clock = new BulkImportDeliveryClock(new DateTimeImmutable('2026-09-02 12:00:00+00:00'));
        $workflow = new BulkImportDeliverySession($session, $clock);
        $digest = hash('sha256', 'same bytes');
        $counts = ['families' => 1, 'new' => 3, 'already_exists' => 0, 'conflicts' => 0, 'issues' => 0];

        $token = $workflow->issuePreview(11, $digest, $counts);
        assertSameValue(1, preg_match('/\A[a-f0-9]{64}\z/', $token));
        assertSameValue(null, $workflow->consumePreview(str_repeat('a', 64), 11));
        assertSameValue($digest, $workflow->consumePreview($token, 11)?->digest);
        assertSameValue(null, $workflow->consumePreview($token, 11));

        $wrongActor = $workflow->issuePreview(11, $digest, $counts);
        assertSameValue(null, $workflow->consumePreview($wrongActor, 12));
        assertSameValue(null, $workflow->consumePreview($wrongActor, 11));

        $expired = $workflow->issuePreview(11, $digest, $counts);
        $clock->instant = new DateTimeImmutable('2026-09-02 12:15:00+00:00');
        assertSameValue(null, $workflow->consumePreview($expired, 11));

        $clock->instant = new DateTimeImmutable('2026-09-02 12:16:00+00:00');
        $old = $workflow->issuePreview(11, $digest, $counts);
        $workflow->invalidateForNewPreview();
        $new = $workflow->issuePreview(11, $digest, $counts);
        assertSameValue(false, hash_equals($old, $new));
        assertSameValue(null, $workflow->consumePreview($old, 11));
        assertSameValue($digest, $workflow->consumePreview($new, 11)?->digest);

        $logoutToken = $workflow->issuePreview(11, $digest, $counts);
        $session->destroy();
        assertSameValue(null, $workflow->consumePreview($logoutToken, 11));
    });

    $runner->add('E015 Phase 7 preview token session contains only approved server-side state', function (): void {
        $session = new FakeSessionManager();
        $clock = new BulkImportDeliveryClock(new DateTimeImmutable('2026-09-02 12:00:00+00:00'));
        $workflow = new BulkImportDeliverySession($session, $clock);
        $digest = hash('sha256', 'sensitive-workbook');
        $workflow->issuePreview(17, $digest, [
            'families' => 1, 'new' => 2, 'already_exists' => 0, 'conflicts' => 0, 'issues' => 0,
        ]);
        $state = $session->get('_e015_bulk_import_preview');
        $keys = array_keys($state);
        sort($keys, SORT_STRING);

        assertSameValue(
            ['actor_id', 'counts', 'digest', 'expires_at', 'issued_at', 'token'],
            $keys,
        );
        assertSameValue($digest, $state['digest']);
        $serialized = json_encode($state, JSON_THROW_ON_ERROR);
        foreach ([
            'ClaveSentinela9271', 'ana@example.test', '1985-02-03',
            '1712345678', 'F00000001', 'tmp', '.xlsx',
        ] as $forbidden) {
            assertSameValue(false, str_contains($serialized, $forbidden), $forbidden);
        }
    });

    $runner->add('E015 Phase 7 local temporary store enforces upload bounds random names and cleanup', function (): void {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'antares-e015-local-store-' . bin2hex(random_bytes(8));
        $source = $root . '-source.xlsx';
        file_put_contents($source, 'PK fixture');
        $store = new LocalBulkImportTemporaryFileStore(
            $root,
            dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public',
            static fn (string $from, string $to): bool => copy($from, $to),
        );

        try {
            $path = $store->store([
                'error' => UPLOAD_ERR_OK,
                'tmp_name' => $source,
                'size' => filesize($source),
                'name' => '..\\attacker.xlsx',
            ]);
            assertSameValue(true, is_file($path));
            assertSameValue(1, preg_match('/\A[a-f0-9]{64}\.xlsx\z/', basename($path)));
            assertSameValue(false, str_contains($path, 'attacker'));
            assertSameValue('PK fixture', file_get_contents($path));
            $store->delete($path);
            assertSameValue(false, file_exists($path));

            foreach ([
                ['error' => UPLOAD_ERR_NO_FILE, 'tmp_name' => '', 'size' => 0],
                ['error' => UPLOAD_ERR_OK, 'tmp_name' => $source, 'size' => 0],
                ['error' => UPLOAD_ERR_OK, 'tmp_name' => $source, 'size' => (5 * 1024 * 1024) + 1],
            ] as $invalid) {
                assertThrows(
                    static fn () => $store->store($invalid),
                    \App\BulkImport\Http\BulkImportUploadRejected::class,
                );
            }
        } finally {
            if (is_file($source)) {
                unlink($source);
            }
            if (is_dir($root)) {
                rmdir($root);
            }
        }
    });

    $runner->add('DEPLOY-001 Bulk Import temporary storage rejects the public directory', function (): void {
        $public = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'antares-deploy-public-' . bin2hex(random_bytes(8));
        $temporary = $public . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'bulk-import';
        $source = $public . '-source.xlsx';
        if (!mkdir($public, 0700, true) && !is_dir($public)) {
            throw new \RuntimeException('Unable to create the DEPLOY-001 public fixture.');
        }
        file_put_contents($source, 'PK fixture');
        $store = new LocalBulkImportTemporaryFileStore(
            $temporary,
            $public,
            static fn (string $from, string $to): bool => copy($from, $to),
        );

        try {
            assertThrows(
                static fn () => $store->store([
                    'error' => UPLOAD_ERR_OK,
                    'tmp_name' => $source,
                    'size' => filesize($source),
                    'name' => 'fixture.xlsx',
                ]),
                \App\BulkImport\Http\BulkImportUploadRejected::class,
            );
        } finally {
            if (is_file($source)) {
                unlink($source);
            }
            if (is_dir($temporary)) {
                rmdir($temporary);
            }
            $temporaryParent = dirname($temporary);
            if (is_dir($temporaryParent)) {
                rmdir($temporaryParent);
            }
            if (is_dir($public)) {
                rmdir($public);
            }
        }
    });

    $runner->add('E015 Phase 7 routes navigation and template download preserve exact admin authority', function (): void {
        $routes = str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__) . '/routes/web.php'));
        foreach ([
            "\$router->get(\n    '/admin/bulk-import',",
            "\$router->get(\n    '/admin/bulk-import/template',",
            "\$router->get(\n    '/admin/bulk-import/result',",
            "\$router->get(\n    '/admin/bulk-import/errors.csv',",
            "\$router->post(\n    '/admin/bulk-import/preview',",
            "\$router->post(\n    '/admin/bulk-import/apply',",
        ] as $route) {
            assertSameValue(1, substr_count($routes, $route), $route);
        }
        assertSameValue(47, substr_count($routes, '$router->get('));
        assertSameValue(71, substr_count($routes, '$router->post('));
        assertSameValue(22, substr_count($routes, "    \$enrollmentAdministrationMiddleware,\n);"));

        foreach ([
            [null, null, 302],
            ['representative-22', 1, 403],
            ['admin', 1, 200],
        ] as [$identifier, $userId, $expectedStatus]) {
            $session = new FakeSessionManager();
            $session->userId = $userId;
            $middleware = new EnrollmentAdministrationMiddleware(new GetAuthenticatedUser(
                $session,
                new InMemoryUserRepository(
                    $identifier === null ? null : deliveryUser($identifier),
                ),
            ));
            assertSameValue($expectedStatus, e012EnrollmentMiddlewareStatus($middleware));
        }

        $fixture = representativePortalLoginFixture(false, 'admin');
        $fixture['session']->userId = 11;
        deliveryRequest('GET', '/admin/bulk-import/result');
        $adminShell = (new SharedShellDataFactory(
            $fixture['getUser'],
            $fixture['getRepresentative'],
            new FakeDeliveryCsrf(),
            WhiteLabelBranding::fromConfig([]),
        ))->forRequest(new Request());
        assertSameValue(true, shellItem($adminShell, 'Importación masiva')['active']);

        $representative = representativePortalLoginFixture(true, 'representative-22');
        $representative['session']->userId = 11;
        $representativeShell = (new SharedShellDataFactory(
            $representative['getUser'],
            $representative['getRepresentative'],
            new FakeDeliveryCsrf(),
            WhiteLabelBranding::fromConfig([]),
        ))->forRequest(new Request());
        assertSameValue(false, in_array(
            'Importación masiva',
            array_column($representativeShell->navigation, 'label'),
            true,
        ));

        $templatePath = dirname(__DIR__) . '/resources/templates/bulk-import/e015-family-import-v1.xlsx';
        $controller = new BulkImportTemplateController(new BulkImportTemplateFile($templatePath));
        assertSameValue((string) file_get_contents($templatePath), $controller->download());
        assertSameValue(200, http_response_code());
        $source = (string) file_get_contents(dirname(__DIR__) . '/app/BulkImport/Http/BulkImportTemplateController.php');
        foreach ([
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition: attachment',
            'X-Content-Type-Options: nosniff',
        ] as $expected) {
            deliveryAssertContains($expected, $source);
        }
        assertSameValue(false, str_contains($source, 'new Request'));
    });

    $runner->add('E015 Phase 7 preview apply PRG replay and session minimization work without JavaScript', function (): void {
        $fixture = bulkImportDeliveryFixture();
        $source = bulkImportDeliverySource('same-workbook-bytes');
        try {
            bulkImportDeliveryRequest('POST', '/admin/bulk-import/preview', [
                '_csrf_token' => 'delivery-csrf',
            ], bulkImportUpload($source));
            $html = $fixture['controller']->preview();
            assertSameValue(200, http_response_code());
            preg_match('/name="preview_token" value="([a-f0-9]{64})"/', $html, $matches);
            $token = $matches[1] ?? '';
            assertSameValue(64, strlen($token));
            foreach (['******5678', 'Vista previa segura', 'Aplicar importación', 'enctype="multipart/form-data"'] as $expected) {
                deliveryAssertContains($expected, $html);
            }
            foreach ([
                '1712345678', 'representative@example.test', '1985-01-02',
                'ClaveSegura9', hash('sha256', 'same-workbook-bytes'),
                $source,
            ] as $forbidden) {
                assertSameValue(false, str_contains($html, $forbidden), $forbidden);
            }
            assertSameValue([], $fixture['temporary']->active);

            bulkImportDeliveryRequest('POST', '/admin/bulk-import/apply', [
                '_csrf_token' => 'delivery-csrf',
                'preview_token' => $token,
            ], bulkImportUpload($source));
            assertSameValue('', $fixture['applyController']->apply());
            assertSameValue(303, http_response_code());
            assertSameValue([], $fixture['temporary']->active);

            bulkImportDeliveryRequest('GET', '/admin/bulk-import/result');
            $result = $fixture['controller']->result();
            assertSameValue(200, http_response_code());
            deliveryAssertContains('Resultado de importación masiva', $result);
            deliveryAssertContains('Aplicado', $result);
            assertSameValue(2, $fixture['environment']->persons->saveCalls());
            $counts = bulkImportDeliverySaveCounts($fixture['environment']);

            bulkImportDeliveryRequest('POST', '/admin/bulk-import/apply', [
                '_csrf_token' => 'delivery-csrf',
                'preview_token' => $token,
            ], bulkImportUpload($source));
            assertSameValue('', $fixture['applyController']->apply());
            assertSameValue(303, http_response_code());
            assertSameValue($counts, bulkImportDeliverySaveCounts($fixture['environment']));
            bulkImportDeliveryRequest('GET', '/admin/bulk-import/result');
            deliveryAssertContains(
                'La vista previa ya no es válida.',
                $fixture['controller']->result(),
            );
        } finally {
            unlink($source);
        }
    });

    $runner->add('E015 Phase 7 digest mismatch and invalid CSRF never invoke Apply and always clean temp files', function (): void {
        $fixture = bulkImportDeliveryFixture();
        $source = bulkImportDeliverySource('preview-bytes');
        $different = bulkImportDeliverySource('different-bytes');
        try {
            bulkImportDeliveryRequest('POST', '/admin/bulk-import/preview', [
                '_csrf_token' => 'delivery-csrf',
            ], bulkImportUpload($source));
            $html = $fixture['controller']->preview();
            preg_match('/name="preview_token" value="([a-f0-9]{64})"/', $html, $matches);
            $token = $matches[1] ?? '';

            bulkImportDeliveryRequest('POST', '/admin/bulk-import/apply', [
                '_csrf_token' => 'invalid',
                'preview_token' => $token,
            ], bulkImportUpload($source));
            $error = $fixture['applyController']->apply();
            assertSameValue(403, http_response_code());
            deliveryAssertContains('no pudo verificarse', $error);
            assertSameValue([], $fixture['temporary']->active);
            assertSameValue(0, $fixture['environment']->persons->saveCalls());

            bulkImportDeliveryRequest('POST', '/admin/bulk-import/apply', [
                '_csrf_token' => 'delivery-csrf',
                'preview_token' => $token,
            ], bulkImportUpload($different));
            assertSameValue('', $fixture['applyController']->apply());
            assertSameValue(303, http_response_code());
            assertSameValue([], $fixture['temporary']->active);
            assertSameValue(0, $fixture['environment']->persons->saveCalls());
            bulkImportDeliveryRequest('GET', '/admin/bulk-import/result');
            $result = $fixture['controller']->result();
            deliveryAssertContains('El archivo no coincide', $result);
            assertSameValue(false, str_contains($result, hash('sha256', 'preview-bytes')));

            bulkImportDeliveryRequest('POST', '/admin/bulk-import/apply', [
                '_csrf_token' => 'delivery-csrf',
                'preview_token' => $token,
            ], bulkImportUpload($source));
            $fixture['applyController']->apply();
            bulkImportDeliveryRequest('GET', '/admin/bulk-import/result');
            deliveryAssertContains('La vista previa ya no es válida.', $fixture['controller']->result());
        } finally {
            unlink($source);
            unlink($different);
        }
    });

    $runner->add('E015 Phase 7 parser and upload failures are safe and leave no temporary workbook', function (): void {
        $fixture = bulkImportDeliveryFixture();
        $source = bulkImportDeliverySource('invalid-workbook');
        try {
            $throwingPreview = new PreviewBulkImport(
                new class implements BulkImportWorkbookReader {
                    public function read(
                        string $localPath,
                        DateTimeImmutable $today,
                    ): \App\BulkImport\Application\Dto\WorkbookValidationResult
                    {
                        throw new BulkImportWorkbookRejected('El workbook XLSX fue rechazado.');
                    }
                },
                new \App\BulkImport\Application\Planning\BulkImportMatcher(
                    new FakeBulkImportCatalogResolver(),
                    $fixture['environment']->persons,
                    $fixture['environment']->representatives,
                    $fixture['environment']->users,
                    $fixture['environment']->students,
                    $fixture['environment']->families,
                    new \App\IdentityAccess\Application\Security\RepresentativePasswordPolicy(),
                ),
            );
            $controller = bulkImportDeliveryController($fixture, $throwingPreview);
            bulkImportDeliveryRequest('POST', '/admin/bulk-import/preview', [
                '_csrf_token' => 'delivery-csrf',
            ], bulkImportUpload($source));
            $html = $controller->preview();
            assertSameValue(422, http_response_code());
            deliveryAssertContains('El workbook XLSX fue rechazado.', $html);
            assertSameValue([], $fixture['temporary']->active);

            $fixture['temporary']->reject = true;
            bulkImportDeliveryRequest('POST', '/admin/bulk-import/preview', [
                '_csrf_token' => 'delivery-csrf',
            ], bulkImportUpload($source));
            $html = $fixture['controller']->preview();
            assertSameValue(422, http_response_code());
            deliveryAssertContains('no pudo cargarse', $html);
            assertSameValue([], $fixture['temporary']->active);
        } finally {
            unlink($source);
        }
    });

    $runner->add('E015 Phase 7 CSV is exact UTF-8 bounded and neutralizes spreadsheet formulas', function (): void {
        $csv = (new BulkImportErrorCsvWriter())->write([
            ['category' => '=cmd', 'sheet' => '+SUM', 'row' => 2, 'field' => '-1+2', 'message' => '@evil'],
            ['category' => 'VALUE_INVALID', 'sheet' => 'Familias', 'row' => 3, 'field' => null, 'message' => 'Texto español'],
        ]);
        assertSameValue(true, str_starts_with($csv, "category,sheet,row,field,message\r\n"));
        foreach (["'=cmd", "'+SUM", "'-1+2", "'@evil", 'Texto español'] as $expected) {
            deliveryAssertContains($expected, $csv);
        }
        assertSameValue(true, mb_check_encoding($csv, 'UTF-8'));
        foreach (['password', 'email', 'birth_date', 'document_number'] as $forbidden) {
            assertSameValue(false, str_contains($csv, $forbidden));
        }
    });

    $runner->add('E015 Phase 7 views escape safe output and expose no browser authority beyond opaque token', function (): void {
        $html = View::render('bulk-import.index', [
            'title' => 'Importación masiva',
            'csrfToken' => 'csrf',
            'previewToken' => str_repeat('a', 64),
            'errorMessage' => null,
            'documentTypes' => [],
            'sexes' => [],
            'relationshipTypes' => [],
            'preview' => [
                'counts' => ['families' => 1, 'new' => 0, 'already_exists' => 0, 'conflicts' => 1, 'issues' => 1],
                'items' => [[
                    'family_code' => 'F00000001',
                    'display_name' => '<script>alert(1)</script>',
                    'person_display_name' => 'Persona',
                    'masked_document_number' => '******5678',
                    'institutional_code' => 'EST-001',
                    'classification' => 'CONFLICT',
                    'message' => '<img src=x onerror=alert(1)>',
                ]],
                'issues' => [[
                    'category' => 'VALUE_INVALID',
                    'sheet' => 'Familias',
                    'row' => 2,
                    'field' => 'display_name',
                    'message' => '<svg onload=alert(1)>',
                ]],
            ],
        ]);
        foreach ([
            '&lt;script&gt;alert(1)&lt;/script&gt;',
            '&lt;img src=x onerror=alert(1)&gt;',
            '&lt;svg onload=alert(1)&gt;',
        ] as $escaped) {
            deliveryAssertContains($escaped, $html);
        }
        foreach (['<script>alert(1)</script>', 'name="digest"', 'name="actor"', 'name="family_id"', 'name="rows"'] as $forbidden) {
            assertSameValue(false, str_contains($html, $forbidden), $forbidden);
        }
        assertSameValue(0, preg_match('/<script>.*alert/s', $html));
    });

    $runner->add('E015 Phase 8 missing CSRF rejects Preview and Apply without writes or retained files', function (): void {
        $fixture = bulkImportDeliveryFixture();
        $source = bulkImportDeliverySource('phase8-csrf-bytes');
        try {
            bulkImportDeliveryRequest('POST', '/admin/bulk-import/preview', [], bulkImportUpload($source));
            $previewFailure = $fixture['controller']->preview();
            assertSameValue(403, http_response_code());
            deliveryAssertContains('no pudo verificarse', $previewFailure);
            assertSameValue(0, $fixture['temporary']->stores);
            assertSameValue(0, $fixture['environment']->persons->saveCalls());

            bulkImportDeliveryRequest('POST', '/admin/bulk-import/preview', [
                '_csrf_token' => 'delivery-csrf',
            ], bulkImportUpload($source));
            $preview = $fixture['controller']->preview();
            preg_match('/name="preview_token" value="([a-f0-9]{64})"/', $preview, $matches);
            $token = $matches[1] ?? '';
            assertSameValue(64, strlen($token));

            bulkImportDeliveryRequest('POST', '/admin/bulk-import/apply', [
                'preview_token' => $token,
            ], bulkImportUpload($source));
            $applyFailure = $fixture['applyController']->apply();
            assertSameValue(403, http_response_code());
            deliveryAssertContains('no pudo verificarse', $applyFailure);
            assertSameValue([], $fixture['temporary']->active);
            assertSameValue(0, $fixture['environment']->persons->saveCalls());

            bulkImportDeliveryRequest('POST', '/admin/bulk-import/apply', [
                '_csrf_token' => 'delivery-csrf',
                'preview_token' => $token,
            ], bulkImportUpload($source));
            assertSameValue('', $fixture['applyController']->apply());
            assertSameValue(303, http_response_code());
            assertSameValue([], $fixture['temporary']->active);
            assertSameValue(2, $fixture['environment']->persons->saveCalls());
        } finally {
            unlink($source);
        }
    });

    $runner->add('E015 Phase 8 concurrent Apply consumes its grant and cleans the exact reupload', function (): void {
        $transactions = new E015ConcurrentChangeTransactionRunner();
        $fixture = bulkImportDeliveryFixture($transactions);
        $source = bulkImportDeliverySource('phase8-concurrent-bytes');
        try {
            bulkImportDeliveryRequest('POST', '/admin/bulk-import/preview', [
                '_csrf_token' => 'delivery-csrf',
            ], bulkImportUpload($source));
            $preview = $fixture['controller']->preview();
            preg_match('/name="preview_token" value="([a-f0-9]{64})"/', $preview, $matches);
            $token = $matches[1] ?? '';

            bulkImportDeliveryRequest('POST', '/admin/bulk-import/apply', [
                '_csrf_token' => 'delivery-csrf',
                'preview_token' => $token,
            ], bulkImportUpload($source));
            assertSameValue('', $fixture['applyController']->apply());
            assertSameValue(303, http_response_code());
            assertSameValue(1, $transactions->calls());
            assertSameValue([], $fixture['temporary']->active);
            assertSameValue(0, $fixture['environment']->persons->saveCalls());

            bulkImportDeliveryRequest('GET', '/admin/bulk-import/result');
            $result = $fixture['controller']->result();
            deliveryAssertContains('cambió concurrentemente', $result);
            assertSameValue(false, str_contains($result, 'Synthetic deadlock detail'));

            bulkImportDeliveryRequest('POST', '/admin/bulk-import/apply', [
                '_csrf_token' => 'delivery-csrf',
                'preview_token' => $token,
            ], bulkImportUpload($source));
            $fixture['applyController']->apply();
            assertSameValue(1, $transactions->calls());
            assertSameValue([], $fixture['temporary']->active);
        } finally {
            unlink($source);
        }
    });

    $runner->add('E015 Phase 8 result remains escaped responsive accessible and JavaScript independent', function (): void {
        $payload = '<script>alert("phase8")</script>';
        $html = View::render('bulk-import.result', [
            'title' => 'Resultado de importación masiva',
            'hasReport' => true,
            'result' => [
                'families' => [[
                    'family_code' => $payload,
                    'classification' => 'CONFLICT',
                    'label' => $payload,
                    'message' => $payload,
                ]],
                'issues' => [[
                    'category' => $payload,
                    'sheet' => $payload,
                    'row' => 2,
                    'field' => $payload,
                    'message' => $payload,
                ]],
            ],
        ]);

        deliveryAssertContains('&lt;script&gt;alert(&quot;phase8&quot;)&lt;/script&gt;', $html);
        foreach ([
            '<h1', '<caption>Resultado por familia.</caption>', '<caption>Observaciones seguras.</caption>',
            '<th scope="col">Familia</th>', '<th scope="col">Mensaje</th>', 'table-responsive',
            'Descargar errores CSV',
        ] as $expected) {
            deliveryAssertContains($expected, $html);
        }
        assertSameValue(false, str_contains($html, $payload));
    });

    $runner->add('E015 Phase 7 Delivery remains thin schema-free and excludes later infrastructure', function (): void {
        $root = dirname(__DIR__);
        $source = '';
        foreach ([
            'app/BulkImport/Http',
            'app/BulkImport/Application/Delivery',
            'app/BulkImport/Infrastructure/Filesystem',
            'resources/views/bulk-import',
        ] as $path) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $path)) as $file) {
                if ($file->isFile()) {
                    $source .= (string) file_get_contents($file->getPathname());
                }
            }
        }
        foreach ([
            'new PDO', 'SELECT ', 'INSERT ', 'UPDATE ', 'DELETE FROM',
            'import_batches', 'import_rows', 'Redis', 'queue', 'institution_id',
        ] as $forbidden) {
            assertSameValue(false, stripos($source, $forbidden) !== false, $forbidden);
        }
        assertSameValue(false, file_exists($root . '/database/migrations/012_bulk_import.php'));
        $controller = (string) file_get_contents($root . '/app/BulkImport/Http/BulkImportController.php');
        foreach (['BulkImportMatcher', 'OpenSpout', 'ZipArchive', 'PasswordHasher'] as $forbidden) {
            assertSameValue(false, str_contains($controller, $forbidden), $forbidden);
        }
    });
}

/** @return array<string, mixed> */
function bulkImportDeliveryFixture(?\Core\Application\TransactionRunner $transactions = null): array
{
    $environment = new BulkImportApplicationEnvironment(e015Phase6Workbook(), $transactions);
    $session = new FakeSessionManager();
    $session->userId = 11;
    $clock = new BulkImportDeliveryClock(new DateTimeImmutable('2026-09-02 12:00:00+00:00'));
    $workflow = new BulkImportDeliverySession($session, $clock);
    $temporary = new FakeBulkImportTemporaryFileStore();
    $fixture = compact('environment', 'session', 'clock', 'workflow', 'temporary');
    $controller = bulkImportDeliveryController($fixture, $environment->preview);
    $applyController = new BulkImportApplyController(
        $environment->apply,
        $temporary,
        $workflow,
        new FakeDeliveryCsrf(),
        $session,
        $clock,
    );

    return $fixture + compact('controller', 'applyController');
}

/** @param array<string, mixed> $fixture */
function bulkImportDeliveryController(array $fixture, PreviewBulkImport $preview): BulkImportController
{
    $personOptions = new class implements PersonFormOptionsProvider {
        public function get(): PersonFormOptions
        {
            return new PersonFormOptions(
                [new PersonFormOption(1, 'dni', 'Cédula')],
                [new PersonFormOption(2, 'female', 'Femenino'), new PersonFormOption(3, 'male', 'Masculino')],
                [],
                [],
                [new PersonFormOption(4, 'ACTIVE', 'Activo'), new PersonFormOption(5, 'INACTIVE', 'Inactivo')],
            );
        }
    };
    $familyOptions = new class implements FamilyFormOptionsProvider {
        public function get(): FamilyFormOptions
        {
            return new FamilyFormOptions(
                [new FamilyFormOption(11, 'mother', 'Madre')],
                [FamilyStatus::Active, FamilyStatus::Inactive],
            );
        }
    };

    return new BulkImportController(
        $preview,
        $fixture['temporary'],
        $fixture['workflow'],
        new BulkImportErrorCsvWriter(),
        new FakeDeliveryCsrf(),
        $fixture['session'],
        $personOptions,
        $familyOptions,
        $fixture['clock'],
    );
}

function bulkImportDeliverySource(string $bytes): string
{
    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'antares-e015-source-' . bin2hex(random_bytes(8)) . '.xlsx';
    file_put_contents($path, $bytes);

    return $path;
}

/** @return array<string, mixed> */
function bulkImportUpload(string $source): array
{
    return [
        'workbook' => [
            'name' => 'familias.xlsx',
            'type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'tmp_name' => $source,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($source),
        ],
    ];
}

/** @param array<string, mixed> $post @param array<string, mixed> $files */
function bulkImportDeliveryRequest(string $method, string $uri, array $post = [], array $files = []): void
{
    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['REQUEST_URI'] = $uri;
    $_GET = $method === 'GET' ? $post : [];
    $_POST = $method === 'POST' ? $post : [];
    $_FILES = $files;
    http_response_code(200);
}

/** @return array<string, int> */
function bulkImportDeliverySaveCounts(BulkImportApplicationEnvironment $environment): array
{
    return [
        'persons' => $environment->persons->saveCalls(),
        'representatives' => $environment->representatives->saveCalls(),
        'users' => $environment->users->saveCalls(),
        'students' => $environment->students->saveCalls(),
        'families' => $environment->families->saveCalls(),
    ];
}
