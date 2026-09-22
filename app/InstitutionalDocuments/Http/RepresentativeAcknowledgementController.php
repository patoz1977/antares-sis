<?php

declare(strict_types=1);

namespace App\InstitutionalDocuments\Http;

use App\Shared\Http\SafeErrorPage;
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
            return $this->error('No se pudo confirmar la operación.', 422);
        }

        http_response_code(200);

        return $this->view('representative-portal.acknowledgements', [
            'title' => 'Aceptaciones institucionales',
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
            return $this->error('No se pudo verificar la solicitud.', 403);
        }

        try {
            $ids = $this->requirementIds($input['acknowledged_requirement_ids'] ?? []);
            $output = $this->complete->handle($ids);
            $this->session->put(
                self::FLASH_SUCCESS_KEY,
                $output->completionId === null
                    ? 'No se requieren aceptaciones institucionales para este período académico.'
                    : 'Aceptaciones institucionales completadas correctamente.',
            );
        } catch (InstitutionalAcknowledgementsAlreadyCompleted) {
            $this->session->put(
                self::FLASH_SUCCESS_KEY,
                'Las aceptaciones institucionales ya están completas para este período académico.',
            );
        } catch (InvalidAcknowledgementConfirmation) {
            $this->session->put(
                self::FLASH_ERROR_KEY,
                'Los requisitos cambiaron. Revise los requisitos actuales e inténtelo nuevamente.',
            );
        } catch (ActiveAcademicPeriodUnavailable) {
            $this->session->put(
                self::FLASH_ERROR_KEY,
                'No hay un período académico activo configurado.',
            );

            return $this->redirect('/representative', 303);
        } catch (RepresentativeAcknowledgementAccessUnavailable) {
            return $this->forbidden();
        } catch (InvalidPersistedAcknowledgementResult) {
            return $this->error('No se pudo confirmar la operación.', 422);
        }

        return $this->redirect('/representative/acknowledgements', 303);
    }

    /** @return list<int> */
    private function requirementIds(mixed $values): array
    {
        if (!is_array($values)) {
            throw new InvalidAcknowledgementConfirmation(
                'La confirmación de aceptaciones institucionales no es válida.'
            );
        }

        $ids = [];
        foreach ($values as $value) {
            if (!is_int($value) && !is_string($value)) {
                throw new InvalidAcknowledgementConfirmation(
                    'La confirmación de aceptaciones institucionales no es válida.'
                );
            }
            $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!is_int($id)) {
                throw new InvalidAcknowledgementConfirmation(
                    'La confirmación de aceptaciones institucionales no es válida.'
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
        return $this->error('No tiene acceso a las aceptaciones institucionales.', 403);
    }

    private function error(string $message, int $status): string
    {
        http_response_code($status);

        return SafeErrorPage::render($status, $message, '/representative', 'Volver al portal');
    }

    private function redirect(string $location, int $status): string
    {
        header('Location: ' . $location);
        http_response_code($status);

        return '';
    }
}
