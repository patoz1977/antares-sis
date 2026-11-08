<?php

declare(strict_types=1);

namespace App\IdentityAccess\Http;

use App\Controllers\Controller;
use App\IdentityAccess\Application\AdministrativeUsers\AdministrativeUserSearchCriteria;
use App\IdentityAccess\Application\AdministrativeUsers\ChangeAdministrativeUserLoginIdentifier;
use App\IdentityAccess\Application\AdministrativeUsers\ChangeAdministrativeUserPassword;
use App\IdentityAccess\Application\AdministrativeUsers\ChangeAdministrativeUserStatus;
use App\IdentityAccess\Application\AdministrativeUsers\Exception\AdministrativeLoginIdentifierAlreadyUsed;
use App\IdentityAccess\Application\AdministrativeUsers\Exception\AdministrativeUserNotFound;
use App\IdentityAccess\Application\AdministrativeUsers\Exception\AdministrativeUserOperationNotAllowed;
use App\IdentityAccess\Application\AdministrativeUsers\Exception\InvalidAdministrativeUserSearch;
use App\IdentityAccess\Application\AdministrativeUsers\GetAdministrativeUser;
use App\IdentityAccess\Application\AdministrativeUsers\SearchAdministrativeUsers;
use App\IdentityAccess\Application\Contract\CsrfTokenManager;
use App\IdentityAccess\Application\Contract\SessionManager;
use App\IdentityAccess\Application\Exception\InstitutionalReportingAccessConflict;
use App\IdentityAccess\Application\Exception\InvalidInstitutionalReportingPassword;
use App\IdentityAccess\Application\Exception\InvalidRepresentativePassword;
use App\IdentityAccess\Application\Exception\RepresentativeUserNotFound;
use App\IdentityAccess\Domain\Exception\InvalidUserState;
use App\Representative\Application\Exception\RepresentativeNotFound;
use Core\Http\Request;
use Throwable;

final class AdministrativeUserController extends Controller
{
    private const TRUSTED_USER_ID_KEY = '_administrative_user_id';
    private const FLASH_SUCCESS_KEY = '_flash_administrative_user_success';
    private const FLASH_ERROR_KEY = '_flash_administrative_user_error';

    public function __construct(
        private readonly SearchAdministrativeUsers $searchUsers,
        private readonly GetAdministrativeUser $getUser,
        private readonly ChangeAdministrativeUserStatus $changeStatus,
        private readonly ChangeAdministrativeUserPassword $changePassword,
        private readonly ChangeAdministrativeUserLoginIdentifier $changeLoginIdentifier,
        private readonly CsrfTokenManager $csrf,
        private readonly SessionManager $session,
    ) {
    }

    public function index(): string
    {
        $this->preventCaching();

        return $this->renderIndex(null, '', '');
    }

    public function search(): string
    {
        $this->preventCaching();
        $input = (new Request())->input();
        $criterion = $this->scalar($input, 'criterion');
        $value = $this->scalar($input, 'value');
        if (!$this->csrf->isValid($this->scalar($input, '_csrf_token'))) {
            return $this->renderIndex(null, $criterion, $value, ['No se pudo verificar la solicitud.'], 422);
        }

        try {
            $criteria = new AdministrativeUserSearchCriteria($criterion, $value);

            return $this->renderIndex($criteria, $criteria->field->value, $criteria->value);
        } catch (InvalidAdministrativeUserSearch $exception) {
            return $this->renderIndex(null, $criterion, $value, [$exception->getMessage()], 422);
        } catch (Throwable) {
            return $this->renderIndex(null, '', '', ['No se pudo completar la búsqueda.'], 500);
        }
    }

    public function show(): string
    {
        $userId = $this->positiveInteger((new Request())->query()['user_id'] ?? null);
        if ($userId === null) {
            return $this->indexFailure('Selecciona un usuario válido.');
        }

        return $this->renderManage($userId);
    }

    public function changeStatus(): string
    {
        $input = (new Request())->input();
        $userId = $this->trustedUserId();
        $error = $this->mutationContextError($input, $userId);
        if ($error !== null) {
            return $this->mutationFailure($userId, [$error]);
        }

        $action = $this->scalar($input, 'action');
        if ($action !== 'activate' && $action !== 'disable') {
            return $this->mutationFailure($userId, ['Selecciona una acción de estado válida.']);
        }

        try {
            $changed = $this->changeStatus->handle($userId, $action === 'activate');
        } catch (AdministrativeUserNotFound) {
            return $this->indexFailure('No se encontró el usuario solicitado.');
        } catch (AdministrativeUserOperationNotAllowed|InstitutionalReportingAccessConflict) {
            return $this->mutationFailure($userId, ['No se permite cambiar el estado de esta cuenta.']);
        }

        $this->session->put(
            self::FLASH_SUCCESS_KEY,
            $changed
                ? ($action === 'activate' ? 'Usuario activado correctamente.' : 'Usuario deshabilitado correctamente.')
                : 'El usuario ya tenía el estado solicitado.',
        );

        return $this->redirect($this->manageUrl($userId), 303);
    }

    public function changePassword(): string
    {
        $input = (new Request())->input();
        $userId = $this->trustedUserId();
        $error = $this->mutationContextError($input, $userId);
        if ($error !== null) {
            return $this->mutationFailure($userId, [$error]);
        }

        $password = $this->raw($input, 'new_password');
        if ($password !== $this->raw($input, 'new_password_confirmation')) {
            return $this->mutationFailure($userId, ['La confirmación de contraseña no coincide.']);
        }

        try {
            $this->changePassword->handle($userId, $password);
        } catch (InvalidRepresentativePassword|InvalidInstitutionalReportingPassword) {
            return $this->mutationFailure($userId, ['La contraseña debe tener al menos cinco caracteres.']);
        } catch (AdministrativeUserNotFound|RepresentativeNotFound|RepresentativeUserNotFound) {
            return $this->indexFailure('No se encontró el usuario solicitado.');
        } catch (AdministrativeUserOperationNotAllowed|InstitutionalReportingAccessConflict) {
            return $this->mutationFailure($userId, ['No se permite reemplazar la contraseña de esta cuenta.']);
        }

        $this->session->put(self::FLASH_SUCCESS_KEY, 'Contraseña reemplazada correctamente.');

        return $this->redirect($this->manageUrl($userId), 303);
    }

    public function changeLoginIdentifier(): string
    {
        $input = (new Request())->input();
        $userId = $this->trustedUserId();
        $error = $this->mutationContextError($input, $userId);
        $loginIdentifier = $this->scalar($input, 'login_identifier');
        if ($error !== null) {
            return $this->mutationFailure($userId, [$error], $loginIdentifier);
        }

        try {
            $changed = $this->changeLoginIdentifier->handle($userId, $loginIdentifier);
        } catch (AdministrativeLoginIdentifierAlreadyUsed) {
            return $this->mutationFailure($userId, ['Ese identificador de acceso ya está en uso.'], $loginIdentifier);
        } catch (InvalidUserState) {
            return $this->mutationFailure($userId, ['El identificador de acceso no es válido.'], $loginIdentifier);
        } catch (AdministrativeUserNotFound) {
            return $this->indexFailure('No se encontró el usuario solicitado.');
        } catch (AdministrativeUserOperationNotAllowed|InstitutionalReportingAccessConflict) {
            return $this->mutationFailure($userId, ['No se permite modificar el identificador de esta cuenta.']);
        }

        $this->session->put(
            self::FLASH_SUCCESS_KEY,
            $changed ? 'Identificador de acceso actualizado correctamente.' : 'El identificador no cambió.',
        );

        return $this->redirect($this->manageUrl($userId), 303);
    }

    private function renderIndex(
        ?AdministrativeUserSearchCriteria $criteria,
        string $criterion,
        string $value,
        array $errors = [],
        int $status = 200,
    ): string {
        http_response_code($status);

        return $this->view('admin-users.index', [
            'title' => 'Usuarios',
            'result' => $this->searchUsers->handle($criteria),
            'criterion' => $criterion,
            'searchValue' => $value,
            'errors' => $errors,
            'csrfToken' => $this->csrf->token(),
            'successMessage' => $this->flash(self::FLASH_SUCCESS_KEY),
            'errorMessage' => $this->flash(self::FLASH_ERROR_KEY),
        ]);
    }

    private function renderManage(
        int $userId,
        array $errors = [],
        int $status = 200,
        string $loginIdentifier = '',
    ): string {
        try {
            $user = $this->getUser->handle($userId);
        } catch (AdministrativeUserNotFound) {
            return $this->indexFailure('No se encontró el usuario solicitado.');
        }

        $this->session->put(self::TRUSTED_USER_ID_KEY, $userId);
        $this->preventCaching();
        http_response_code($status);

        return $this->view('admin-users.manage', [
            'title' => 'Administrar usuario',
            'user' => $user,
            'errors' => $errors,
            'loginIdentifier' => $loginIdentifier,
            'csrfToken' => $this->csrf->token(),
            'successMessage' => $this->flash(self::FLASH_SUCCESS_KEY),
            'errorMessage' => $this->flash(self::FLASH_ERROR_KEY),
        ]);
    }

    private function renderIndexOrRedirect(): string
    {
        return $this->redirect('/admin/users', 303);
    }

    private function indexFailure(string $message): string
    {
        $this->session->put(self::FLASH_ERROR_KEY, $message);

        return $this->renderIndexOrRedirect();
    }

    private function mutationFailure(?int $userId, array $errors, string $loginIdentifier = ''): string
    {
        if ($userId === null) {
            return $this->indexFailure('La selección de usuario caducó. Abre el usuario nuevamente.');
        }

        return $this->renderManage($userId, $errors, 422, $loginIdentifier);
    }

    private function mutationContextError(array $input, ?int $userId): ?string
    {
        if (!$this->csrf->isValid($this->scalar($input, '_csrf_token'))) {
            return 'El formulario caducó. Abre nuevamente la administración del usuario.';
        }
        if ($userId === null) {
            return 'La selección de usuario caducó.';
        }
        if ($this->positiveInteger($input['user_id'] ?? null) !== $userId) {
            return 'No se puede cambiar la identidad del usuario.';
        }

        return null;
    }

    private function trustedUserId(): ?int
    {
        $value = $this->session->pull(self::TRUSTED_USER_ID_KEY);

        return is_int($value) && $value > 0 ? $value : null;
    }

    private function flash(string $key): ?string
    {
        $value = $this->session->pull($key);

        return is_string($value) ? $value : null;
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (!is_int($value) && !is_string($value)) {
            return null;
        }
        $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return is_int($parsed) ? $parsed : null;
    }

    private function scalar(array $input, string $key): string
    {
        return trim($this->raw($input, $key));
    }

    private function raw(array $input, string $key): string
    {
        $value = $input[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }

    private function manageUrl(int $userId): string
    {
        return '/admin/users/manage?user_id=' . $userId;
    }

    private function preventCaching(): void
    {
        header('Cache-Control: no-store');
    }

    private function redirect(string $location, int $status = 302): string
    {
        header('Location: ' . $location);
        http_response_code($status);

        return '';
    }
}
