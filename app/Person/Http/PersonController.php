<?php

declare(strict_types=1);

namespace App\Person\Http;

use App\Controllers\Controller;
use App\IdentityAccess\Application\Contract\CsrfTokenManager;
use App\IdentityAccess\Application\Contract\SessionManager;
use App\Person\Application\CreatePerson;
use App\Person\Application\Dto\CreatePersonInput;
use App\Person\Application\Dto\PersonOutput;
use App\Person\Application\Dto\UpdatePersonInput;
use App\Person\Application\Exception\IdentificationAlreadyUsed;
use App\Person\Application\Exception\PersonNotFound;
use App\Person\Application\GetPerson;
use App\Person\Application\UpdatePerson;
use App\Person\Domain\Exception\InvalidPersonState;
use App\Person\Domain\PersonStatus;
use Core\Http\Request;
use DateTimeImmutable;

final class PersonController extends Controller
{
    private const FLASH_SUCCESS_KEY = '_flash_person_success';
    private const FLASH_ERROR_KEY = '_flash_person_error';
    private const FORM_STATE_KEY = '_flash_person_form_state';
    private const EDIT_ID_KEY = '_person_edit_id';

    public function __construct(
        private readonly CreatePerson $createPerson,
        private readonly GetPerson $getPerson,
        private readonly UpdatePerson $updatePerson,
        private readonly PersonFormOptionsProvider $formOptions,
        private readonly CsrfTokenManager $csrf,
        private readonly SessionManager $session,
    ) {
    }

    public function index(): string
    {
        return $this->view('persons.index', [
            'title' => 'Persons',
            'successMessage' => $this->flashMessage(self::FLASH_SUCCESS_KEY),
            'errorMessage' => $this->flashMessage(self::FLASH_ERROR_KEY),
        ]);
    }

    public function showCreate(): string
    {
        $state = $this->formState();
        $values = $state['values'] ?? $this->emptyValues();
        $errors = $state['errors'] ?? [];
        $options = $this->formOptions->get();

        if (!$options->isReadyForSave()) {
            $errors[] = $this->catalogUnavailableMessage();
        }

        return $this->formView('create', $values, $errors, $options);
    }

    public function create(): string
    {
        $input = (new Request())->input();

        if (!$this->csrf->isValid($this->scalarValue($input, '_csrf_token'))) {
            $this->storeFormState($input, ['Your form expired. Please try again.']);

            return $this->redirect('/persons/create', 303);
        }

        $options = $this->formOptions->get();
        [$values, $errors, $data] = $this->validateForm($input, $options);

        if ($errors !== []) {
            return $this->formView('create', $values, $errors, $options, 422);
        }

        try {
            $person = $this->createPerson->handle(
                new CreatePersonInput(...$data),
                new DateTimeImmutable('today'),
            );
        } catch (IdentificationAlreadyUsed) {
            return $this->formView(
                'create',
                $values,
                ['A Person already uses that identification.'],
                $options,
                422,
            );
        } catch (InvalidPersonState) {
            return $this->formView(
                'create',
                $values,
                ['Review the entered Person data.'],
                $options,
                422,
            );
        }

        $this->session->put(self::FLASH_SUCCESS_KEY, 'Person created successfully.');

        return $this->redirect('/persons/show?id=' . $person->id, 303);
    }

    public function show(): string
    {
        $id = $this->positiveInteger((new Request())->query()['id'] ?? null);
        if ($id === null) {
            $this->session->put(self::FLASH_ERROR_KEY, 'Enter a valid positive Person ID.');

            return $this->redirect('/persons');
        }

        try {
            $person = $this->getPerson->handle($id);
        } catch (PersonNotFound) {
            return $this->notFound();
        }

        return $this->view('persons.show', [
            'title' => 'Person details',
            'person' => $person,
            'successMessage' => $this->flashMessage(self::FLASH_SUCCESS_KEY),
        ]);
    }

    public function showEdit(): string
    {
        $id = $this->positiveInteger((new Request())->query()['id'] ?? null);
        if ($id === null) {
            $this->session->put(self::FLASH_ERROR_KEY, 'Enter a valid positive Person ID.');

            return $this->redirect('/persons');
        }

        try {
            $person = $this->getPerson->handle($id);
        } catch (PersonNotFound) {
            return $this->notFound();
        }

        $state = $this->formState();
        $values = ($state['personId'] ?? null) === $id
            ? ($state['values'] ?? $this->valuesFromPerson($person))
            : $this->valuesFromPerson($person);
        $errors = ($state['personId'] ?? null) === $id ? ($state['errors'] ?? []) : [];
        $options = $this->formOptions->get();

        if (!$options->isReadyForSave()) {
            $errors[] = $this->catalogUnavailableMessage();
        }

        $this->session->put(self::EDIT_ID_KEY, $id);

        return $this->formView('edit', $values, $errors, $options, personId: $id);
    }

    public function update(): string
    {
        $input = (new Request())->input();
        $trustedId = $this->session->pull(self::EDIT_ID_KEY);
        $trustedId = is_int($trustedId) && $trustedId > 0 ? $trustedId : null;

        if (!$this->csrf->isValid($this->scalarValue($input, '_csrf_token'))) {
            if ($trustedId !== null) {
                $this->session->put(self::EDIT_ID_KEY, $trustedId);
            }
            $this->storeFormState($input, ['Your form expired. Please try again.'], $trustedId);

            return $this->redirect($trustedId === null ? '/persons' : '/persons/edit?id=' . $trustedId, 303);
        }

        $options = $this->formOptions->get();
        [$values, $errors, $data] = $this->validateForm($input, $options);
        $postedId = $this->positiveInteger($input['id'] ?? null);

        if ($trustedId === null) {
            $errors[] = 'The editing session expired. Open the Person again.';
        } elseif ($postedId !== $trustedId) {
            $errors[] = 'Person identity cannot be changed.';
        }

        if ($errors !== []) {
            if ($trustedId !== null) {
                $this->session->put(self::EDIT_ID_KEY, $trustedId);
            }

            return $this->formView('edit', $values, $errors, $options, 422, $trustedId);
        }

        try {
            $person = $this->updatePerson->handle(
                new UpdatePersonInput($trustedId, ...$data),
                new DateTimeImmutable('today'),
            );
        } catch (PersonNotFound) {
            return $this->notFound();
        } catch (IdentificationAlreadyUsed) {
            $this->session->put(self::EDIT_ID_KEY, $trustedId);

            return $this->formView(
                'edit',
                $values,
                ['A Person already uses that identification.'],
                $options,
                422,
                $trustedId,
            );
        } catch (InvalidPersonState) {
            $this->session->put(self::EDIT_ID_KEY, $trustedId);

            return $this->formView(
                'edit',
                $values,
                ['Review the entered Person data.'],
                $options,
                422,
                $trustedId,
            );
        }

        $this->session->put(self::FLASH_SUCCESS_KEY, 'Person updated successfully.');

        return $this->redirect('/persons/show?id=' . $person->id, 303);
    }

    /**
     * @return array{0: array<string, string>, 1: list<string>, 2: array<string, mixed>}
     */
    private function validateForm(array $input, PersonFormOptions $options): array
    {
        $errors = [];
        $values = $this->preservedValues($input, $errors);

        $birthDate = $this->dateValue($values['birth_date']);
        if ($birthDate === null) {
            $errors[] = 'Birth date must use the YYYY-MM-DD format.';
        }

        $sexId = $this->positiveInteger($values['sex_id']);
        if ($sexId === null || !$options->hasSex($sexId)) {
            $errors[] = 'Select a valid sex.';
        }

        $documentTypeId = $this->optionalPositiveInteger(
            $values['document_type_id'],
            'document type',
            $errors,
        );
        $maritalStatusId = $this->optionalPositiveInteger(
            $values['marital_status_id'],
            'marital status',
            $errors,
        );
        $educationLevelId = $this->optionalPositiveInteger(
            $values['education_level_id'],
            'education level',
            $errors,
        );

        if ($documentTypeId !== null && !$options->hasDocumentType($documentTypeId)) {
            $errors[] = 'Select a valid document type.';
        }
        if ($maritalStatusId !== null && !$options->hasMaritalStatus($maritalStatusId)) {
            $errors[] = 'Select a valid marital status.';
        }
        if ($educationLevelId !== null && !$options->hasEducationLevel($educationLevelId)) {
            $errors[] = 'Select a valid education level.';
        }

        $documentNumber = $this->nullableString($values['document_number']);
        if (($documentTypeId === null) !== ($documentNumber === null)) {
            $errors[] = 'Document type and document number must both be provided or both be empty.';
        }

        $status = PersonStatus::tryFrom($values['status']);
        if ($status === null || !$options->hasStatus($values['status'])) {
            $errors[] = 'Select a valid status.';
        }

        if (!$options->isReadyForSave()) {
            $errors[] = $this->catalogUnavailableMessage();
        }

        $data = [
            'firstName' => $values['first_name'],
            'middleName' => $this->nullableString($values['middle_name']),
            'firstSurname' => $values['first_surname'],
            'secondSurname' => $this->nullableString($values['second_surname']),
            'documentTypeId' => $documentTypeId,
            'documentNumber' => $documentNumber,
            'birthDate' => $birthDate,
            'sexId' => $sexId,
            'maritalStatusId' => $maritalStatusId,
            'educationLevelId' => $educationLevelId,
            'email' => $this->nullableString($values['email']),
            'mobilePhone' => $this->nullableString($values['mobile_phone']),
            'landlinePhone' => $this->nullableString($values['landline_phone']),
            'status' => $status,
        ];

        return [$values, array_values(array_unique($errors)), $data];
    }

    /** @return array<string, string> */
    private function preservedValues(array $input, array &$errors = []): array
    {
        $values = [];

        foreach (array_keys($this->emptyValues()) as $field) {
            $value = $input[$field] ?? '';
            if (!is_scalar($value) && $value !== null) {
                $errors[] = sprintf('%s must be a single value.', str_replace('_', ' ', ucfirst($field)));
                $values[$field] = '';

                continue;
            }

            $values[$field] = trim((string) $value);
        }

        return $values;
    }

    /** @return array<string, string> */
    private function emptyValues(): array
    {
        return [
            'first_name' => '',
            'middle_name' => '',
            'first_surname' => '',
            'second_surname' => '',
            'document_type_id' => '',
            'document_number' => '',
            'birth_date' => '',
            'sex_id' => '',
            'marital_status_id' => '',
            'education_level_id' => '',
            'email' => '',
            'mobile_phone' => '',
            'landline_phone' => '',
            'status' => 'ACTIVE',
        ];
    }

    /** @return array<string, string> */
    private function valuesFromPerson(PersonOutput $person): array
    {
        return [
            'first_name' => $person->firstName,
            'middle_name' => $person->middleName ?? '',
            'first_surname' => $person->firstSurname,
            'second_surname' => $person->secondSurname ?? '',
            'document_type_id' => $person->documentTypeId === null ? '' : (string) $person->documentTypeId,
            'document_number' => $person->documentNumber ?? '',
            'birth_date' => $person->birthDate->format('Y-m-d'),
            'sex_id' => (string) $person->sexId,
            'marital_status_id' => $person->maritalStatusId === null ? '' : (string) $person->maritalStatusId,
            'education_level_id' => $person->educationLevelId === null ? '' : (string) $person->educationLevelId,
            'email' => $person->email ?? '',
            'mobile_phone' => $person->mobilePhone ?? '',
            'landline_phone' => $person->landlinePhone ?? '',
            'status' => $person->status->value,
        ];
    }

    private function formView(
        string $mode,
        array $values,
        array $errors,
        PersonFormOptions $options,
        int $status = 200,
        ?int $personId = null,
    ): string {
        http_response_code($status);

        return $this->view('persons.form', [
            'title' => $mode === 'create' ? 'Create Person' : 'Edit Person',
            'mode' => $mode,
            'personId' => $personId,
            'values' => $values,
            'errors' => $errors,
            'options' => $options,
            'csrfToken' => $this->csrf->token(),
            'canSubmit' => $options->isReadyForSave(),
        ]);
    }

    private function notFound(): string
    {
        http_response_code(404);

        return $this->view('persons.not-found', ['title' => 'Person not found']);
    }

    private function flashMessage(string $key): ?string
    {
        $message = $this->session->pull($key);

        return is_string($message) ? $message : null;
    }

    /** @return array{values?: array<string, string>, errors?: list<string>, personId?: int}|array{} */
    private function formState(): array
    {
        $state = $this->session->pull(self::FORM_STATE_KEY);

        return is_array($state) ? $state : [];
    }

    private function storeFormState(array $input, array $errors, ?int $personId = null): void
    {
        $state = [
            'values' => $this->preservedValues($input),
            'errors' => $errors,
        ];

        if ($personId !== null) {
            $state['personId'] = $personId;
        }

        $this->session->put(self::FORM_STATE_KEY, $state);
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

    private function optionalPositiveInteger(string $value, string $label, array &$errors): ?int
    {
        if ($value === '') {
            return null;
        }

        $id = $this->positiveInteger($value);
        if ($id === null) {
            $errors[] = sprintf('Select a valid %s.', $label);
        }

        return $id;
    }

    private function dateValue(string $value): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value ? $date : null;
    }

    private function nullableString(string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    private function catalogUnavailableMessage(): string
    {
        return 'Person cannot be saved because required form catalogs are unavailable.';
    }

    private function redirect(string $location, int $status = 302): string
    {
        header('Location: ' . $location);
        http_response_code($status);

        return '';
    }
}
