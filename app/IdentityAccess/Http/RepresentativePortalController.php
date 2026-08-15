<?php

declare(strict_types=1);

namespace App\IdentityAccess\Http;

use App\Controllers\Controller;
use App\IdentityAccess\Application\Contract\CsrfTokenManager;
use App\IdentityAccess\Application\Exception\FamilyContextNotAuthorized;
use App\IdentityAccess\Application\ResolveFamilyContext;
use App\IdentityAccess\Application\SelectAuthorizedFamily;
use App\InstitutionalDocuments\Application\Exception\InvalidPersistedAcknowledgementResult;
use App\InstitutionalDocuments\Application\RepresentativePortal\Exception\ActiveAcademicPeriodUnavailable;
use App\InstitutionalDocuments\Application\RepresentativePortal\Exception\RepresentativeAcknowledgementAccessUnavailable;
use App\InstitutionalDocuments\Application\RepresentativePortal\GetRepresentativeAcknowledgementPortalState;
use Core\Http\Request;

final class RepresentativePortalController extends Controller
{
    public function __construct(
        private readonly ResolveFamilyContext $resolveFamilyContext,
        private readonly SelectAuthorizedFamily $selectAuthorizedFamily,
        private readonly CsrfTokenManager $csrf,
        private readonly GetRepresentativeAcknowledgementPortalState $getAcknowledgementState,
    ) {
    }

    public function index(): string
    {
        $access = $this->resolveFamilyContext->handle();
        if ($access === null) {
            return $this->forbidden();
        }
        if ($access->authorizedFamilies === []) {
            http_response_code(403);

            return $this->view('representative-portal.no-family', [
                'title' => 'Representative Portal unavailable',
                'csrfToken' => $this->csrf->token(),
            ]);
        }

        try {
            $acknowledgementState = $this->getAcknowledgementState->handle();
        } catch (ActiveAcademicPeriodUnavailable) {
            $acknowledgementState = null;
        } catch (RepresentativeAcknowledgementAccessUnavailable|InvalidPersistedAcknowledgementResult) {
            return $this->forbidden();
        }

        http_response_code(200);

        return $this->view('representative-portal.index', [
            'title' => 'Representative Portal',
            'authorizedFamilies' => $access->authorizedFamilies,
            'context' => $access->context,
            'requiresSelection' => $access->requiresSelection,
            'acknowledgementState' => $acknowledgementState,
            'csrfToken' => $this->csrf->token(),
        ]);
    }

    public function selectFamily(): string
    {
        $input = (new Request())->input();
        if (!$this->csrf->isValid($this->scalarValue($input, '_csrf_token'))) {
            return $this->forbidden();
        }

        $familyId = $this->positiveInteger($input['family_id'] ?? null);
        if ($familyId === null) {
            return $this->forbidden();
        }

        try {
            $this->selectAuthorizedFamily->handle($familyId);
        } catch (FamilyContextNotAuthorized) {
            return $this->forbidden();
        }

        return $this->redirect('/representative', 303);
    }

    private function forbidden(): string
    {
        http_response_code(403);

        return $this->view('representative-portal.forbidden', [
            'title' => 'Representative Portal unavailable',
            'csrfToken' => $this->csrf->token(),
        ]);
    }

    private function scalarValue(array $input, string $key): string
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

    private function redirect(string $location, int $status = 302): string
    {
        header('Location: ' . $location);
        http_response_code($status);

        return '';
    }
}
