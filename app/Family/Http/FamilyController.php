<?php

declare(strict_types=1);

namespace App\Family\Http;

use App\Controllers\Controller;
use App\Family\Application\Dto\FamilyOutput;
use App\Family\Application\Exception\FamilyNotFound;
use App\Family\Application\Exception\InvalidPersistedFamilyResult;
use App\Family\Application\Exception\RelationshipTypeNotFound;
use App\Family\Application\Exception\StudentAlreadyHasActiveFamily;
use App\Family\Application\GetFamily;
use App\Family\Application\Orchestration\CreateRepresentativeFamily;
use App\Family\Application\Orchestration\CreateStudentInFamily;
use App\Family\Application\Orchestration\Dto\CreateRepresentativeFamilyInput;
use App\Family\Application\Orchestration\Dto\CreateStudentInFamilyInput;
use App\Family\Domain\Exception\InvalidFamilyState;
use App\Family\Domain\FamilyStatus;
use App\IdentityAccess\Application\Contract\CsrfTokenManager;
use App\IdentityAccess\Application\Contract\SessionManager;
use App\Person\Application\Exception\IdentificationAlreadyUsed;
use App\Person\Application\Exception\InvalidPersistedPersonResult;
use App\Person\Domain\Exception\InvalidPersonState;
use App\Person\Domain\PersonStatus;
use App\Person\Http\PersonFormOptions;
use App\Person\Http\PersonFormOptionsProvider;
use App\Representative\Application\Exception\InvalidPersistedRepresentativeResult;
use App\Representative\Application\Exception\RepresentativeAlreadyExistsForPerson;
use App\Representative\Domain\Exception\InvalidRepresentativeState;
use App\Representative\Domain\RepresentativeStatus;
use App\Student\Application\Exception\InstitutionalCodeAlreadyUsed;
use App\Student\Application\Exception\InvalidPersistedStudentResult;
use App\Student\Application\Exception\StudentAlreadyExistsForPerson;
use App\Student\Domain\Exception\InvalidStudentState;
use App\Student\Domain\StudentStatus;
use Core\Http\Request;
use DateTimeImmutable;

final class FamilyController extends Controller
{
    private const FLASH_SUCCESS_KEY = '_flash_family_success';
    private const FLASH_ERROR_KEY = '_flash_family_error';
    private const REPRESENTATIVE_FORM_STATE_KEY = '_flash_family_representative_form_state';
    private const STUDENT_FORM_STATE_KEY = '_flash_family_student_form_state';
    private const TRUSTED_FAMILY_ID_KEY = '_family_student_trusted_family_id';

    public function __construct(
        private readonly CreateRepresentativeFamily $createRepresentativeFamily,
        private readonly CreateStudentInFamily $createStudentInFamily,
        private readonly GetFamily $getFamily,
        private readonly CsrfTokenManager $csrf,
        private readonly SessionManager $session,
        private readonly PersonFormOptionsProvider $personFormOptions,
        private readonly FamilyFormOptionsProvider $familyFormOptions,
    ) {
    }

    public function index(): string
    {
        return $this->view('families.index', [
            'title' => 'Families',
            'successMessage' => $this->flashMessage(self::FLASH_SUCCESS_KEY),
            'errorMessage' => $this->flashMessage(self::FLASH_ERROR_KEY),
        ]);
    }

    public function showCreateRepresentativeFamily(): string
    {
        $state = $this->formState(self::REPRESENTATIVE_FORM_STATE_KEY);
        $values = $state['values'] ?? $this->emptyRepresentativeValues();
        $errors = $state['errors'] ?? [];
        $personOptions = $this->personFormOptions->get();
        $familyOptions = $this->familyFormOptions->get();
        $errors = $this->catalogErrors($errors, $personOptions, $familyOptions);

        return $this->representativeFormView($values, $errors, $personOptions, $familyOptions);
    }

    public function createRepresentativeFamily(): string
    {
        $input = (new Request())->input();
        if (!$this->csrf->isValid($this->scalarValue($input, '_csrf_token'))) {
            $this->storeFormState(
                self::REPRESENTATIVE_FORM_STATE_KEY,
                $input,
                $this->representativeFields(),
                ['Your form expired. Please try again.'],
            );

            return $this->redirect('/families/create', 303);
        }

        $personOptions = $this->personFormOptions->get();
        $familyOptions = $this->familyFormOptions->get();
        [$values, $errors, $data] = $this->validateRepresentativeForm(
            $input,
            $personOptions,
            $familyOptions,
        );

        if ($errors !== []) {
            return $this->representativeFormView(
                $values,
                $errors,
                $personOptions,
                $familyOptions,
                422,
            );
        }

        try {
            $result = $this->createRepresentativeFamily->handle(
                new CreateRepresentativeFamilyInput(...$data),
                new DateTimeImmutable('today'),
            );
        } catch (IdentificationAlreadyUsed) {
            return $this->representativeFormView(
                $values,
                ['A Person already uses that identification.'],
                $personOptions,
                $familyOptions,
                422,
            );
        } catch (InvalidPersonState) {
            return $this->representativeFormView(
                $values,
                ['Review the entered Person data.'],
                $personOptions,
                $familyOptions,
                422,
            );
        } catch (RepresentativeAlreadyExistsForPerson) {
            return $this->representativeFormView(
                $values,
                ['The Person already has a Representative role.'],
                $personOptions,
                $familyOptions,
                422,
            );
        } catch (InvalidRepresentativeState) {
            return $this->representativeFormView(
                $values,
                ['Review the entered Representative data.'],
                $personOptions,
                $familyOptions,
                422,
            );
        } catch (RelationshipTypeNotFound) {
            return $this->representativeFormView(
                $values,
                ['Select an active relationship type.'],
                $personOptions,
                $familyOptions,
                422,
            );
        } catch (InvalidFamilyState) {
            return $this->representativeFormView(
                $values,
                ['Review the entered Family data.'],
                $personOptions,
                $familyOptions,
                422,
            );
        } catch (
            InvalidPersistedPersonResult
            | InvalidPersistedRepresentativeResult
            | InvalidPersistedFamilyResult
        ) {
            return $this->representativeFormView(
                $values,
                ['The complete operation could not be confirmed. No data was saved.'],
                $personOptions,
                $familyOptions,
                422,
            );
        }

        $this->session->put(
            self::FLASH_SUCCESS_KEY,
            'Family and primary Representative created successfully.',
        );

        return $this->redirect('/families/show?id=' . $result->family->id, 303);
    }

    public function show(): string
    {
        $id = $this->positiveInteger((new Request())->query()['id'] ?? null);
        if ($id === null) {
            $this->session->put(self::FLASH_ERROR_KEY, 'Enter a valid positive Family ID.');

            return $this->redirect('/families');
        }

        try {
            $family = $this->getFamily->handle($id);
        } catch (FamilyNotFound) {
            return $this->notFound();
        }

        return $this->view('families.show', [
            'title' => 'Family details',
            'family' => $family,
            'successMessage' => $this->flashMessage(self::FLASH_SUCCESS_KEY),
        ]);
    }

    public function showCreateStudent(): string
    {
        $id = $this->positiveInteger((new Request())->query()['family_id'] ?? null);
        if ($id === null) {
            $this->session->put(self::FLASH_ERROR_KEY, 'Enter a valid positive Family ID.');

            return $this->redirect('/families');
        }

        try {
            $family = $this->getFamily->handle($id);
        } catch (FamilyNotFound) {
            return $this->notFound();
        }

        $state = $this->formState(self::STUDENT_FORM_STATE_KEY);
        $values = ($state['familyId'] ?? null) === $id
            ? ($state['values'] ?? $this->emptyStudentValues($id))
            : $this->emptyStudentValues($id);
        $errors = ($state['familyId'] ?? null) === $id ? ($state['errors'] ?? []) : [];
        $personOptions = $this->personFormOptions->get();
        if (!$personOptions->isReadyForSave()) {
            $errors[] = $this->personCatalogUnavailableMessage();
        }

        $this->session->put(self::TRUSTED_FAMILY_ID_KEY, $id);

        return $this->studentFormView($family, $values, $errors, $personOptions);
    }

    public function createStudent(): string
    {
        $input = (new Request())->input();
        $trustedFamilyId = $this->trustedFamilyId();

        if (!$this->csrf->isValid($this->scalarValue($input, '_csrf_token'))) {
            if ($trustedFamilyId !== null) {
                $this->session->put(self::TRUSTED_FAMILY_ID_KEY, $trustedFamilyId);
            }
            $this->storeFormState(
                self::STUDENT_FORM_STATE_KEY,
                $input,
                $this->studentFields(),
                ['Your form expired. Please try again.'],
                $trustedFamilyId,
            );

            return $this->redirect(
                $trustedFamilyId === null
                    ? '/families'
                    : '/families/students/create?family_id=' . $trustedFamilyId,
                303,
            );
        }

        if ($trustedFamilyId === null) {
            $this->session->put(
                self::FLASH_ERROR_KEY,
                'The Family selection expired. Open the Family again.',
            );

            return $this->redirect('/families', 303);
        }

        try {
            $family = $this->getFamily->handle($trustedFamilyId);
        } catch (FamilyNotFound) {
            return $this->notFound();
        }

        $personOptions = $this->personFormOptions->get();
        [$values, $errors, $data] = $this->validateStudentForm(
            $input,
            $trustedFamilyId,
            $personOptions,
        );

        if ($errors !== []) {
            $this->restoreTrustedFamilyId($trustedFamilyId);

            return $this->studentFormView($family, $values, $errors, $personOptions, 422);
        }

        try {
            $result = $this->createStudentInFamily->handle(
                new CreateStudentInFamilyInput(...$data),
                new DateTimeImmutable('today'),
            );
        } catch (FamilyNotFound) {
            return $this->notFound();
        } catch (IdentificationAlreadyUsed) {
            return $this->studentFailure(
                $family,
                $values,
                ['A Person already uses that identification.'],
                $personOptions,
                $trustedFamilyId,
            );
        } catch (InvalidPersonState) {
            return $this->studentFailure(
                $family,
                $values,
                ['Review the entered Person data.'],
                $personOptions,
                $trustedFamilyId,
            );
        } catch (StudentAlreadyExistsForPerson) {
            return $this->studentFailure(
                $family,
                $values,
                ['The Person already has a Student role.'],
                $personOptions,
                $trustedFamilyId,
            );
        } catch (InstitutionalCodeAlreadyUsed) {
            return $this->studentFailure(
                $family,
                $values,
                ['The institutional code is already in use.'],
                $personOptions,
                $trustedFamilyId,
            );
        } catch (InvalidStudentState) {
            return $this->studentFailure(
                $family,
                $values,
                ['Review the entered Student data.'],
                $personOptions,
                $trustedFamilyId,
            );
        } catch (StudentAlreadyHasActiveFamily) {
            return $this->studentFailure(
                $family,
                $values,
                ['The Student already has an active Family.'],
                $personOptions,
                $trustedFamilyId,
            );
        } catch (InvalidFamilyState) {
            return $this->studentFailure(
                $family,
                $values,
                ['Review the Family membership data.'],
                $personOptions,
                $trustedFamilyId,
            );
        } catch (InvalidPersistedPersonResult | InvalidPersistedStudentResult | InvalidPersistedFamilyResult) {
            return $this->studentFailure(
                $family,
                $values,
                ['The complete operation could not be confirmed. No data was saved.'],
                $personOptions,
                $trustedFamilyId,
            );
        }

        $this->session->put(self::FLASH_SUCCESS_KEY, 'Student added to Family successfully.');

        return $this->redirect('/families/show?id=' . $result->family->id, 303);
    }

    /**
     * @return array{0: array<string, string>, 1: list<string>, 2: array<string, mixed>}
     */
    private function validateRepresentativeForm(
        array $input,
        PersonFormOptions $personOptions,
        FamilyFormOptions $familyOptions,
    ): array {
        $errors = [];
        $values = $this->preservedValues($input, $this->representativeFields(), $errors);
        $person = $this->personData($values, $personOptions, $errors);

        $representativeStatus = RepresentativeStatus::tryFrom($values['representative_status']);
        if ($representativeStatus === null) {
            $errors[] = 'Select a valid Representative status.';
        }

        $workEmail = $this->nullableString($values['work_email']);
        if ($workEmail !== null && filter_var($workEmail, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'Enter a valid work email.';
        }

        if ($values['display_name'] === '') {
            $errors[] = 'Family display name is required.';
        }

        $familyStatus = FamilyStatus::tryFrom($values['family_status']);
        if ($familyStatus === null || !$familyOptions->hasStatus($familyStatus)) {
            $errors[] = 'Select a valid Family status.';
        }

        $relationshipTypeId = $this->positiveInteger($values['relationship_type_id']);
        if ($relationshipTypeId === null || !$familyOptions->hasRelationshipType($relationshipTypeId)) {
            $errors[] = 'Select an active relationship type.';
        }

        $startedAt = $this->timestampValue($values['started_at']);
        if ($startedAt === null) {
            $errors[] = 'Membership start must use the YYYY-MM-DDTHH:MM format.';
        }

        $errors = $this->catalogErrors($errors, $personOptions, $familyOptions);

        return [$values, array_values(array_unique($errors)), array_merge($person, [
            'occupation' => $this->nullableString($values['occupation']),
            'companyName' => $this->nullableString($values['company_name']),
            'position' => $this->nullableString($values['position']),
            'workPhone' => $this->nullableString($values['work_phone']),
            'workEmail' => $workEmail,
            'representativeStatus' => $representativeStatus,
            'displayName' => $values['display_name'],
            'familyStatus' => $familyStatus,
            'relationshipTypeId' => $relationshipTypeId,
            'startedAt' => $startedAt,
        ])];
    }

    /**
     * @return array{0: array<string, string>, 1: list<string>, 2: array<string, mixed>}
     */
    private function validateStudentForm(
        array $input,
        int $trustedFamilyId,
        PersonFormOptions $personOptions,
    ): array {
        $errors = [];
        $values = $this->preservedValues($input, $this->studentFields(), $errors);
        $person = $this->personData($values, $personOptions, $errors);
        $postedFamilyId = $this->positiveInteger($values['family_id']);
        if ($postedFamilyId !== $trustedFamilyId) {
            $errors[] = 'Family identity cannot be changed.';
        }

        if ($values['institutional_code'] === '') {
            $errors[] = 'Institutional code is required.';
        }

        $admissionDate = $this->dateValue($values['admission_date']);
        if ($admissionDate === null) {
            $errors[] = 'Admission date must use the YYYY-MM-DD format.';
        }

        $studentStatus = StudentStatus::tryFrom($values['student_status']);
        if ($studentStatus === null) {
            $errors[] = 'Select a valid Student status.';
        }

        $startedAt = $this->timestampValue($values['started_at']);
        if ($startedAt === null) {
            $errors[] = 'Membership start must use the YYYY-MM-DDTHH:MM format.';
        }

        if (!$personOptions->isReadyForSave()) {
            $errors[] = $this->personCatalogUnavailableMessage();
        }

        return [$values, array_values(array_unique($errors)), array_merge($person, [
            'familyId' => $trustedFamilyId,
            'institutionalCode' => $values['institutional_code'],
            'admissionDate' => $admissionDate,
            'studentStatus' => $studentStatus,
            'startedAt' => $startedAt,
        ])];
    }

    /** @return array<string, mixed> */
    private function personData(
        array $values,
        PersonFormOptions $options,
        array &$errors,
    ): array {
        if ($values['first_name'] === '') {
            $errors[] = 'First name is required.';
        }
        if ($values['first_surname'] === '') {
            $errors[] = 'First surname is required.';
        }

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

        $email = $this->nullableString($values['email']);
        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'Enter a valid email.';
        }

        $personStatus = PersonStatus::tryFrom($values['person_status']);
        if ($personStatus === null || !$options->hasStatus($values['person_status'])) {
            $errors[] = 'Select a valid Person status.';
        }

        return [
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
            'email' => $email,
            'mobilePhone' => $this->nullableString($values['mobile_phone']),
            'landlinePhone' => $this->nullableString($values['landline_phone']),
            'personStatus' => $personStatus,
        ];
    }

    private function representativeFormView(
        array $values,
        array $errors,
        PersonFormOptions $personOptions,
        FamilyFormOptions $familyOptions,
        int $status = 200,
    ): string {
        http_response_code($status);

        return $this->view('families.create-representative', [
            'title' => 'Create Representative and Family',
            'values' => $values,
            'errors' => $errors,
            'personOptions' => $personOptions,
            'familyOptions' => $familyOptions,
            'csrfToken' => $this->csrf->token(),
            'canSubmit' => $personOptions->isReadyForSave() && $familyOptions->isReadyForSave(),
        ]);
    }

    private function studentFormView(
        FamilyOutput $family,
        array $values,
        array $errors,
        PersonFormOptions $personOptions,
        int $status = 200,
    ): string {
        http_response_code($status);

        return $this->view('families.create-student', [
            'title' => 'Add Student to Family',
            'family' => $family,
            'values' => $values,
            'errors' => $errors,
            'personOptions' => $personOptions,
            'csrfToken' => $this->csrf->token(),
            'canSubmit' => $personOptions->isReadyForSave(),
        ]);
    }

    private function studentFailure(
        FamilyOutput $family,
        array $values,
        array $errors,
        PersonFormOptions $personOptions,
        int $trustedFamilyId,
    ): string {
        $this->restoreTrustedFamilyId($trustedFamilyId);

        return $this->studentFormView($family, $values, $errors, $personOptions, 422);
    }

    private function notFound(): string
    {
        http_response_code(404);

        return $this->view('families.not-found', ['title' => 'Family not found']);
    }

    /** @return list<string> */
    private function catalogErrors(
        array $errors,
        PersonFormOptions $personOptions,
        FamilyFormOptions $familyOptions,
    ): array {
        if (!$personOptions->isReadyForSave()) {
            $errors[] = $this->personCatalogUnavailableMessage();
        }
        if (!$familyOptions->isReadyForSave()) {
            $errors[] = 'Family cannot be saved because relationship types are unavailable.';
        }

        return array_values(array_unique($errors));
    }

    /** @return array<string, string> */
    private function emptyRepresentativeValues(): array
    {
        return array_merge($this->emptyPersonValues(), [
            'occupation' => '',
            'company_name' => '',
            'position' => '',
            'work_phone' => '',
            'work_email' => '',
            'representative_status' => 'ACTIVE',
            'display_name' => '',
            'family_status' => 'ACTIVE',
            'relationship_type_id' => '',
            'started_at' => (new DateTimeImmutable())->format('Y-m-d\TH:i'),
        ]);
    }

    /** @return array<string, string> */
    private function emptyStudentValues(int $familyId): array
    {
        return array_merge($this->emptyPersonValues(), [
            'family_id' => (string) $familyId,
            'institutional_code' => '',
            'admission_date' => (new DateTimeImmutable('today'))->format('Y-m-d'),
            'student_status' => 'ACTIVE',
            'started_at' => (new DateTimeImmutable())->format('Y-m-d\TH:i'),
        ]);
    }

    /** @return array<string, string> */
    private function emptyPersonValues(): array
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
            'person_status' => 'ACTIVE',
        ];
    }

    /** @return list<string> */
    private function representativeFields(): array
    {
        return array_keys($this->emptyRepresentativeValues());
    }

    /** @return list<string> */
    private function studentFields(): array
    {
        return array_keys($this->emptyStudentValues(0));
    }

    /** @return array<string, string> */
    private function preservedValues(array $input, array $fields, array &$errors = []): array
    {
        $values = [];
        foreach ($fields as $field) {
            $value = $input[$field] ?? '';
            if (!is_scalar($value) && $value !== null) {
                $errors[] = sprintf(
                    '%s must be a single value.',
                    str_replace('_', ' ', ucfirst($field)),
                );
                $values[$field] = '';

                continue;
            }

            $values[$field] = trim((string) $value);
        }

        return $values;
    }

    private function storeFormState(
        string $key,
        array $input,
        array $fields,
        array $errors,
        ?int $familyId = null,
    ): void {
        $state = [
            'values' => $this->preservedValues($input, $fields),
            'errors' => $errors,
        ];
        if ($familyId !== null) {
            $state['familyId'] = $familyId;
        }

        $this->session->put($key, $state);
    }

    /** @return array<string, mixed>|array{} */
    private function formState(string $key): array
    {
        $state = $this->session->pull($key);

        return is_array($state) ? $state : [];
    }

    private function trustedFamilyId(): ?int
    {
        $value = $this->session->pull(self::TRUSTED_FAMILY_ID_KEY);

        return is_int($value) && $value > 0 ? $value : null;
    }

    private function restoreTrustedFamilyId(int $familyId): void
    {
        $this->session->put(self::TRUSTED_FAMILY_ID_KEY, $familyId);
    }

    private function flashMessage(string $key): ?string
    {
        $message = $this->session->pull($key);

        return is_string($message) ? $message : null;
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

        return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value
            ? $date
            : null;
    }

    private function timestampValue(string $value): ?DateTimeImmutable
    {
        $timestamp = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value);

        return $timestamp instanceof DateTimeImmutable
            && $timestamp->format('Y-m-d\TH:i') === $value
            ? $timestamp
            : null;
    }

    private function nullableString(string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    private function personCatalogUnavailableMessage(): string
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
