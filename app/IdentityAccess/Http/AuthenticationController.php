<?php

declare(strict_types=1);

namespace App\IdentityAccess\Http;

use App\Controllers\Controller;
use App\IdentityAccess\Application\AuthenticateUser;
use App\IdentityAccess\Application\Contract\CsrfTokenManager;
use App\IdentityAccess\Application\Contract\SessionManager;
use App\IdentityAccess\Application\GetAuthenticatedUser;
use App\IdentityAccess\Application\LogoutUser;
use Core\Http\Request;

final class AuthenticationController extends Controller
{
    private const FLASH_ERROR_KEY = '_flash_authentication_error';

    public function __construct(
        private readonly AuthenticateUser $authenticateUser,
        private readonly LogoutUser $logoutUser,
        private readonly GetAuthenticatedUser $getAuthenticatedUser,
        private readonly CsrfTokenManager $csrf,
        private readonly SessionManager $session,
    ) {
    }

    public function showLogin(): string
    {
        if ($this->getAuthenticatedUser->handle() !== null) {
            return $this->redirect('/');
        }

        $flashMessage = $this->session->pull(self::FLASH_ERROR_KEY);

        return $this->view('auth.login', [
            'title' => 'Sign in',
            'flashMessage' => is_string($flashMessage) ? $flashMessage : null,
            'csrfToken' => $this->csrf->token(),
        ]);
    }

    public function login(): string
    {
        $input = (new Request())->input();
        $csrfToken = $this->stringInput($input, '_csrf_token');

        if (!$this->csrf->isValid($csrfToken)) {
            $this->session->put(self::FLASH_ERROR_KEY, 'Invalid request.');

            return $this->redirect('/login', 303);
        }

        $identifier = $this->stringInput(
            $input,
            array_key_exists('login_identifier', $input) ? 'login_identifier' : 'username'
        );
        $password = $this->stringInput($input, 'password', false);

        $result = $this->authenticateUser->handle($identifier, $password);
        if (!$result->isSuccessful()) {
            $this->session->put(self::FLASH_ERROR_KEY, $result->externalMessage());

            return $this->redirect('/login', 303);
        }

        return $this->redirect('/', 303);
    }

    public function logout(): string
    {
        $input = (new Request())->input();

        if (!$this->csrf->isValid($this->stringInput($input, '_csrf_token'))) {
            $this->session->put(self::FLASH_ERROR_KEY, 'Invalid request.');

            return $this->redirect('/login', 303);
        }

        $this->logoutUser->handle();

        return $this->redirect('/login', 303);
    }

    public function dashboard(): string
    {
        $user = $this->getAuthenticatedUser->handle();

        return $this->view('dashboard.index', [
            'title' => 'Dashboard',
            'csrfToken' => $this->csrf->token(),
            'canAccessPersons' => $user?->loginIdentifier === 'admin',
        ]);
    }

    private function stringInput(array $input, string $key, bool $trim = true): string
    {
        $value = $input[$key] ?? '';
        if (!is_string($value)) {
            return '';
        }

        return $trim ? trim($value) : $value;
    }

    private function redirect(string $location, int $status = 302): string
    {
        header('Location: ' . $location);
        http_response_code($status);

        return '';
    }
}
