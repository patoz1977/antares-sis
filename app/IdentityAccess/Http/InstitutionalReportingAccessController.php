<?php

declare(strict_types=1);

namespace App\IdentityAccess\Http;

use App\Controllers\Controller;
use App\IdentityAccess\Application\DisableInstitutionalReportingUser;
use App\IdentityAccess\Application\Dto\InstitutionalReportingAccessState;
use App\IdentityAccess\Application\Dto\ProvisionInstitutionalReportingUserInput;
use App\IdentityAccess\Application\Exception\InstitutionalReportingAccessConflict;
use App\IdentityAccess\Application\Exception\InstitutionalReportingLoginIdentifierAlreadyUsed;
use App\IdentityAccess\Application\Exception\InstitutionalReportingReservedLoginIdentifier;
use App\IdentityAccess\Application\Exception\InvalidInstitutionalReportingPassword;
use App\IdentityAccess\Application\GetInstitutionalReportingAccessState;
use App\IdentityAccess\Application\GrantInstitutionalReportingAccessToExistingUser;
use App\IdentityAccess\Application\ProvisionInstitutionalReportingUser;
use App\IdentityAccess\Application\ReactivateInstitutionalReportingUser;
use App\IdentityAccess\Application\Contract\CsrfTokenManager;
use App\IdentityAccess\Application\Contract\SessionManager;
use App\IdentityAccess\Domain\Exception\InvalidUserState;
use Core\Http\Request;

final class InstitutionalReportingAccessController extends Controller
{
    private const TRUSTED_PERSON_ID_KEY = '_institutional_reporting_person_id';
    private const FLASH_SUCCESS_KEY = '_flash_institutional_reporting_success';
    private const FLASH_ERROR_KEY = '_flash_institutional_reporting_error';

    public function __construct(
        private readonly GetInstitutionalReportingAccessState $getState,
        private readonly GrantInstitutionalReportingAccessToExistingUser $grantExisting,
        private readonly ProvisionInstitutionalReportingUser $provisionNew,
        private readonly DisableInstitutionalReportingUser $disableUser,
        private readonly ReactivateInstitutionalReportingUser $reactivateUser,
        private readonly CsrfTokenManager $csrf,
        private readonly SessionManager $session,
    ) {
    }

    public function showManage(): string
    {
        $personId = $this->positiveInteger((new Request())->query()['person_id'] ?? null);
        if ($personId === null) {
            $this->session->put(self::FLASH_ERROR_KEY, 'Selecciona una persona válida.');

            return $this->redirect('/persons');
        }

        return $this->renderManage($personId);
    }

    public function provision(): string
    {
        $input = (new Request())->input();
        $personId = $this->trustedPersonId();
        $contextError = $this->validateMutationContext($input, $personId);
        if ($contextError !== null) {
            return $this->formFailure($personId, [$contextError]);
        }

        try {
            $state = $this->getState->handle($personId);
            if ($state->state === InstitutionalReportingAccessState::ELIGIBLE_NO_USER) {
                $password = $this->rawScalar($input, 'password');
                if ($password !== $this->rawScalar($input, 'password_confirmation')) {
                    return $this->formFailure(
                        $personId,
                        ['La confirmación de contraseña no coincide.'],
                        $this->trimmedScalar($input, 'login_identifier'),
                    );
                }
                $this->provisionNew->handle(new ProvisionInstitutionalReportingUserInput(
                    $personId,
                    $this->trimmedScalar($input, 'login_identifier'),
                    $password,
                ));
                $message = 'Acceso institucional a reportes creado correctamente.';
            } elseif ($state->state === InstitutionalReportingAccessState::ELIGIBLE_ACTIVE_USER
                || $state->state === InstitutionalReportingAccessState::ACTIVE_DEDICATED
            ) {
                $changed = $this->grantExisting->handle($personId);
                $message = $changed
                    ? 'Acceso institucional a reportes asignado correctamente.'
                    : 'El acceso institucional a reportes ya estaba activo.';
            } else {
                throw new InstitutionalReportingAccessConflict('Person is not eligible for provisioning.');
            }
        } catch (InstitutionalReportingLoginIdentifierAlreadyUsed|InstitutionalReportingReservedLoginIdentifier) {
            return $this->formFailure(
                $personId,
                ['Ese identificador de acceso ya está en uso.'],
                $this->trimmedScalar($input, 'login_identifier'),
            );
        } catch (InvalidInstitutionalReportingPassword) {
            return $this->formFailure(
                $personId,
                ['La contraseña debe tener al menos cinco caracteres.'],
                $this->trimmedScalar($input, 'login_identifier'),
            );
        } catch (InvalidUserState) {
            return $this->formFailure(
                $personId,
                ['El identificador de acceso no es válido.'],
                $this->trimmedScalar($input, 'login_identifier'),
            );
        } catch (InstitutionalReportingAccessConflict) {
            return $this->formFailure(
                $personId,
                ['No se pudo confirmar la elegibilidad actual de la persona.'],
            );
        }

        $this->session->put(self::FLASH_SUCCESS_KEY, $message);

        return $this->redirect($this->manageUrl($personId), 303);
    }

    public function disable(): string
    {
        return $this->lifecycleMutation(
            fn (int $personId): bool => $this->disableUser->handle($personId),
            'El acceso institucional a reportes fue deshabilitado.',
            'El acceso institucional a reportes ya estaba deshabilitado.',
        );
    }

    public function reactivate(): string
    {
        return $this->lifecycleMutation(
            fn (int $personId): bool => $this->reactivateUser->handle($personId),
            'El acceso institucional a reportes fue reactivado.',
            'El acceso institucional a reportes ya estaba activo.',
        );
    }

    private function lifecycleMutation(callable $operation, string $success, string $noOp): string
    {
        $input = (new Request())->input();
        $personId = $this->trustedPersonId();
        $contextError = $this->validateMutationContext($input, $personId);
        if ($contextError !== null) {
            return $this->formFailure($personId, [$contextError]);
        }

        try {
            $changed = $operation($personId);
        } catch (InstitutionalReportingAccessConflict) {
            return $this->formFailure(
                $personId,
                ['No se pudo confirmar el acceso institucional solicitado.'],
            );
        }

        $this->session->put(self::FLASH_SUCCESS_KEY, $changed ? $success : $noOp);

        return $this->redirect($this->manageUrl($personId), 303);
    }

    private function validateMutationContext(array $input, ?int $personId): ?string
    {
        if (!$this->csrf->isValid($this->trimmedScalar($input, '_csrf_token'))) {
            return 'El formulario caducó. Abre nuevamente la administración del acceso.';
        }
        if ($personId === null) {
            return 'La selección de persona caducó. Abre la persona nuevamente.';
        }
        if ($this->positiveInteger($input['person_id'] ?? null) !== $personId) {
            return 'No se puede cambiar la identidad de la persona.';
        }

        return null;
    }

    private function renderManage(
        int $personId,
        array $errors = [],
        int $status = 200,
        string $loginIdentifier = '',
    ): string {
        try {
            $state = $this->getState->handle($personId);
        } catch (InstitutionalReportingAccessConflict) {
            http_response_code(404);

            return $this->view('institutional-reporting-access.not-found', [
                'title' => 'Persona no encontrada',
            ]);
        }

        $this->session->put(self::TRUSTED_PERSON_ID_KEY, $personId);
        header('Cache-Control: no-store');
        http_response_code($status);

        return $this->view('institutional-reporting-access.manage', [
            'title' => 'Acceso institucional a reportes',
            'state' => $state,
            'errors' => $errors,
            'loginIdentifier' => $loginIdentifier,
            'csrfToken' => $this->csrf->token(),
            'successMessage' => $this->flash(self::FLASH_SUCCESS_KEY),
            'errorMessage' => $this->flash(self::FLASH_ERROR_KEY),
        ]);
    }

    private function formFailure(?int $personId, array $errors, string $loginIdentifier = ''): string
    {
        if ($personId === null) {
            http_response_code(422);

            return $this->view('institutional-reporting-access.session-expired', [
                'title' => 'Selección de persona caducada',
            ]);
        }

        return $this->renderManage($personId, $errors, 422, $loginIdentifier);
    }

    private function trustedPersonId(): ?int
    {
        $value = $this->session->pull(self::TRUSTED_PERSON_ID_KEY);

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
        $result = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return is_int($result) ? $result : null;
    }

    private function rawScalar(array $input, string $key): string
    {
        $value = $input[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }

    private function trimmedScalar(array $input, string $key): string
    {
        return trim($this->rawScalar($input, $key));
    }

    private function manageUrl(int $personId): string
    {
        return '/institutional-reporting-access/manage?person_id=' . $personId;
    }

    private function redirect(string $location, int $status = 302): string
    {
        header('Location: ' . $location);
        http_response_code($status);

        return '';
    }
}
