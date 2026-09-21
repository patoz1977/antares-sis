<?php

declare(strict_types=1);

namespace App\Enrollment\Http;

use App\Shared\Http\SafeErrorPage;
use App\Controllers\Controller;
use App\Enrollment\Application\RepresentativePortal\Exception\RepresentativeEnrollmentContextUnavailable;
use App\Enrollment\Application\RepresentativePortal\Exception\RepresentativeEnrollmentFamilySelectionRequired;
use App\Enrollment\Application\RepresentativePortal\Exception\RepresentativeEnrollmentStudentUnavailable;
use App\Enrollment\Application\Submission\Dto\SubmitRepresentativeEnrollmentInput;
use App\Enrollment\Application\Submission\Exception\EnrollmentSubmissionContextUnavailable;
use App\Enrollment\Application\Submission\Exception\EnrollmentSubmissionNotReady;
use App\Enrollment\Application\Submission\Exception\EnrollmentSubmissionPersistedStateMismatch;
use App\Enrollment\Application\Submission\GetRepresentativeEnrollmentSubmissionReview;
use App\Enrollment\Application\Submission\SubmitRepresentativeEnrollment;
use App\IdentityAccess\Application\Contract\CsrfTokenManager;
use App\IdentityAccess\Application\Contract\SessionManager;
use App\InstitutionalDocuments\Application\RepresentativePortal\GetRepresentativeAcknowledgementPortalState;
use Core\Http\Request;
use Throwable;

final class RepresentativeEnrollmentSubmissionController extends Controller
{
    private const FLASH_SUCCESS_KEY = '_flash_representative_enrollment_submission_success';
    private const FLASH_ERROR_KEY = '_flash_representative_enrollment_submission_error';

    public function __construct(
        private readonly GetRepresentativeEnrollmentSubmissionReview $getReview,
        private readonly SubmitRepresentativeEnrollment $submitEnrollment,
        private readonly RepresentativeEnrollmentSubmissionViewDataFactory $viewData,
        private readonly GetRepresentativeAcknowledgementPortalState $getAcknowledgementState,
        private readonly CsrfTokenManager $csrf,
        private readonly SessionManager $session,
    ) {
    }

    public function review(): string
    {
        $studentId = $this->positiveInteger((new Request())->query()['student_id'] ?? null);
        if ($studentId === null) {
            return $this->error('Seleccione un estudiante antes de revisar su matrícula.', 422);
        }

        try {
            $review = $this->getReview->handle($studentId);
            $presentation = $this->viewData->make($review);
            $presentation['acknowledgementRequirements'] = $this->getAcknowledgementState
                ->handle()->activeRequirements;
        } catch (RepresentativeEnrollmentFamilySelectionRequired) {
            return $this->redirect('/representative', 303);
        } catch (RepresentativeEnrollmentContextUnavailable|RepresentativeEnrollmentStudentUnavailable) {
            return $this->error('No tiene acceso a la revisión de esta matrícula.', 403);
        } catch (EnrollmentSubmissionContextUnavailable) {
            return $this->error('No hay un contexto de envío de matrícula activo.', 422);
        } catch (Throwable) {
            return $this->error('No se pudo cargar la revisión de matrícula.', 500);
        }

        http_response_code(200);

        return $this->view('representative-portal.enrollment-submission-review', array_merge(
            $presentation,
            [
                'title' => 'Revisar y enviar matrícula',
                'review' => $review,
                'csrfToken' => $this->csrf->token(),
                'successMessage' => $this->flash(self::FLASH_SUCCESS_KEY),
                'errorMessage' => $this->flash(self::FLASH_ERROR_KEY),
            ],
        ));
    }

    public function submit(): string
    {
        $input = (new Request())->input();
        if (!$this->csrf->isValid($this->scalar($input, '_csrf_token'))) {
            return $this->error('No se pudo verificar la solicitud.', 403);
        }

        $expectedFamilyId = $this->positiveInteger($input['expected_family_id'] ?? null);
        $expectedAcademicPeriodId = $this->positiveInteger($input['expected_academic_period_id'] ?? null);
        $studentId = $this->positiveInteger($input['student_id'] ?? null);
        if ($expectedFamilyId === null || $expectedAcademicPeriodId === null || $studentId === null) {
            return $this->error('El contexto de envío de matrícula no es válido.', 422);
        }

        $location = '/representative/enrollment/review?student_id=' . $studentId;
        try {
            $this->submitEnrollment->handle(new SubmitRepresentativeEnrollmentInput(
                $expectedFamilyId,
                $expectedAcademicPeriodId,
                $studentId,
            ));
            $this->session->put(
                self::FLASH_SUCCESS_KEY,
                'Matrícula enviada correctamente.',
            );
        } catch (EnrollmentSubmissionNotReady) {
            $this->session->put(
                self::FLASH_ERROR_KEY,
                'La matrícula aún no está lista para enviarse. Revise los requisitos actuales.',
            );
        } catch (EnrollmentSubmissionContextUnavailable) {
            return $this->error('El contexto de la matrícula cambió. Revise la matrícula actual.', 409);
        } catch (EnrollmentSubmissionPersistedStateMismatch) {
            return $this->error('No se pudo enviar la matrícula.', 422);
        } catch (Throwable) {
            return $this->error('No se pudo enviar la matrícula.', 500);
        }

        return $this->redirect($location, 303);
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (!is_int($value) && !is_string($value)) {
            return null;
        }
        $result = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return is_int($result) ? $result : null;
    }

    private function scalar(array $input, string $key): string
    {
        $value = $input[$key] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function flash(string $key): ?string
    {
        $value = $this->session->pull($key);

        return is_string($value) ? $value : null;
    }

    private function error(string $message, int $status): string
    {
        http_response_code($status);

        return SafeErrorPage::render($status, $message, '/representative/enrollment', 'Volver a matrícula');
    }

    private function redirect(string $location, int $status): string
    {
        header('Location: ' . $location);
        http_response_code($status);

        return '';
    }
}
