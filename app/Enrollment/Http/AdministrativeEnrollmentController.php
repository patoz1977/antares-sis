<?php

declare(strict_types=1);

namespace App\Enrollment\Http;

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
            return $this->plainError('Enrollment administration is unavailable.', 500);
        }

        return $this->view('enrollments.index', [
            'title' => 'Enrollment Administration',
            'items' => $items,
            'successMessage' => $this->flash(self::FLASH_SUCCESS_KEY),
            'errorMessage' => $this->flash(self::FLASH_ERROR_KEY),
        ]);
    }

    public function review(): string
    {
        $enrollmentId = $this->positiveInteger((new Request())->query()['id'] ?? null);
        if ($enrollmentId === null) {
            return $this->plainError('Enrollment is unavailable.', 404);
        }

        try {
            $context = $this->getReviewContext->handle($enrollmentId);
        } catch (AdministrativeEnrollmentUnavailable | AdministrativeEnrollmentNotSubmitted) {
            return $this->plainError('Enrollment is unavailable.', 404);
        } catch (Throwable) {
            return $this->plainError('Enrollment review is unavailable.', 500);
        }

        return $this->view('enrollments.review', [
            'title' => 'Administrative Enrollment Review',
            'context' => $context,
            'csrfToken' => $this->csrf->token(),
        ]);
    }

    public function reopen(): string
    {
        return $this->mutate(
            fn (int $id): mixed => $this->reopenEnrollment->handle($id),
            'Enrollment reopened successfully.',
        );
    }

    public function complete(): string
    {
        return $this->mutate(
            fn (int $id): mixed => $this->completeEnrollment->handle($id),
            'Enrollment completed successfully.',
        );
    }

    public function cancel(): string
    {
        return $this->mutate(
            fn (int $id): mixed => $this->cancelEnrollment->handle($id),
            'Enrollment cancelled successfully.',
        );
    }

    /** @param callable(int): mixed $operation */
    private function mutate(callable $operation, string $successMessage): string
    {
        $input = (new Request())->input();
        if (!$this->csrf->isValid($this->scalar($input, '_csrf_token'))) {
            return $this->plainError('The request could not be verified.', 403);
        }
        if (!$this->containsOnlyLifecycleFields($input)) {
            return $this->plainError('The Enrollment request is invalid.', 422);
        }

        $enrollmentId = $this->positiveInteger($input['enrollment_id'] ?? null);
        if ($enrollmentId === null) {
            return $this->plainError('The Enrollment request is invalid.', 422);
        }

        try {
            $operation($enrollmentId);
        } catch (AdministrativeEnrollmentInvalidTransition) {
            $this->session->put(
                self::FLASH_ERROR_KEY,
                'Enrollment status changed. Review the current queue.',
            );

            return $this->redirectToList();
        } catch (AdministrativeEnrollmentUnavailable) {
            return $this->plainError('Enrollment is unavailable.', 404);
        } catch (AdministrativeEnrollmentPersistedStateMismatch) {
            return $this->plainError('Enrollment lifecycle operation could not be confirmed.', 500);
        } catch (Throwable) {
            return $this->plainError('Enrollment lifecycle operation is unavailable.', 500);
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

        return '<h1>Enrollment administration unavailable</h1><p role="alert">'
            . htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
            . '</p><p><a href="/enrollments">Back to Enrollment administration</a></p>';
    }
}
