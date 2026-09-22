<?php

declare(strict_types=1);

namespace App\Enrollment\Http;

use App\Shared\Http\SafeErrorPage;
use App\Controllers\Controller;
use App\Enrollment\Application\Administrative\CancelEnrollment;
use App\Enrollment\Application\Administrative\CompleteEnrollment;
use App\Enrollment\Application\Administrative\Exception\AdministrativeEnrollmentInvalidTransition;
use App\Enrollment\Application\Administrative\Exception\AdministrativeEnrollmentNotSubmitted;
use App\Enrollment\Application\Administrative\Exception\AdministrativeEnrollmentPersistedStateMismatch;
use App\Enrollment\Application\Administrative\Exception\AdministrativeEnrollmentUnavailable;
use App\Enrollment\Application\Administrative\GetAdministrativeEnrollmentReviewContext;
use App\Enrollment\Application\Administrative\ListSubmittedEnrollments;
use App\Enrollment\Application\Administrative\ReopenEnrollment;
use App\IdentityAccess\Application\Contract\CsrfTokenManager;
use App\IdentityAccess\Application\Contract\SessionManager;
use Core\Http\Request;
use Throwable;

final class AdministrativeEnrollmentController extends Controller
{
    private const FLASH_SUCCESS_KEY = '_flash_enrollment_administration_success';
    private const FLASH_ERROR_KEY = '_flash_enrollment_administration_error';

    public function __construct(
        private readonly ListSubmittedEnrollments $listSubmittedEnrollments,
        private readonly GetAdministrativeEnrollmentReviewContext $getReviewContext,
        private readonly ReopenEnrollment $reopenEnrollment,
        private readonly CompleteEnrollment $completeEnrollment,
        private readonly CancelEnrollment $cancelEnrollment,
        private readonly CsrfTokenManager $csrf,
        private readonly SessionManager $session,
    ) {
    }

    public function index(): string
    {
        try {
            $items = $this->listSubmittedEnrollments->handle();
        } catch (Throwable) {
            return $this->plainError('La administración de matrículas no está disponible.', 500);
        }

        return $this->view('enrollments.index', [
            'title' => 'Administración de matrículas',
            'items' => $items,
            'successMessage' => $this->flash(self::FLASH_SUCCESS_KEY),
            'errorMessage' => $this->flash(self::FLASH_ERROR_KEY),
        ]);
    }

    public function review(): string
    {
        $enrollmentId = $this->positiveInteger((new Request())->query()['id'] ?? null);
        if ($enrollmentId === null) {
            return $this->plainError('La matrícula no está disponible.', 404);
        }

        try {
            $context = $this->getReviewContext->handle($enrollmentId);
        } catch (AdministrativeEnrollmentUnavailable | AdministrativeEnrollmentNotSubmitted) {
            return $this->plainError('La matrícula no está disponible.', 404);
        } catch (Throwable) {
            return $this->plainError('La revisión de la matrícula no está disponible.', 500);
        }

        return $this->view('enrollments.review', [
            'title' => 'Revisión administrativa de matrícula',
            'context' => $context,
            'csrfToken' => $this->csrf->token(),
        ]);
    }

    public function reopen(): string
    {
        return $this->mutate(
            fn (int $id): mixed => $this->reopenEnrollment->handle($id),
            'Matrícula reabierta correctamente.',
        );
    }

    public function complete(): string
    {
        return $this->mutate(
            fn (int $id): mixed => $this->completeEnrollment->handle($id),
            'Matrícula completada correctamente.',
        );
    }

    public function cancel(): string
    {
        return $this->mutate(
            fn (int $id): mixed => $this->cancelEnrollment->handle($id),
            'Matrícula cancelada correctamente.',
        );
    }

    /** @param callable(int): mixed $operation */
    private function mutate(callable $operation, string $successMessage): string
    {
        $input = (new Request())->input();
        if (!$this->csrf->isValid($this->scalar($input, '_csrf_token'))) {
            return $this->plainError('No se pudo verificar la solicitud.', 403);
        }
        if (!$this->containsOnlyLifecycleFields($input)) {
            return $this->plainError('La solicitud de matrícula no es válida.', 422);
        }

        $enrollmentId = $this->positiveInteger($input['enrollment_id'] ?? null);
        if ($enrollmentId === null) {
            return $this->plainError('La solicitud de matrícula no es válida.', 422);
        }

        try {
            $operation($enrollmentId);
        } catch (AdministrativeEnrollmentInvalidTransition) {
            $this->session->put(
                self::FLASH_ERROR_KEY,
                'El estado de la matrícula cambió. Revise la lista actual.',
            );

            return $this->redirectToList();
        } catch (AdministrativeEnrollmentUnavailable) {
            return $this->plainError('La matrícula no está disponible.', 404);
        } catch (AdministrativeEnrollmentPersistedStateMismatch) {
            return $this->plainError('No se pudo confirmar el cambio de estado de la matrícula.', 500);
        } catch (Throwable) {
            return $this->plainError('El cambio de estado de la matrícula no está disponible.', 500);
        }

        $this->session->put(self::FLASH_SUCCESS_KEY, $successMessage);

        return $this->redirectToList();
    }

    /** @param array<mixed> $input */
    private function containsOnlyLifecycleFields(array $input): bool
    {
        $keys = array_keys($input);
        sort($keys, SORT_STRING);

        return $keys === ['_csrf_token', 'enrollment_id'];
    }

    /** @param array<mixed> $input */
    private function scalar(array $input, string $key): string
    {
        $value = $input[$key] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (!is_int($value) && !is_string($value)) {
            return null;
        }
        $validated = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return is_int($validated) ? $validated : null;
    }

    private function flash(string $key): ?string
    {
        $message = $this->session->pull($key);

        return is_string($message) ? $message : null;
    }

    private function redirectToList(): string
    {
        header('Location: /enrollments');
        http_response_code(303);

        return '';
    }

    private function plainError(string $message, int $status): string
    {
        http_response_code($status);

        return SafeErrorPage::render($status, $message, '/enrollments', 'Volver a matrículas');
    }
}
