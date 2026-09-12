<?php

declare(strict_types=1);

namespace Tests;

use Core\Foundation\Kernel;
use Core\Http\Request;
use Core\Routing\Router;
use RuntimeException;
use Tests\Support\TestRunner;
use Throwable;

function registerProductionErrorHandlingTests(TestRunner $runner): void
{
    $runner->add('DEPLOY-001 diagnostics require local or development with explicit debug', function (): void {
        foreach ([
            ['production', false, false],
            ['production', true, false],
            ['local', false, false],
            ['local', true, true],
            ['development', false, false],
            ['development', true, true],
        ] as [$environment, $debug, $expected]) {
            assertSameValue($expected, Kernel::shouldDisplayDiagnostics($environment, $debug));
        }

        assertSameValue(false, Kernel::shouldDisplayDiagnostics('LOCAL', 'true'));
        assertSameValue(false, Kernel::shouldDisplayDiagnostics(null, true));
    });

    $runner->add('DEPLOY-001 hidden diagnostics return only the generic HTTP 500 response', function (): void {
        foreach ([
            ['production', false],
            ['production', true],
            ['local', false],
            ['development', false],
        ] as [$environment, $debug]) {
            $result = deployExecuteKernelFailure($environment, $debug);

            assertSameValue(null, $result['throwable']);
            assertSameValue(500, $result['status']);
            assertSameValue('Internal Server Error', $result['output']);
            foreach (['DEPLOY-001 internal failure', 'private/path', 'Stack trace'] as $forbidden) {
                assertSameValue(false, str_contains($result['output'], $forbidden));
            }
        }
    });

    $runner->add('DEPLOY-001 local diagnostics rethrow only when debug is enabled', function (): void {
        foreach (['local', 'development'] as $environment) {
            $result = deployExecuteKernelFailure($environment, true);
            $throwableClass = $result['throwable'] === null ? null : $result['throwable']::class;

            assertSameValue(RuntimeException::class, $throwableClass);
            assertSameValue('DEPLOY-001 internal failure at private/path', $result['throwable']?->getMessage());
        }
    });

    $runner->add('DEPLOY-001 public entry point disables display errors before bootstrap', function (): void {
        $source = (string) file_get_contents(dirname(__DIR__) . '/public/index.php');
        $disablePosition = strpos($source, "ini_set('display_errors', '0');");
        $bootstrapPosition = strpos($source, '$app = require');
        $policyPosition = strpos($source, 'Kernel::shouldDisplayDiagnostics(');

        assertSameValue(true, is_int($disablePosition));
        assertSameValue(true, is_int($bootstrapPosition));
        assertSameValue(true, is_int($policyPosition));
        assertSameValue(true, $disablePosition < $bootstrapPosition);
        assertSameValue(true, $bootstrapPosition < $policyPosition);
    });
}

/** @return array{output: string, status: int|false, throwable: ?Throwable} */
function deployExecuteKernelFailure(string $environment, bool $debug): array
{
    $previousMethod = $_SERVER['REQUEST_METHOD'] ?? null;
    $previousUri = $_SERVER['REQUEST_URI'] ?? null;
    $previousStatus = http_response_code();

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/deploy-error';
    http_response_code(200);

    $request = new Request();
    $router = new Router();
    $router->get('/deploy-error', static function (): never {
        throw new RuntimeException('DEPLOY-001 internal failure at private/path');
    });
    $kernel = new Kernel($request, $router);
    $kernel->configureDiagnostics($environment, $debug);

    $initialBufferLevel = ob_get_level();
    ob_start();
    $throwable = null;

    try {
        $kernel->handle();
    } catch (Throwable $exception) {
        $throwable = $exception;
    }

    $output = '';
    while (ob_get_level() > $initialBufferLevel) {
        $chunk = ob_get_clean();
        if (is_string($chunk)) {
            $output = $chunk . $output;
        }
    }

    $status = http_response_code();

    if ($previousMethod === null) {
        unset($_SERVER['REQUEST_METHOD']);
    } else {
        $_SERVER['REQUEST_METHOD'] = $previousMethod;
    }
    if ($previousUri === null) {
        unset($_SERVER['REQUEST_URI']);
    } else {
        $_SERVER['REQUEST_URI'] = $previousUri;
    }
    if (is_int($previousStatus)) {
        http_response_code($previousStatus);
    }

    return compact('output', 'status', 'throwable');
}
