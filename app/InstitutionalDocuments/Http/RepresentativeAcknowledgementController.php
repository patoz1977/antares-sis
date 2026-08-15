<?php

declare(strict_types=1);

namespace App\InstitutionalDocuments\Http;

use App\Controllers\Controller;
use App\IdentityAccess\Application\Contract\CsrfTokenManager;
use App\IdentityAccess\Application\Contract\SessionManager;
use App\InstitutionalDocuments\Application\Exception\InstitutionalAcknowledgementsAlreadyCompleted;
use App\InstitutionalDocuments\Application\Exception\InvalidAcknowledgementConfirmation;
use App\InstitutionalDocuments\Application\Exception\InvalidPersistedAcknowledgementResult;
use App\InstitutionalDocuments\Application\RepresentativePortal\CompleteAuthenticatedRepresentativeAcknowledgements;
use App\InstitutionalDocuments\Application\RepresentativePortal\Exception\ActiveAcademicPeriodUnavailable;
use App\InstitutionalDocuments\Application\RepresentativePortal\Exception\RepresentativeAcknowledgementAccessUnavailable;
use App\InstitutionalDocuments\Application\RepresentativePortal\GetRepresentativeAcknowledgementPortalState;
use Core\Http\Request;

final class RepresentativeAcknowledgementController extends Controller
{
    private const FLASH_SUCCESS_KEY = '_flash_representative_acknowledgements_success';
    private const FLASH_ERROR_KEY = '_flash_representative_acknowledgements_error';

    public function __construct(
        private readonly GetRepresentativeAcknowledgementPortalState $getState,
        private readonly CompleteAuthenticatedRepresentativeAcknowledgements $complete,
        private readonly CsrfTokenManager $csrf,
        private readonly SessionManager $session,
    ) {
    }

    public function index(): string
    {
        try {
            $state = $this->getState->handle();
        } catch (ActiveAcademicPeriodUnavailable) {
            $state = null;
        } catch (RepresentativeAcknowledgementAccessUnavailable) {
            return $this->forbidden();
        } catch (InvalidPersistedAcknowledgementResult) {
            return $this->error('The operation could not be confirmed.', 422);
        }

        http_response_code(200);

        return $this->view('representative-portal.acknowledgements', [
            'title' => 'Institutional Acknowledgements',
            'state' => $state,
            'csrfToken' => $this->csrf->token(),
            'successMessage' => $this->flash(self::FLASH_SUCCESS_KEY),
            'errorMessage' => $this->flash(self::FLASH_ERROR_KEY),
        ]);
    }

    public function complete(): string
    {
        $input = (new Request())->input();
        if (!$this->csrf->isValid($this->scalar($input, '_csrf_token'))) {
            return $this->error('The request could not be verified.', 403);
        }

        try {
            $ids = $this->requirementIds($input['acknowledged_requirement_ids'] ?? []);
            $output = $this->complete->handle($ids);
            $this->session->put(
                self::FLASH_SUCCESS_KEY,
                $output->completionId === null
                    ? 'No institutional acknowledgements are required for this Academic Period.'
                    : 'Institutional Acknowledgements completed successfully.',
            );
        } catch (InstitutionalAcknowledgementsAlreadyCompleted) {
            $this->session->put(
                self::FLASH_SUCCESS_KEY,
                'Institutional Acknowledgements were already completed for this Academic Period.',
            );
        } catch (InvalidAcknowledgementConfirmation) {
            $this->session->put(
                self::FLASH_ERROR_KEY,
                'The requirements changed. Review the current requirements and try again.',
            );
        } catch (ActiveAcademicPeriodUnavailable) {
            $this->session->put(
                self::FLASH_ERROR_KEY,
                'No active Academic Period is currently configured.',
            );

            return $this->redirect('/representative', 303);
        } catch (RepresentativeAcknowledgementAccessUnavailable) {
            return $this->forbidden();
        } catch (InvalidPersistedAcknowledgementResult) {
            return $this->error('The operation could not be confirmed.', 422);
        }

        return $this->redirect('/representative/acknowledgements', 303);
    }

    /** @return list<int> */
    private function requirementIds(mixed $values): array
    {
        if (!is_array($values)) {
            throw new InvalidAcknowledgementConfirmation(
                'Institutional Acknowledgements confirmation is invalid.'
            );
        }

        $ids = [];
        foreach ($values as $value) {
            if (!is_int($value) && !is_string($value)) {
                throw new InvalidAcknowledgementConfirmation(
                    'Institutional Acknowledgements confirmation is invalid.'
                );
            }
            $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!is_int($id)) {
                throw new InvalidAcknowledgementConfirmation(
                    'Institutional Acknowledgements confirmation is invalid.'
                );
            }
            $ids[] = $id;
        }

        return $ids;
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

    private function forbidden(): string
    {
        return $this->error('Representative acknowledgement access is unavailable.', 403);
    }

    private function error(string $message, int $status): string
    {
        http_response_code($status);

        return '<h1>Institutional Acknowledgements unavailable</h1><p role="alert">'
            . htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
            . '</p><p><a href="/representative">Back to Representative Portal</a></p>';
    }

    private function redirect(string $location, int $status): string
    {
        header('Location: ' . $location);
        http_response_code($status);

        return '';
    }
}
