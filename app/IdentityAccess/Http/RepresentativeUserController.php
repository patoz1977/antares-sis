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
                'Enter a valid positive Representative ID.'
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
                ['Your form expired. Open the Representative User form again.'],
            );
        }

        $postedId = $this->positiveInteger($input['representative_id'] ?? null);
        if ($trustedId === null) {
            return $this->expiredSession();
        }
        if ($postedId !== $trustedId) {
            return $this->formFailure(
                $trustedId,
                ['Representative identity cannot be changed.'],
            );
        }

        $password = $this->rawScalar($input, 'password');
        $confirmation = $this->rawScalar($input, 'password_confirmation');
        $statusValue = $this->trimmedScalar($input, 'status');
        $status = UserStatus::tryFrom($statusValue);
        $errors = [];
        if ($password !== $confirmation) {
            $errors[] = 'Password confirmation does not match.';
        }
        if ($status === null) {
            $errors[] = 'Select a valid User status.';
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
                ['The Representative Person could not be resolved.'],
                $statusValue,
            );
        } catch (RepresentativeUserRequiresIdentification) {
            return $this->formFailure(
                $trustedId,
                ['Representative User requires complete Person identification.'],
                $statusValue,
            );
        } catch (RepresentativeRequiresContactEmail) {
            return $this->formFailure(
                $trustedId,
                ['Representative User requires a Person personal email.'],
                $statusValue,
            );
        } catch (RepresentativeUserAlreadyExists) {
            return $this->formFailure(
                $trustedId,
                ['This Representative already has a User.'],
                $statusValue,
            );
        } catch (RepresentativeLoginIdentifierAlreadyUsed) {
            return $this->formFailure(
                $trustedId,
                ['That Representative username is already in use.'],
                $statusValue,
            );
        } catch (InvalidRepresentativePassword) {
            return $this->formFailure(
                $trustedId,
                ['Password must contain at least five characters.'],
                $statusValue,
            );
        }

        $this->session->put(self::FLASH_SUCCESS_KEY, 'Representative User created successfully.');

        return $this->redirect($this->manageUrl($trustedId), 303);
    }

    public function changePassword(): string
    {
        $input = (new Request())->input();
        $trustedId = $this->trustedRepresentativeId();

        if (!$this->csrf->isValid($this->trimmedScalar($input, '_csrf_token'))) {
            return $this->formFailure(
                $trustedId,
                ['Your form expired. Open the Representative User form again.'],
            );
        }

        $postedId = $this->positiveInteger($input['representative_id'] ?? null);
        if ($trustedId === null) {
            return $this->expiredSession();
        }
        if ($postedId !== $trustedId) {
            return $this->formFailure(
                $trustedId,
                ['Representative identity cannot be changed.'],
            );
        }

        $password = $this->rawScalar($input, 'new_password');
        $confirmation = $this->rawScalar($input, 'new_password_confirmation');
        if ($password !== $confirmation) {
            return $this->formFailure(
                $trustedId,
                ['Password confirmation does not match.'],
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
                ['Representative User was not found.'],
            );
        } catch (InvalidRepresentativePassword) {
            return $this->formFailure(
                $trustedId,
                ['Password must contain at least five characters.'],
            );
        }

        $this->session->put(
            self::FLASH_SUCCESS_KEY,
            'Representative User password changed successfully.'
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
            'title' => 'Manage Representative User',
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
            'title' => 'Representative User session expired',
        ]);
    }

    private function notFound(): string
    {
        http_response_code(404);

        return $this->view('representative-users.not-found', [
            'title' => 'Representative not found',
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
