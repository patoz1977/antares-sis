<?php

declare(strict_types=1);

namespace App\IdentityAccess\Http;

use App\Controllers\Controller;
use App\IdentityAccess\Application\ChangeRepresentativeUserPassword;
use App\IdentityAccess\Application\Contract\CsrfTokenManager;
use App\IdentityAccess\Application\Contract\SessionManager;
use App\IdentityAccess\Application\CreateRepresentativeUser;
use App\IdentityAccess\Application\Dto\ChangeRepresentativeUserPasswordInput;
use App\IdentityAccess\Application\Dto\CreateRepresentativeUserInput;
use App\IdentityAccess\Application\Exception\InvalidRepresentativePassword;
use App\IdentityAccess\Application\Exception\RepresentativeLoginIdentifierAlreadyUsed;
use App\IdentityAccess\Application\Exception\RepresentativeUserAlreadyExists;
use App\IdentityAccess\Application\Exception\RepresentativeUserNotFound;
use App\IdentityAccess\Application\Exception\RepresentativeUserPersonNotFound;
use App\IdentityAccess\Application\Exception\RepresentativeUserRequiresIdentification;
use App\IdentityAccess\Application\GetUserByPersonId;
use App\IdentityAccess\Domain\UserStatus;
use App\Person\Application\Exception\PersonNotFound;
use App\Person\Application\GetPerson;
use App\Representative\Application\Exception\RepresentativeNotFound;
use App\Representative\Application\Exception\RepresentativeRequiresContactEmail;
use App\Representative\Application\GetRepresentative;
use Core\Http\Request;

final class RepresentativeUserController extends Controller
{
    private const TRUSTED_REPRESENTATIVE_ID_KEY = '_representative_user_manage_id';
    private const FLASH_SUCCESS_KEY = '_flash_representative_user_success';
    private const FLASH_ERROR_KEY = '_flash_representative_user_error';

    public function __construct(
        private readonly GetRepresentative $getRepresentative,
        private readonly GetPerson $getPerson,
        private readonly GetUserByPersonId $getUserByPersonId,
        private readonly CreateRepresentativeUser $createRepresentativeUser,
        private readonly ChangeRepresentativeUserPassword $changeRepresentativeUserPassword,
        private readonly CsrfTokenManager $csrf,
        private readonly SessionManager $session,
    ) {
    }

    public function showManage(): string
    {
        $representativeId = $this->positiveInteger(
            (new Request())->query()['representative_id'] ?? null
        );
        if ($representativeId === null) {
            $this->session->put(
                self::FLASH_ERROR_KEY,
                'Ingresa un identificador válido de representante.'
            );

            return $this->redirect('/families');
        }

        return $this->renderManage($representativeId);
    }

    public function create(): string
    {
        $input = (new Request())->input();
        $trustedId = $this->trustedRepresentativeId();

        if (!$this->csrf->isValid($this->trimmedScalar($input, '_csrf_token'))) {
            return $this->formFailure(
                $trustedId,
                ['El formulario caducó. Abre nuevamente la administración del usuario.'],
            );
        }

        $postedId = $this->positiveInteger($input['representative_id'] ?? null);
        if ($trustedId === null) {
            return $this->expiredSession();
        }
        if ($postedId !== $trustedId) {
            return $this->formFailure(
                $trustedId,
                ['No se puede cambiar la identidad del representante.'],
            );
        }

        $password = $this->rawScalar($input, 'password');
        $confirmation = $this->rawScalar($input, 'password_confirmation');
        $statusValue = $this->trimmedScalar($input, 'status');
        $status = UserStatus::tryFrom($statusValue);
        $errors = [];
        if ($password !== $confirmation) {
            $errors[] = 'La confirmación de contraseña no coincide.';
        }
        if ($status === null) {
            $errors[] = 'Selecciona un estado de usuario válido.';
        }
        if ($errors !== []) {
            return $this->formFailure($trustedId, $errors, $statusValue);
        }

        try {
            $this->createRepresentativeUser->handle(new CreateRepresentativeUserInput(
                $trustedId,
                $password,
                $status,
            ));
        } catch (RepresentativeNotFound) {
            return $this->notFound();
        } catch (RepresentativeUserPersonNotFound) {
            return $this->formFailure(
                $trustedId,
                ['No se pudo encontrar la persona del representante.'],
                $statusValue,
            );
        } catch (RepresentativeUserRequiresIdentification) {
            return $this->formFailure(
                $trustedId,
                ['El usuario representante requiere la identificación completa de la persona.'],
                $statusValue,
            );
        } catch (RepresentativeRequiresContactEmail) {
            return $this->formFailure(
                $trustedId,
                ['El usuario representante requiere un correo personal.'],
                $statusValue,
            );
        } catch (RepresentativeUserAlreadyExists) {
            return $this->formFailure(
                $trustedId,
                ['Este representante ya tiene un usuario.'],
                $statusValue,
            );
        } catch (RepresentativeLoginIdentifierAlreadyUsed) {
            return $this->formFailure(
                $trustedId,
                ['Ese nombre de usuario ya está en uso.'],
                $statusValue,
            );
        } catch (InvalidRepresentativePassword) {
            return $this->formFailure(
                $trustedId,
                ['La contraseña debe tener al menos cinco caracteres.'],
                $statusValue,
            );
        }

        $this->session->put(self::FLASH_SUCCESS_KEY, 'Usuario representante creado correctamente.');

        return $this->redirect($this->manageUrl($trustedId), 303);
    }

    public function changePassword(): string
    {
        $input = (new Request())->input();
        $trustedId = $this->trustedRepresentativeId();

        if (!$this->csrf->isValid($this->trimmedScalar($input, '_csrf_token'))) {
            return $this->formFailure(
                $trustedId,
                ['El formulario caducó. Abre nuevamente la administración del usuario.'],
            );
        }

        $postedId = $this->positiveInteger($input['representative_id'] ?? null);
        if ($trustedId === null) {
            return $this->expiredSession();
        }
        if ($postedId !== $trustedId) {
            return $this->formFailure(
                $trustedId,
                ['No se puede cambiar la identidad del representante.'],
            );
        }

        $password = $this->rawScalar($input, 'new_password');
        $confirmation = $this->rawScalar($input, 'new_password_confirmation');
        if ($password !== $confirmation) {
            return $this->formFailure(
                $trustedId,
                ['La confirmación de contraseña no coincide.'],
            );
        }

        try {
            $this->changeRepresentativeUserPassword->handle(
                new ChangeRepresentativeUserPasswordInput($trustedId, $password)
            );
        } catch (RepresentativeNotFound) {
            return $this->notFound();
        } catch (RepresentativeUserNotFound) {
            return $this->formFailure(
                $trustedId,
                ['No se encontró el usuario representante.'],
            );
        } catch (InvalidRepresentativePassword) {
            return $this->formFailure(
                $trustedId,
                ['La contraseña debe tener al menos cinco caracteres.'],
            );
        }

        $this->session->put(
            self::FLASH_SUCCESS_KEY,
            'Contraseña del usuario representante reemplazada correctamente.'
        );

        return $this->redirect($this->manageUrl($trustedId), 303);
    }

    private function renderManage(
        int $representativeId,
        array $errors = [],
        int $status = 200,
        string $selectedStatus = 'ACTIVE',
    ): string {
        try {
            $representative = $this->getRepresentative->handle($representativeId);
            $person = $this->getPerson->handle($representative->personId);
            $user = $this->getUserByPersonId->handle($representative->personId);
        } catch (RepresentativeNotFound|PersonNotFound) {
            return $this->notFound();
        }

        $this->session->put(self::TRUSTED_REPRESENTATIVE_ID_KEY, $representativeId);
        http_response_code($status);

        return $this->view('representative-users.manage', [
            'title' => 'Administrar usuario representante',
            'representative' => $representative,
            'person' => $person,
            'user' => $user,
            'errors' => $errors,
            'selectedStatus' => UserStatus::tryFrom($selectedStatus) ?? UserStatus::Active,
            'csrfToken' => $this->csrf->token(),
            'successMessage' => $this->flashMessage(self::FLASH_SUCCESS_KEY),
            'errorMessage' => $this->flashMessage(self::FLASH_ERROR_KEY),
        ]);
    }

    private function formFailure(
        ?int $representativeId,
        array $errors,
        string $selectedStatus = 'ACTIVE',
    ): string {
        if ($representativeId === null) {
            return $this->expiredSession();
        }

        return $this->renderManage($representativeId, $errors, 422, $selectedStatus);
    }

    private function expiredSession(): string
    {
        http_response_code(422);

        return $this->view('representative-users.session-expired', [
            'title' => 'Sesión de usuario representante caducada',
        ]);
    }

    private function notFound(): string
    {
        http_response_code(404);

        return $this->view('representative-users.not-found', [
            'title' => 'Representante no encontrado',
        ]);
    }

    private function trustedRepresentativeId(): ?int
    {
        $value = $this->session->pull(self::TRUSTED_REPRESENTATIVE_ID_KEY);

        return is_int($value) && $value > 0 ? $value : null;
    }

    private function flashMessage(string $key): ?string
    {
        $message = $this->session->pull($key);

        return is_string($message) ? $message : null;
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (!is_int($value) && !is_string($value)) {
            return null;
        }

        $validated = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return is_int($validated) ? $validated : null;
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

    private function manageUrl(int $representativeId): string
    {
        return '/representative-users/manage?representative_id=' . $representativeId;
    }

    private function redirect(string $location, int $status = 302): string
    {
        header('Location: ' . $location);
        http_response_code($status);

        return '';
    }
}
