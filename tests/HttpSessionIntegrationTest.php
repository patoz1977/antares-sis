<?php

declare(strict_types=1);

use App\IdentityAccess\Application\GetAuthenticatedUser;
use App\IdentityAccess\Application\LogoutUser;
use App\IdentityAccess\Http\AuthenticationController;
use App\IdentityAccess\Infrastructure\Session\PhpSessionManager;
use App\IdentityAccess\Infrastructure\Session\SessionCsrfTokenManager;
use Core\Session\Session;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/IdentityAccessTest.php';

function integrationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

ob_start();

$nativeSession = new Session();
$sessionPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'antares-e0041-sessions';
if (!is_dir($sessionPath) && !mkdir($sessionPath, 0700, true) && !is_dir($sessionPath)) {
    throw new RuntimeException('Unable to create the session test directory.');
}
session_save_path($sessionPath);
session_id('e0041-before-regeneration');
$nativeSession->start();
$before = session_id();

$realSession = new PhpSessionManager($nativeSession);
$realSession->regenerateForUser(7);
$after = session_id();
integrationAssert($before !== $after, 'The real PHP session identifier was not regenerated.');
integrationAssert($realSession->authenticatedUserId() === 7, 'The real session did not store the minimal user reference.');
integrationAssert(array_keys($_SESSION) === ['user_id'], 'The real session stored data beyond the user reference.');
$realSession->destroy();
integrationAssert(session_status() !== PHP_SESSION_ACTIVE, 'The real PHP session remained active after logout.');
integrationAssert($_SESSION === [], 'The real PHP session data was not destroyed.');

[$authenticate, $repository, $session] = Tests\authenticationFixture();
$csrf = new SessionCsrfTokenManager($session);
$controller = new AuthenticationController(
    $authenticate,
    new LogoutUser($session, new Tests\FakeSecurityEvents()),
    new GetAuthenticatedUser($session, $repository),
    $csrf,
    $session,
);

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI'] = '/login';
$_POST = [
    '_csrf_token' => $csrf->token(),
    'username' => ' ADMIN ',
    'password' => 'correct-password',
];
$controller->login();
integrationAssert(http_response_code() === 303, 'Successful HTTP login did not use a 303 redirect.');
integrationAssert($session->authenticatedUserId() === 1, 'HTTP login did not establish the authenticated session.');

$_SERVER['REQUEST_URI'] = '/logout';
$_POST = ['_csrf_token' => $csrf->token()];
$controller->logout();
integrationAssert(http_response_code() === 303, 'HTTP logout did not use a 303 redirect.');
integrationAssert($session->authenticatedUserId() === null, 'HTTP logout did not destroy authentication state.');

[$failedAuthenticate, $failedRepository, $failedSession] = Tests\authenticationFixture();
$failedCsrf = new SessionCsrfTokenManager($failedSession);
$failedController = new AuthenticationController(
    $failedAuthenticate,
    new LogoutUser($failedSession, new Tests\FakeSecurityEvents()),
    new GetAuthenticatedUser($failedSession, $failedRepository),
    $failedCsrf,
    $failedSession,
);
$_SERVER['REQUEST_URI'] = '/login';
$_POST = [
    '_csrf_token' => $failedCsrf->token(),
    'username' => 'admin',
    'password' => 'wrong',
];
$failedController->login();
integrationAssert(
    $failedSession->pull('_flash_authentication_error') === 'Invalid credentials.',
    'HTTP login exposed a non-generic authentication failure.'
);

ob_end_clean();
echo "PASS real PHP session regeneration and destruction\n";
echo "PASS HTTP login/logout request, CSRF and redirect integration\n";
echo "PASS HTTP authentication failure remains generic\n";
