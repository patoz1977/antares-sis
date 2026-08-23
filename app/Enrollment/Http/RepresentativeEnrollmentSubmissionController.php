<?php

declare(strict_types=1);

namespace App\Enrollment\Http;

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
        private readonly CsrfTokenManager $csrf,
        private readonly SessionManager $session,
    ) {
    }

    public function review(): string
    {
        $studentId = $this->positiveInteger((new Request())->query()['student_id'] ?? null);
        if ($studentId === null) {
            return $this->error('Select a Student before reviewing an Enrollment.', 422);
        }

        try {
            $review = $this->getReview->handle($studentId);
            $presentation = $this->viewData->make($review);
        } catch (RepresentativeEnrollmentFamilySelectionRequired) {
            return $this->redirect('/representative', 303);
        } catch (RepresentativeEnrollmentContextUnavailable|RepresentativeEnrollmentStudentUnavailable) {
            return $this->error('Representative Enrollment review is unavailable.', 403);
        } catch (EnrollmentSubmissionContextUnavailable) {
            return $this->error('No active Enrollment Submission context is currently available.', 422);
        } catch (Throwable) {
            return $this->error('Enrollment review could not be loaded.', 500);
        }

        http_response_code(200);

        return $this->view('representative-portal.enrollment-submission-review', array_merge(
            $presentation,
            [
                'title' => 'Review and Submit Enrollment',
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
            return $this->error('The request could not be verified.', 403);
        }

        $expectedFamilyId = $this->positiveInteger($input['expected_family_id'] ?? null);
        $expectedAcademicPeriodId = $this->positiveInteger($input['expected_academic_period_id'] ?? null);
        $studentId = $this->positiveInteger($input['student_id'] ?? null);
        if ($expectedFamilyId === null || $expectedAcademicPeriodId === null || $studentId === null) {
            return $this->error('Enrollment Submission context is invalid.', 422);
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
                'Enrollment submitted successfully.',
            );
        } catch (EnrollmentSubmissionNotReady) {
            $this->session->put(
                self::FLASH_ERROR_KEY,
                'Enrollment is not ready for Submission. Review the current requirements.',
            );
        } catch (EnrollmentSubmissionContextUnavailable) {
            return $this->error('Enrollment Submission context changed. Review the current Enrollment.', 409);
        } catch (EnrollmentSubmissionPersistedStateMismatch) {
            return $this->error('Enrollment could not be submitted.', 422);
        } catch (Throwable) {
            return $this->error('Enrollment could not be submitted.', 500);
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

        return '<h1>Enrollment Submission unavailable</h1><p role="alert">'
            . htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
            . '</p><p><a href="/representative/enrollment">Back to Representative Enrollment</a></p>';
    }

    private function redirect(string $location, int $status): string
    {
        header('Location: ' . $location);
        http_response_code($status);

        return '';
    }
}
