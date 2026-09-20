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
use App\IdentityAccess\Application\Exception\InvalidPersistedUserResult;
use App\IdentityAccess\Application\Exception\InvalidRepresentativePassword;
use App\IdentityAccess\Application\Exception\RepresentativeLoginIdentifierAlreadyUsed;
use App\IdentityAccess\Application\Exception\RepresentativeUserAlreadyExists;
use App\IdentityAccess\Application\Exception\RepresentativeUserPersonNotFound;
use App\IdentityAccess\Application\Exception\RepresentativeUserRequiresIdentification;
use App\IdentityAccess\Domain\UserStatus;
use App\Person\Application\Exception\IdentificationAlreadyUsed;
use App\Person\Application\Exception\InvalidPersistedPersonResult;
use App\Person\Domain\Exception\InvalidPersonState;
use App\Person\Domain\PersonStatus;
use App\Person\Http\PersonFormOptions;
use App\Person\Http\PersonFormOptionsProvider;
use App\Representative\Application\Exception\InvalidPersistedRepresentativeResult;
use App\Representative\Application\Exception\RepresentativeAlreadyExistsForPerson;
use App\Representative\Application\Exception\RepresentativeRequiresContactEmail;
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
        private readonly FamilyMemberLabelsProvider $memberLabels,
    ) {
    }

    public function index(): string
    {
        return $this->view('families.index', [
            'title' => 'Familias',
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
                ['El formulario caducó. Inténtalo de nuevo.'],
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
                ['Otra persona ya utiliza esa identificación.'],
                $personOptions,
                $familyOptions,
                422,
            );
        } catch (InvalidPersonState) {
            return $this->representativeFormView(
                $values,
                ['Revisa los datos de la persona.'],
                $personOptions,
                $familyOptions,
                422,
            );
        } catch (RepresentativeAlreadyExistsForPerson) {
            return $this->representativeFormView(
                $values,
                ['La persona ya tiene un rol de representante.'],
                $personOptions,
                $familyOptions,
                422,
            );
        } catch (RepresentativeRequiresContactEmail) {
            return $this->representativeFormView(
                $values,
                ['El representante necesita un correo personal.'],
                $personOptions,
                $familyOptions,
                422,
            );
        } catch (InvalidRepresentativeState) {
            return $this->representativeFormView(
                $values,
                ['Revisa los datos del representante.'],
                $personOptions,
                $familyOptions,
                422,
            );
        } catch (RepresentativeUserPersonNotFound | RepresentativeUserRequiresIdentification) {
            return $this->representativeFormView(
                $values,
                ['La identidad de acceso del representante no pudo resolverse.'],
                $personOptions,
                $familyOptions,
                422,
            );
        } catch (RepresentativeUserAlreadyExists) {
            return $this->representativeFormView(
                $values,
                ['La persona representante ya tiene un usuario.'],
                $personOptions,
                $familyOptions,
                422,
            );
        } catch (RepresentativeLoginIdentifierAlreadyUsed) {
            return $this->representativeFormView(
                $values,
                ['El identificador de acceso derivado ya está en uso.'],
                $personOptions,
                $familyOptions,
                422,
            );
        } catch (InvalidRepresentativePassword) {
            return $this->representativeFormView(
                $values,
                ['La contraseña inicial debe contener al menos cinco caracteres.'],
                $personOptions,
                $familyOptions,
                422,
            );
        } catch (RelationshipTypeNotFound) {
            return $this->representativeFormView(
                $values,
                ['Selecciona un tipo de relación activo.'],
                $personOptions,
                $familyOptions,
                422,
            );
        } catch (InvalidFamilyState) {
            return $this->representativeFormView(
                $values,
                ['Revisa los datos de la familia.'],
                $personOptions,
                $familyOptions,
                422,
            );
        } catch (
            InvalidPersistedPersonResult
            | InvalidPersistedRepresentativeResult
            | InvalidPersistedUserResult
            | InvalidPersistedFamilyResult
        ) {
            return $this->representativeFormView(
                $values,
                ['No se pudo confirmar la operación completa. No se guardaron datos.'],
                $personOptions,
                $familyOptions,
                422,
            );
        }

        $this->session->put(
            self::FLASH_SUCCESS_KEY,
            'Familia, representante principal y usuario creados correctamente.',
        );

        return $this->redirect('/families/show?id=' . $result->family->id, 303);
    }

    public function show(): string
    {
        $id = $this->positiveInteger((new Request())->query()['id'] ?? null);
        if ($id === null) {
            $this->session->put(self::FLASH_ERROR_KEY, 'Ingresa un identificador válido de familia.');

            return $this->redirect('/families');
        }

        try {
            $family = $this->getFamily->handle($id);
        } catch (FamilyNotFound) {
            return $this->notFound();
        }

        return $this->view('families.show', [
            'title' => 'Detalle de familia',
            'family' => $family,
            'memberLabels' => $this->memberLabels->forFamily($family->id),
            'successMessage' => $this->flashMessage(self::FLASH_SUCCESS_KEY),
        ]);
    }

    public function showCreateStudent(): string
    {
        $id = $this->positiveInteger((new Request())->query()['family_id'] ?? null);
        if ($id === null) {
            $this->session->put(self::FLASH_ERROR_KEY, 'Ingresa un identificador válido de familia.');

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
                ['El formulario caducó. Inténtalo de nuevo.'],
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
                'La selección de familia caducó. Abre la familia nuevamente.',
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
                ['Otra persona ya utiliza esa identificación.'],
                $personOptions,
                $trustedFamilyId,
            );
        } catch (InvalidPersonState) {
            return $this->studentFailure(
                $family,
                $values,
                ['Revisa los datos de la persona.'],
                $personOptions,
                $trustedFamilyId,
            );
        } catch (StudentAlreadyExistsForPerson) {
            return $this->studentFailure(
                $family,
                $values,
                ['La persona ya tiene un rol de estudiante.'],
                $personOptions,
                $trustedFamilyId,
            );
        } catch (InstitutionalCodeAlreadyUsed) {
            return $this->studentFailure(
                $family,
                $values,
                ['El código institucional ya está en uso.'],
                $personOptions,
                $trustedFamilyId,
            );
        } catch (InvalidStudentState) {
            return $this->studentFailure(
                $family,
                $values,
                ['Revisa los datos del estudiante.'],
                $personOptions,
                $trustedFamilyId,
            );
        } catch (StudentAlreadyHasActiveFamily) {
            return $this->studentFailure(
                $family,
                $values,
                ['El estudiante ya tiene una familia activa.'],
                $personOptions,
                $trustedFamilyId,
            );
        } catch (InvalidFamilyState) {
            return $this->studentFailure(
                $family,
                $values,
                ['Revisa los datos de la membresía familiar.'],
                $personOptions,
                $trustedFamilyId,
            );
        } catch (InvalidPersistedPersonResult | InvalidPersistedStudentResult | InvalidPersistedFamilyResult) {
            return $this->studentFailure(
                $family,
                $values,
                ['No se pudo confirmar la operación completa. No se guardaron datos.'],
                $personOptions,
                $trustedFamilyId,
            );
        }

        $this->session->put(self::FLASH_SUCCESS_KEY, 'Estudiante agregado a la familia correctamente.');

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
        if ($person['email'] === null) {
            $errors[] = 'El representante necesita un correo personal.';
        }
        if ($person['documentTypeId'] === null || $person['documentNumber'] === null) {
            $errors[] = 'El usuario representante requiere identificación completa.';
        }

        $initialPassword = $this->sensitiveScalar(
            $input,
            'initial_password',
            'Initial password',
            $errors,
        );
        $passwordConfirmation = $this->sensitiveScalar(
            $input,
            'initial_password_confirmation',
            'Initial password confirmation',
            $errors,
        );
        if ($initialPassword !== $passwordConfirmation) {
            $errors[] = 'Initial password confirmation does not match.';
        }
        $userStatus = UserStatus::tryFrom($values['user_status']);
        if ($userStatus === null) {
            $errors[] = 'Selecciona un estado de usuario válido.';
        }

        $representativeStatus = RepresentativeStatus::tryFrom($values['representative_status']);
        if ($representativeStatus === null) {
            $errors[] = 'Selecciona un estado de representante válido.';
        }

        $workEmail = $this->nullableString($values['work_email']);
        if ($workEmail !== null && filter_var($workEmail, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'Ingresa un correo laboral válido.';
        }

        if ($values['display_name'] === '') {
            $errors[] = 'El nombre visible de la familia es obligatorio.';
        }

        $familyStatus = FamilyStatus::tryFrom($values['family_status']);
        if ($familyStatus === null || !$familyOptions->hasStatus($familyStatus)) {
            $errors[] = 'Selecciona un estado de familia válido.';
        }

        $relationshipTypeId = $this->positiveInteger($values['relationship_type_id']);
        if ($relationshipTypeId === null || !$familyOptions->hasRelationshipType($relationshipTypeId)) {
            $errors[] = 'Selecciona un tipo de relación activo.';
        }

        $startedAt = $this->timestampValue($values['started_at']);
        if ($startedAt === null) {
            $errors[] = 'El inicio de membresía debe tener el formato AAAA-MM-DDTHH:MM.';
        }

        $errors = $this->catalogErrors($errors, $personOptions, $familyOptions);

        return [$values, array_values(array_unique($errors)), array_merge($person, [
            'occupation' => $this->nullableString($values['occupation']),
            'companyName' => $this->nullableString($values['company_name']),
            'position' => $this->nullableString($values['position']),
            'workPhone' => $this->nullableString($values['work_phone']),
            'workEmail' => $workEmail,
            'representativeStatus' => $representativeStatus,
            'initialPassword' => $initialPassword,
            'userStatus' => $userStatus,
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
            $errors[] = 'No se puede cambiar la identidad de la familia.';
        }

        if ($values['institutional_code'] === '') {
            $errors[] = 'Institutional code is required.';
        }

        $admissionDate = $this->dateValue($values['admission_date']);
        if ($admissionDate === null) {
            $errors[] = 'La fecha de admisión debe tener el formato AAAA-MM-DD.';
        }

        $studentStatus = StudentStatus::tryFrom($values['student_status']);
        if ($studentStatus === null) {
            $errors[] = 'Selecciona un estado de estudiante válido.';
        }

        $startedAt = $this->timestampValue($values['started_at']);
        if ($startedAt === null) {
            $errors[] = 'El inicio de membresía debe tener el formato AAAA-MM-DDTHH:MM.';
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
            $errors[] = 'La fecha de nacimiento debe tener el formato AAAA-MM-DD.';
        }

        $sexId = $this->positiveInteger($values['sex_id']);
        if ($sexId === null || !$options->hasSex($sexId)) {
            $errors[] = 'Selecciona un sexo válido.';
        }

        $documentTypeId = $this->optionalPositiveInteger(
            $values['document_type_id'],
            'tipo de documento',
            $errors,
        );
        $maritalStatusId = $this->optionalPositiveInteger(
            $values['marital_status_id'],
            'estado civil',
            $errors,
        );
        $educationLevelId = $this->optionalPositiveInteger(
            $values['education_level_id'],
            'nivel educativo',
            $errors,
        );

        if ($documentTypeId !== null && !$options->hasDocumentType($documentTypeId)) {
            $errors[] = 'Selecciona un tipo de documento válido.';
        }
        if ($maritalStatusId !== null && !$options->hasMaritalStatus($maritalStatusId)) {
            $errors[] = 'Selecciona un estado civil válido.';
        }
        if ($educationLevelId !== null && !$options->hasEducationLevel($educationLevelId)) {
            $errors[] = 'Selecciona un nivel educativo válido.';
        }

        $documentNumber = $this->nullableString($values['document_number']);
        if (($documentTypeId === null) !== ($documentNumber === null)) {
            $errors[] = 'Indica tanto el tipo como el número de documento, o deja ambos vacíos.';
        }

        $email = $this->nullableString($values['email']);
        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'Ingresa un correo válido.';
        }

        $personStatus = PersonStatus::tryFrom($values['person_status']);
        if ($personStatus === null || !$options->hasStatus($values['person_status'])) {
            $errors[] = 'Selecciona un estado de persona válido.';
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
            'title' => 'Crear representante y familia',
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
            'title' => 'Agregar estudiante a familia',
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

        return $this->view('families.not-found', ['title' => 'Familia no encontrada']);
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
            $errors[] = 'No se puede guardar la familia porque faltan tipos de relación.';
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
            'user_status' => UserStatus::Active->value,
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

    private function sensitiveScalar(
        array $input,
        string $key,
        string $label,
        array &$errors,
    ): string {
        $value = $input[$key] ?? '';
        if (!is_string($value)) {
            $errors[] = $label . ' must be a single value.';

            return '';
        }

        return $value;
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
            $errors[] = sprintf('Selecciona un valor válido para %s.', $label);
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
        return 'No se puede guardar la persona porque faltan catálogos necesarios.';
    }

    private function redirect(string $location, int $status = 302): string
    {
        header('Location: ' . $location);
        http_response_code($status);

        return '';
    }
}
