<?php

declare(strict_types=1);

namespace Tests;

use App\Family\Domain\Exception\InvalidFamilyState;
use App\Family\Http\FamilyResourceFeedback;
use App\Shared\Http\SafeErrorPage;
use Core\Foundation\Kernel;
use Core\Http\Request;
use Core\Routing\Router;
use Core\View\View;
use Tests\Support\TestRunner;

function registerSharedPresentationCorrectionTests(TestRunner $runner): void
{
    $runner->add('Shared safe errors keep 403 and 404 status with Spanish White Label presentation', function (): void {
        $previousStatus = http_response_code();
        $resolver = new \ReflectionProperty(View::class, 'sharedDataResolver');
        $previousResolver = $resolver->getValue();
        View::setSharedDataResolver(null);
        try {
            $router = new Router();
            $router->get('/only-get', static fn (): string => 'OK');
            $router->setNotFoundRenderer(static fn (): string =>
                SafeErrorPage::render(404, 'La página solicitada no existe.'));
            ob_start();
            $router->dispatch('POST', '/only-get');
            $notFound = (string) ob_get_clean();
            assertSameValue(404, http_response_code());
            deliveryAssertContains('lang="es"', $notFound);
            deliveryAssertContains('Página no encontrada', $notFound);
            deliveryAssertContains('Sistema de Información Escolar', $notFound);

            $router->setNotFoundRenderer(static function (): never {
                throw new \RuntimeException('private SQLSTATE or path');
            });
            ob_start();
            $router->dispatch('POST', '/only-get');
            $fallback = (string) ob_get_clean();
            assertSameValue(404, http_response_code());
            deliveryAssertContains('Página no encontrada.', $fallback);
            assertSameValue(false, str_contains($fallback, 'SQLSTATE'));

            http_response_code(403);
            $forbidden = SafeErrorPage::render(403, 'No tienes permiso para acceder a esta página.');
            assertSameValue(403, http_response_code());
            deliveryAssertContains('Acceso no permitido', $forbidden);
            deliveryAssertContains('No tienes permiso', $forbidden);
            assertSameValue(false, str_contains($forbidden, 'SQLSTATE'));
        } finally {
            View::setSharedDataResolver($previousResolver);
            http_response_code($previousStatus ?: 200);
        }
    });

    $runner->add('Assigned Family resource rejection has contextual Spanish feedback and safe fallback', function (): void {
        foreach ([
            InvalidFamilyState::ASSIGNED_ADDRESS => 'dirección mientras tenga asignaciones activas',
            InvalidFamilyState::ASSIGNED_EMERGENCY_CONTACT => 'contacto de emergencia mientras tenga asignaciones activas',
            InvalidFamilyState::ASSIGNED_AUTHORIZED_PICKUP => 'persona autorizada mientras tenga asignaciones activas',
        ] as $code => $expected) {
            $message = FamilyResourceFeedback::forInvalidState(new InvalidFamilyState('private detail', $code));
            deliveryAssertContains($expected, $message);
            assertSameValue(false, str_contains($message, 'private detail'));
        }
        assertSameValue(
            'El recurso seleccionado no está disponible para esta familia.',
            FamilyResourceFeedback::forInvalidState(new InvalidFamilyState('private detail')),
        );
    });

    $runner->add('Production 500 uses Spanish safe shell and falls back without exception disclosure', function (): void {
        $previousMethod = $_SERVER['REQUEST_METHOD'] ?? null;
        $previousUri = $_SERVER['REQUEST_URI'] ?? null;
        $previousStatus = http_response_code();
        $resolver = new \ReflectionProperty(View::class, 'sharedDataResolver');
        $previousResolver = $resolver->getValue();
        View::setSharedDataResolver(null);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/presentation-failure';
        try {
            $router = new Router();
            $router->get('/presentation-failure', static function (): never {
                throw new \RuntimeException('private SQLSTATE or path');
            });
            $kernel = new Kernel(new Request(), $router);
            $kernel->configureDiagnostics('production', false);
            $kernel->setServerErrorRenderer(static fn (): string =>
                SafeErrorPage::render(500, 'No se pudo completar la solicitud. Inténtelo nuevamente.'));
            ob_start();
            $kernel->handle();
            $content = (string) ob_get_clean();
            assertSameValue(500, http_response_code());
            deliveryAssertContains('No se pudo completar la solicitud.', $content);
            deliveryAssertContains('lang="es"', $content);
            assertSameValue(false, str_contains($content, 'SQLSTATE'));
            assertSameValue(false, str_contains($content, 'private'));
        } finally {
            View::setSharedDataResolver($previousResolver);
            http_response_code($previousStatus ?: 200);
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
        }
    });
}
