<?php

declare(strict_types=1);

namespace App\InstitutionalDocuments\Http;

use App\AcademicCore\Application\ActivateAcademicPeriod;
use App\AcademicCore\Application\DeactivateAcademicPeriod;
use App\AcademicCore\Application\Exception\AcademicPeriodNotFound;
use App\AcademicCore\Application\Exception\InvalidPersistedAcademicPeriodResult;
use App\AcademicCore\Domain\Exception\AcademicPeriodOperationalStateConflict;
use App\Controllers\Controller;
use App\IdentityAccess\Application\Contract\CsrfTokenManager;
use App\IdentityAccess\Application\Contract\SessionManager;
use App\InstitutionalDocuments\Application\ActivateAcknowledgementRequirement;
use App\InstitutionalDocuments\Application\CreateAcknowledgementRequirement;
use App\InstitutionalDocuments\Application\DeactivateAcknowledgementRequirement;
use App\InstitutionalDocuments\Application\Dto\CreateAcknowledgementRequirementInput;
use App\InstitutionalDocuments\Application\Dto\UpdateAcknowledgementRequirementInput;
use App\InstitutionalDocuments\Application\Exception\AcknowledgementRequirementNotFound;
use App\InstitutionalDocuments\Application\Exception\InvalidPersistedAcknowledgementResult;
use App\InstitutionalDocuments\Application\GetAcknowledgementRequirements;
use App\InstitutionalDocuments\Application\UpdateAcknowledgementRequirement;
use App\InstitutionalDocuments\Domain\Exception\InvalidInstitutionalAcknowledgementState;
use Core\Http\Request;

final class InstitutionalAcknowledgementController extends Controller
{
    private const TRUSTED_PERIOD_KEY = '_institutional_acknowledgements_trusted_academic_period_id';
    private const FLASH_SUCCESS_KEY = '_flash_institutional_acknowledgements_success';
    private const ALLOWED_STATUSES = ['ACTIVE', 'INACTIVE'];

    public function __construct(
        private readonly GetAcknowledgementRequirements $getRequirements,
        private readonly CreateAcknowledgementRequirement $createRequirement,
        private readonly UpdateAcknowledgementRequirement $updateRequirement,
        private readonly ActivateAcknowledgementRequirement $activateRequirement,
        private readonly DeactivateAcknowledgementRequirement $deactivateRequirement,
        private readonly CsrfTokenManager $csrf,
        private readonly SessionManager $session,
        private readonly InstitutionalAcknowledgementAcademicPeriodOptionsProvider $periods,
        private readonly ActivateAcademicPeriod $activateAcademicPeriod,
        private readonly DeactivateAcademicPeriod $deactivateAcademicPeriod,
    ) {
    }

    public function index(): string
    {
        $query = (new Request())->query();
        $options = $this->periods->all();
        if (!array_key_exists('academic_period_id', $query)) {
            $this->session->remove(self::TRUSTED_PERIOD_KEY);

            return $this->render($options, null, [], [], [], $this->flash());
        }

        $periodId = $this->positiveInteger($query['academic_period_id']);
        if ($periodId === null) {
            $this->session->remove(self::TRUSTED_PERIOD_KEY);

            return $this->contextError('Select a valid Academic Period.', 422, $options);
        }

        $period = $this->periods->findById($periodId);
        if ($period === null) {
            $this->session->remove(self::TRUSTED_PERIOD_KEY);

            return $this->contextError('Academic Period not found.', 404, $options);
        }

        $this->session->put(self::TRUSTED_PERIOD_KEY, $periodId);

        return $this->render(
            $options,
            $period,
            $this->getRequirements->handle($periodId),
            [],
            [],
            $this->flash(),
        );
    }

    public function create(): string
    {
        return $this->mutate(function (int $periodId, array $values): void {
            [$data, $errors] = $this->requirementData($values, true);
            if ($errors !== []) {
                throw new InvalidFormInput($errors);
            }
            $this->createRequirement->handle(new CreateAcknowledgementRequirementInput(
                $periodId,
                $data['title'],
                $data['url'],
                $data['officialReference'],
                $data['status'],
            ));
        }, 'Requirement created successfully.');
    }

    public function update(): string
    {
        return $this->mutate(function (int $periodId, array $values): void {
            [$data, $errors] = $this->requirementData($values, false);
            $requirementId = $this->positiveInteger($values['requirement_id'] ?? null);
            if ($requirementId === null) {
                $errors[] = 'Select a valid Requirement.';
            }
            if ($errors !== []) {
                throw new InvalidFormInput($errors);
            }
            $this->updateRequirement->handle(new UpdateAcknowledgementRequirementInput(
                $requirementId,
                $periodId,
                $data['title'],
                $data['url'],
                $data['officialReference'],
            ));
        }, 'Requirement updated successfully.');
    }

    public function activate(): string
    {
        return $this->status(fn (int $id, int $periodId): mixed =>
            $this->activateRequirement->handle($id, $periodId), 'Requirement activated successfully.');
    }

    public function deactivate(): string
    {
        return $this->status(fn (int $id, int $periodId): mixed =>
            $this->deactivateRequirement->handle($id, $periodId), 'Requirement deactivated successfully.');
    }

    public function activateAcademicPeriod(): string
    {
        return $this->changeAcademicPeriodStatus(
            fn (int $id): mixed => $this->activateAcademicPeriod->handle($id),
            'Academic Period activated successfully.',
        );
    }

    public function deactivateAcademicPeriod(): string
    {
        return $this->changeAcademicPeriodStatus(
            fn (int $id): mixed => $this->deactivateAcademicPeriod->handle($id),
            'Academic Period deactivated successfully.',
        );
    }

    /** @param callable(int, array<string, string>): void $operation */
    private function mutate(callable $operation, string $success): string
    {
        $input = (new Request())->input();
        $token = $this->scalar($input, '_csrf_token');
        if (!$this->csrf->isValid($token)) {
            return $this->plainError('The request could not be verified.', 419);
        }

        $period = $this->trustedPeriod($input);
        if ($period === null) {
            return $this->plainError('Academic Period context is unavailable.', 422);
        }

        $errors = [];
        $values = $this->safeValues($input, $errors);
        try {
            if ($errors !== []) {
                throw new InvalidFormInput($errors);
            }
            $operation($period->id, $values);
        } catch (InvalidFormInput $exception) {
            return $this->renderFailure($period, $values, $exception->errors);
        } catch (AcknowledgementRequirementNotFound) {
            return $this->renderFailure($period, $values, ['Requirement was not found.']);
        } catch (InvalidInstitutionalAcknowledgementState) {
            return $this->renderFailure($period, $values, ['Requirement could not be changed.']);
        } catch (InvalidPersistedAcknowledgementResult) {
            return $this->renderFailure($period, $values, ['The operation could not be confirmed.']);
        }

        $this->session->put(self::FLASH_SUCCESS_KEY, $success);

        return $this->redirect($period->id);
    }

    private function status(callable $operation, string $success): string
    {
        return $this->mutate(function (int $periodId, array $values) use ($operation): void {
            $requirementId = $this->positiveInteger($values['requirement_id'] ?? null);
            if ($requirementId === null) {
                throw new InvalidFormInput(['Select a valid Requirement.']);
            }
            $operation($requirementId, $periodId);
        }, $success);
    }

    /** @param callable(int): mixed $operation */
    private function changeAcademicPeriodStatus(callable $operation, string $success): string
    {
        $input = (new Request())->input();
        if (!$this->csrf->isValid($this->scalar($input, '_csrf_token'))) {
            return $this->plainError('The request could not be verified.', 419);
        }

        $periodId = $this->positiveInteger($input['academic_period_id'] ?? null);
        if ($periodId === null) {
            return $this->plainError('Select a valid Academic Period.', 422);
        }

        try {
            $operation($periodId);
        } catch (AcademicPeriodNotFound) {
            return $this->plainError('Academic Period was not found.', 404);
        } catch (AcademicPeriodOperationalStateConflict) {
            return $this->plainError('Academic Period operational state is inconsistent.', 409);
        } catch (InvalidPersistedAcademicPeriodResult) {
            return $this->plainError('The Academic Period operation could not be confirmed.', 409);
        }

        $this->session->put(self::FLASH_SUCCESS_KEY, $success);

        return $this->redirect($periodId);
    }

    private function trustedPeriod(array $input): ?InstitutionalAcknowledgementAcademicPeriodOption
    {
        $hiddenId = $this->positiveInteger($input['academic_period_id'] ?? null);
        $trustedId = $this->positiveInteger($this->session->get(self::TRUSTED_PERIOD_KEY));
        if ($hiddenId === null || $trustedId === null || $hiddenId !== $trustedId) {
            return null;
        }

        return $this->periods->findById($trustedId);
    }

    /** @return array{array{title: string, url: string, officialReference: ?string, status: string}, list<string>} */
    private function requirementData(array $values, bool $creating): array
    {
        $errors = [];
        $title = $values['title'] ?? '';
        $url = $values['url'] ?? '';
        $officialReference = $values['official_reference'] ?? '';
        $status = $creating ? ($values['status'] ?? '') : '';
        if ($title === '' || mb_strlen($title) > 200) {
            $errors[] = 'Title is required and must not exceed 200 characters.';
        }
        if ($url === '' || mb_strlen($url) > 500) {
            $errors[] = 'URL is required and must not exceed 500 characters.';
        }
        if (mb_strlen($officialReference) > 255) {
            $errors[] = 'Official Reference must not exceed 255 characters.';
        }
        if ($creating && !in_array($status, self::ALLOWED_STATUSES, true)) {
            $errors[] = 'Select a valid Status.';
        }

        return [[
            'title' => $title,
            'url' => $url,
            'officialReference' => $officialReference === '' ? null : $officialReference,
            'status' => $status,
        ], $errors];
    }

    private function renderFailure(
        InstitutionalAcknowledgementAcademicPeriodOption $period,
        array $values,
        array $errors,
    ): string {
        return $this->render(
            $this->periods->all(),
            $period,
            $this->getRequirements->handle($period->id),
            $values,
            $errors,
            null,
            422,
        );
    }

    private function contextError(string $message, int $status, array $options): string
    {
        return $this->render($options, null, [], [], [$message], null, $status);
    }

    private function render(
        array $periods,
        ?InstitutionalAcknowledgementAcademicPeriodOption $selectedPeriod,
        array $requirements,
        array $values,
        array $errors,
        ?string $success,
        int $status = 200,
    ): string {
        http_response_code($status);

        return $this->view('institutional-acknowledgements.index', [
            'title' => 'Institutional Acknowledgements',
            'periods' => $periods,
            'selectedPeriod' => $selectedPeriod,
            'requirements' => $requirements,
            'values' => $values,
            'errors' => $errors,
            'successMessage' => $success,
            'csrfToken' => $this->csrf->token(),
        ]);
    }

    /** @return array<string, string> */
    private function safeValues(array $input, array &$errors): array
    {
        $values = [];
        foreach ($input as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $values[$key] = trim((string) $value);
            } elseif (is_string($key)) {
                $errors[] = sprintf('%s must be a single value.', ucfirst(str_replace('_', ' ', $key)));
            }
        }

        return $values;
    }

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

    private function flash(): ?string
    {
        $value = $this->session->pull(self::FLASH_SUCCESS_KEY);

        return is_string($value) ? $value : null;
    }

    private function redirect(int $periodId): string
    {
        header('Location: /institutional-acknowledgements?academic_period_id=' . $periodId);
        http_response_code(303);

        return '';
    }

    private function plainError(string $message, int $status): string
    {
        http_response_code($status);

        return '<h1>Institutional Acknowledgements unavailable</h1><p role="alert">'
            . htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
            . '</p><p><a href="/institutional-acknowledgements">Back</a></p>';
    }
}
