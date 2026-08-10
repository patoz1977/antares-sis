<?php

declare(strict_types=1);

namespace Tests;

use Tests\Support\TestRunner;

function registerForgotPasswordAssistanceTests(TestRunner $runner): void
{
    $runner->add('Forgot Password assistance exposes one public read-only route', function (): void {
        $routes = str_replace(
            ["\r\n", "\r"],
            "\n",
            (string) file_get_contents(dirname(__DIR__) . '/routes/web.php'),
        );

        assertSameValue(1, substr_count($routes, "get('/forgot-password'"));
        foreach ([
            "post('/forgot-password'",
            "get('/reset-password'",
            "post('/reset-password'",
            "post('/verification-code'",
        ] as $forbiddenRoute) {
            assertSameValue(false, str_contains($routes, $forbiddenRoute));
        }
        assertSameValue(
            true,
            str_contains($routes, "['showForgotPassword']")
                || str_contains($routes, "'showForgotPassword'"),
        );
    });

    $runner->add('Forgot Password assistance is public generic and requests no account data', function (): void {
        $controller = deliveryDashboardController('admin');
        deliveryRequest('GET', '/forgot-password');
        $html = $controller->showForgotPassword();

        assertSameValue(200, http_response_code());
        deliveryAssertContains('Forgot password', $html);
        deliveryAssertContains('contact the school administration office', $html);
        deliveryAssertContains('href="/login"', $html);
        assertSameValue(false, str_contains($html, '<form'));
        foreach (['name="username"', 'name="document"', 'name="email"', 'token', 'OTP'] as $forbidden) {
            assertSameValue(false, str_contains($html, $forbidden));
        }
    });

    $runner->add('Login links to assisted recovery without changing authentication fields', function (): void {
        $html = (string) file_get_contents(
            dirname(__DIR__) . '/resources/views/auth/login.php'
        );

        deliveryAssertContains('href="/forgot-password"', $html);
        deliveryAssertContains('name="username"', $html);
        deliveryAssertContains('name="password"', $html);
        assertSameValue(false, str_contains($html, 'name="email"'));
    });

    $runner->add('Forgot Password assistance adds no recovery infrastructure or institution coupling', function (): void {
        $root = dirname(__DIR__);
        $production = implode("\n", array_map(
            static fn (string $path): string => (string) file_get_contents($path),
            [
                $root . '/app/IdentityAccess/Http/AuthenticationController.php',
                $root . '/resources/views/auth/login.php',
                $root . '/resources/views/auth/forgot-password.php',
                $root . '/routes/web.php',
            ],
        ));

        foreach (['mail(', 'SMTP', 'PHPMailer', 'SendGrid', 'SES', 'PasswordReset', 'VerificationCode'] as $forbidden) {
            assertSameValue(false, str_contains($production, $forbidden));
        }
        assertSameValue(0, preg_match('/antares|ueant/i', $production));
        assertSameValue(false, str_contains($production, 'UserRepository'));
        assertSameValue(false, str_contains($production, 'PersonRepository'));
    });
}
