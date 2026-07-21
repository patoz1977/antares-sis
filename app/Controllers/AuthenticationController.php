<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthenticationServiceInterface;
use Core\Http\Request;

final class AuthenticationController extends Controller
{
    public function __construct(
        private AuthenticationServiceInterface $authenticationService
    ) {
    }

    public function showLogin(): string
    {
        if ($this->authenticationService->check()) {
            header('Location: /');
            http_response_code(302);

            return '';
        }

        $flashMessage = $this->pullFlash('error');

        return $this->view('auth.login', [
            'title' => 'Sign in',
            'flashMessage' => $flashMessage,
        ]);
    }

    public function login(): string
    {
        $request = new Request();
        $input = $request->input();

        $usernameValue = $input['username'] ?? '';
        $passwordValue = $input['password'] ?? '';

        $username = is_string($usernameValue) ? trim($usernameValue) : '';
        $password = is_string($passwordValue) ? $passwordValue : '';

        if ($username === '' || $password === '' || !$this->authenticationService->attempt($username, $password)) {
            $this->flash('error', 'Invalid credentials.');

            header('Location: /login');
            http_response_code(302);

            return '';
        }

        header('Location: /');
        http_response_code(302);

        return '';
    }

    public function logout(): string
    {
        $this->ensureSessionStarted();

        $this->authenticationService->logout();

        header('Location: /login');
        http_response_code(302);

        return '';
    }

    public function dashboard(): string
    {
        return $this->view('dashboard.index', [
            'title' => 'Dashboard',
        ]);
    }

    private function flash(string $key, string $message): void
    {
        $this->ensureSessionStarted();

        $_SESSION['_flash'][$key] = $message;
    }

    private function pullFlash(string $key): ?string
    {
        $this->ensureSessionStarted();

        if (!isset($_SESSION['_flash'][$key]) || !is_string($_SESSION['_flash'][$key])) {
            return null;
        }

        $message = $_SESSION['_flash'][$key];
        unset($_SESSION['_flash'][$key]);

        if ($_SESSION['_flash'] === []) {
            unset($_SESSION['_flash']);
        }

        return $message;
    }

    private function ensureSessionStarted(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_start();
    }
}
