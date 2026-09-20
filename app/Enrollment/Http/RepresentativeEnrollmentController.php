<?php

declare(strict_types=1);

namespace App\Enrollment\Http;

use App\Shared\Http\SafeErrorPage;
use App\AcademicCore\Application\AcademicPlacementReferenceProvider;
use App\Controllers\Controller;
use App\Enrollment\Application\Exception\EnrollmentAlreadyExists;
use App\Enrollment\Application\RepresentativePortal\Dto\ResolveOrStartRepresentativeEnrollmentInput;
use App\Enrollment\Application\RepresentativePortal\Dto\UpdateRepresentativeContactInformationInput;
use App\Enrollment\Application\RepresentativePortal\Dto\UpdateRepresentativeEmploymentInformationInput;
use App\Enrollment\Application\RepresentativePortal\Dto\UpdateRepresentativeEnrollmentBillingInput;
use App\Enrollment\Application\RepresentativePortal\Dto\UpdateRepresentativeEnrollmentLeaveAloneInput;
use App\Enrollment\Application\RepresentativePortal\Dto\UpdateRepresentativeEnrollmentMedicalInput;
use App\Enrollment\Application\RepresentativePortal\Dto\UpdateRepresentativeEnrollmentTransportInput;
use App\Enrollment\Application\RepresentativePortal\Dto\UpdateRepresentativePersonalInformationInput;
use App\Enrollment\Application\RepresentativePortal\Dto\UpdateStudentPersonalInformationInput;
use App\Enrollment\Application\RepresentativePortal\Exception\RepresentativeEnrollmentAcademicPeriodUnavailable;
use App\Enrollment\Application\RepresentativePortal\Exception\RepresentativeEnrollmentContextChanged;
use App\Enrollment\Application\RepresentativePortal\Exception\RepresentativeEnrollmentContextUnavailable;
use App\Enrollment\Application\RepresentativePortal\Exception\RepresentativeEnrollmentFamilySelectionRequired;
use App\Enrollment\Application\RepresentativePortal\Exception\RepresentativeEnrollmentStudentUnavailable;
use App\Enrollment\Application\RepresentativePortal\Exception\RepresentativeEnrollmentUnavailable;
use App\Enrollment\Application\RepresentativePortal\GetRepresentativeEnrollmentPortalState;
use App\Enrollment\Application\RepresentativePortal\ResolveOrStartRepresentativeEnrollment;
use App\Enrollment\Application\RepresentativePortal\UpdateAuthenticatedRepresentativeContactInformation;
use App\Enrollment\Application\RepresentativePortal\UpdateAuthenticatedRepresentativeEmploymentInformation;
use App\Enrollment\Application\RepresentativePortal\UpdateAuthenticatedRepresentativePersonalInformation;
use App\Enrollment\Application\RepresentativePortal\UpdateAuthorizedStudentPersonalInformation;
use App\Enrollment\Application\RepresentativePortal\UpdateRepresentativeEnrollmentBillingInformation;
use App\Enrollment\Application\RepresentativePortal\UpdateRepresentativeEnrollmentLeaveAloneAuthorization;
use App\Enrollment\Application\RepresentativePortal\UpdateRepresentativeEnrollmentMedicalInformation;
use App\Enrollment\Application\RepresentativePortal\UpdateRepresentativeEnrollmentTransportInformation;
use App\Enrollment\Domain\Exception\InvalidEnrollmentState;
use App\IdentityAccess\Application\Contract\CsrfTokenManager;
use App\IdentityAccess\Application\Contract\SessionManager;
use App\InstitutionalDocuments\Application\RepresentativePortal\Exception\RepresentativeAcknowledgementsRequired;
use App\Person\Http\PersonFormOptions;
use App\Person\Http\PersonFormOptionsProvider;
use App\Representative\Application\Exception\RepresentativeRequiresContactEmail;
use Core\Http\Request;
use DomainException;
use RuntimeException;
use Throwable;

final class RepresentativeEnrollmentController extends Controller
{
    private const FLASH_SUCCESS_KEY = '_flash_representative_enrollment_success';
    private const FLASH_ERROR_KEY = '_flash_representative_enrollment_error';

    public function __construct(
        private readonly GetRepresentativeEnrollmentPortalState $getState,
        private readonly ResolveOrStartRepresentativeEnrollment $resolveOrStart,
        private readonly UpdateAuthenticatedRepresentativePersonalInformation $updateRepresentativePersonal,
        private readonly UpdateAuthenticatedRepresentativeContactInformation $updateRepresentativeContact,
        private readonly UpdateAuthenticatedRepresentativeEmploymentInformation $updateRepresentativeEmployment,
        private readonly UpdateAuthorizedStudentPersonalInformation $updateStudentPersonal,
        private readonly UpdateRepresentativeEnrollmentBillingInformation $updateBilling,
        private readonly UpdateRepresentativeEnrollmentMedicalInformation $updateMedical,
        private readonly UpdateRepresentativeEnrollmentTransportInformation $updateTransport,
        private readonly UpdateRepresentativeEnrollmentLeaveAloneAuthorization $updateLeaveAlone,
        private readonly PersonFormOptionsProvider $formOptions,
        private readonly AcademicPlacementReferenceProvider $academicReferences,
        private readonly CsrfTokenManager $csrf,
        private readonly SessionManager $session,
        private readonly RepresentativeEnrollmentInputMapper $inputMapper,
        private readonly RepresentativeEnrollmentAutosaveResponder $autosaveResponder,
    ) {
    }

    public function index(): string
    {
        $query = (new Request())->query();
        $studentId = null;
        if (array_key_exists('student_id', $query)) {
            $studentId = $this->inputMapper->parsePositiveInteger($query['student_id']);
            if ($studentId === null) {
                return $this->error('El estudiante seleccionado no está disponible.', 422);
            }
        }

        try {
            return $this->portalView($studentId);
        } catch (RepresentativeEnrollmentFamilySelectionRequired) {
            return $this->redirect('/representative', 302);
        } catch (RepresentativeEnrollmentStudentUnavailable|RepresentativeEnrollmentContextUnavailable) {
            return $this->forbidden();
        } catch (Throwable) {
            return $this->error('No se pudo cargar la matrícula.', 422);
        }
    }

    public function open(): string
    {
        return $this->execute(
            ['expected_family_id', 'expected_academic_period_id', 'student_id'],
            function (array $values, array &$errors): ResolveOrStartRepresentativeEnrollmentInput {
                [$familyId, $periodId] = $this->context($values, $errors);
                $studentId = $this->inputMapper->positiveInteger($values, 'student_id', $errors);

                return new ResolveOrStartRepresentativeEnrollmentInput(
                    $familyId ?? 0,
                    $periodId ?? 0,
                    $studentId ?? 0,
                );
            },
            fn (object $input): mixed => $this->resolveOrStart->handle($input),
            'El borrador de matrícula está listo.',
            'draft',
            true,
        );
    }

    public function updateRepresentativePersonal(): string
    {
        return $this->execute(
            $this->personalFields(),
            function (array $values, array &$errors): UpdateRepresentativePersonalInformationInput {
                [$familyId, $periodId] = $this->context($values, $errors);
                $maritalStatusId = $this->inputMapper->optionalPositiveInteger(
                    $values,
                    'marital_status_id',
                    $errors,
                );
                $educationLevelId = $this->inputMapper->optionalPositiveInteger(
                    $values,
                    'education_level_id',
                    $errors,
                );
                $this->validatePersonOptions($maritalStatusId, $educationLevelId, $errors);

                return new UpdateRepresentativePersonalInformationInput(
                    $familyId ?? 0,
                    $periodId ?? 0,
                    $this->inputMapper->requiredString($values, 'first_name', $errors),
                    $this->inputMapper->optionalString($values, 'middle_name'),
                    $this->inputMapper->requiredString($values, 'first_surname', $errors),
                    $this->inputMapper->optionalString($values, 'second_surname'),
                    $this->inputMapper->date($values, 'birth_date', $errors)
                        ?? new \DateTimeImmutable('1970-01-01'),
                    $maritalStatusId,
                    $educationLevelId,
                );
            },
            fn (object $input): mixed => $this->updateRepresentativePersonal->handle($input),
            'Información personal guardada.',
            'representative-personal',
        );
    }

    public function updateRepresentativeContact(): string
    {
        return $this->execute(
            ['expected_family_id', 'expected_academic_period_id', 'student_id', 'email', 'mobile_phone', 'landline_phone'],
            function (array $values, array &$errors): UpdateRepresentativeContactInformationInput {
                [$familyId, $periodId] = $this->context($values, $errors);
                $email = $this->inputMapper->requiredString($values, 'email', $errors);
                if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    $errors[] = 'Ingrese un correo electrónico válido.';
                }

                return new UpdateRepresentativeContactInformationInput(
                    $familyId ?? 0,
                    $periodId ?? 0,
                    $email,
                    $this->inputMapper->optionalString($values, 'mobile_phone'),
                    $this->inputMapper->optionalString($values, 'landline_phone'),
                );
            },
            fn (object $input): mixed => $this->updateRepresentativeContact->handle($input),
            'Información de contacto guardada.',
            'representative-contact',
        );
    }

    public function updateRepresentativeEmployment(): string
    {
        return $this->execute(
            ['expected_family_id', 'expected_academic_period_id', 'student_id', 'occupation', 'company_name', 'position', 'work_phone', 'work_email'],
            function (array $values, array &$errors): UpdateRepresentativeEmploymentInformationInput {
                [$familyId, $periodId] = $this->context($values, $errors);
                $email = $this->inputMapper->optionalString($values, 'work_email');
                if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    $errors[] = 'Ingrese un correo laboral válido.';
                }

                return new UpdateRepresentativeEmploymentInformationInput(
                    $familyId ?? 0,
                    $periodId ?? 0,
                    $this->inputMapper->optionalString($values, 'occupation'),
                    $this->inputMapper->optionalString($values, 'company_name'),
                    $this->inputMapper->optionalString($values, 'position'),
                    $this->inputMapper->optionalString($values, 'work_phone'),
                    $email,
                );
            },
            fn (object $input): mixed => $this->updateRepresentativeEmployment->handle($input),
            'Información laboral guardada.',
            'representative-employment',
        );
    }

    public function updateStudentPersonal(): string
    {
        return $this->execute(
            $this->personalFields(),
            function (array $values, array &$errors): UpdateStudentPersonalInformationInput {
                [$familyId, $periodId] = $this->context($values, $errors);
                $studentId = $this->inputMapper->positiveInteger($values, 'student_id', $errors);
                $maritalStatusId = $this->inputMapper->optionalPositiveInteger(
                    $values,
                    'marital_status_id',
                    $errors,
                );
                $educationLevelId = $this->inputMapper->optionalPositiveInteger(
                    $values,
                    'education_level_id',
                    $errors,
                );
                $this->validatePersonOptions($maritalStatusId, $educationLevelId, $errors);

                return new UpdateStudentPersonalInformationInput(
                    $familyId ?? 0,
                    $periodId ?? 0,
                    $studentId ?? 0,
                    $this->inputMapper->requiredString($values, 'first_name', $errors),
                    $this->inputMapper->optionalString($values, 'middle_name'),
                    $this->inputMapper->requiredString($values, 'first_surname', $errors),
                    $this->inputMapper->optionalString($values, 'second_surname'),
                    $this->inputMapper->date($values, 'birth_date', $errors)
                        ?? new \DateTimeImmutable('1970-01-01'),
                    $maritalStatusId,
                    $educationLevelId,
                );
            },
            fn (object $input): mixed => $this->updateStudentPersonal->handle($input),
            'Información personal del estudiante guardada.',
            'student-personal',
            true,
        );
    }

    public function updateBilling(): string
    {
        return $this->execute(
            ['expected_family_id', 'expected_academic_period_id', 'student_id', 'identification_type_id', 'identification_number', 'legal_name', 'billing_address', 'billing_email', 'phone'],
            function (array $values, array &$errors): UpdateRepresentativeEnrollmentBillingInput {
                [$familyId, $periodId] = $this->context($values, $errors);
                $studentId = $this->inputMapper->positiveInteger($values, 'student_id', $errors);
                $identificationTypeId = $this->inputMapper->positiveInteger(
                    $values,
                    'identification_type_id',
                    $errors,
                );
                if ($identificationTypeId !== null
                    && !$this->formOptions->get()->hasDocumentType($identificationTypeId)
                ) {
                    $errors[] = 'Seleccione un tipo de identificación activo.';
                }
                $email = $this->inputMapper->requiredString($values, 'billing_email', $errors);
                if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    $errors[] = 'Ingrese un correo de facturación válido.';
                }

                return new UpdateRepresentativeEnrollmentBillingInput(
                    $familyId ?? 0,
                    $periodId ?? 0,
                    $studentId ?? 0,
                    $identificationTypeId ?? 0,
                    $this->inputMapper->requiredString($values, 'identification_number', $errors),
                    $this->inputMapper->requiredString($values, 'legal_name', $errors),
                    $this->inputMapper->requiredString($values, 'billing_address', $errors),
                    $email,
                    $this->inputMapper->requiredString($values, 'phone', $errors),
                );
            },
            fn (object $input): mixed => $this->updateBilling->handle($input),
            'Información de facturación guardada.',
            'billing',
            true,
        );
    }

    public function updateMedical(): string
    {
        return $this->execute(
            ['expected_family_id', 'expected_academic_period_id', 'student_id', 'has_medical_condition', 'medical_condition_detail', 'has_allergies', 'allergy_detail', 'takes_permanent_medication', 'medication_name', 'requires_special_care', 'special_care_detail', 'has_medical_insurance', 'insurance_provider', 'pediatrician_name', 'pediatrician_phone', 'observations'],
            function (array $values, array &$errors): UpdateRepresentativeEnrollmentMedicalInput {
                [$familyId, $periodId] = $this->context($values, $errors);
                $studentId = $this->inputMapper->positiveInteger($values, 'student_id', $errors);
                $medical = $this->inputMapper->boolean($values, 'has_medical_condition', $errors);
                $allergies = $this->inputMapper->boolean($values, 'has_allergies', $errors);
                $medication = $this->inputMapper->boolean($values, 'takes_permanent_medication', $errors);
                $care = $this->inputMapper->boolean($values, 'requires_special_care', $errors);
                $insurance = $this->inputMapper->boolean($values, 'has_medical_insurance', $errors);
                foreach ([
                    [$medical, 'medical_condition_detail', 'Medical condition detail'],
                    [$allergies, 'allergy_detail', 'Allergy detail'],
                    [$medication, 'medication_name', 'Medication name'],
                    [$care, 'special_care_detail', 'Special care detail'],
                    [$insurance, 'insurance_provider', 'Insurance provider'],
                ] as [$answer, $detailKey, $detailLabel]) {
                    if ($answer === true && $this->inputMapper->optionalString($values, $detailKey) === null) {
                        $errors[] = $detailLabel . ' is required when the answer is Yes.';
                    }
                }

                return new UpdateRepresentativeEnrollmentMedicalInput(
                    $familyId ?? 0,
                    $periodId ?? 0,
                    $studentId ?? 0,
                    $medical ?? false,
                    $this->inputMapper->optionalString($values, 'medical_condition_detail'),
                    $allergies ?? false,
                    $this->inputMapper->optionalString($values, 'allergy_detail'),
                    $medication ?? false,
                    $this->inputMapper->optionalString($values, 'medication_name'),
                    $care ?? false,
                    $this->inputMapper->optionalString($values, 'special_care_detail'),
                    $insurance ?? false,
                    $this->inputMapper->optionalString($values, 'insurance_provider'),
                    $this->inputMapper->optionalString($values, 'pediatrician_name'),
                    $this->inputMapper->optionalString($values, 'pediatrician_phone'),
                    $this->inputMapper->optionalString($values, 'observations'),
                );
            },
            fn (object $input): mixed => $this->updateMedical->handle($input),
            'Información médica guardada.',
            'medical',
            true,
        );
    }

    public function updateTransport(): string
    {
        return $this->booleanSection(
            'requires_institutional_transport',
            fn (int $family, int $period, int $student, bool $value): object =>
                new UpdateRepresentativeEnrollmentTransportInput($family, $period, $student, $value),
            fn (object $input): mixed => $this->updateTransport->handle($input),
            'Información de transporte guardada.',
            'transport',
        );
    }

    public function updateLeaveAlone(): string
    {
        return $this->booleanSection(
            'is_authorized_to_leave_alone',
            fn (int $family, int $period, int $student, bool $value): object =>
                new UpdateRepresentativeEnrollmentLeaveAloneInput($family, $period, $student, $value),
            fn (object $input): mixed => $this->updateLeaveAlone->handle($input),
            'Autorización de salida sin acompañante guardada.',
            'leave-alone',
        );
    }

    /**
     * @param list<string> $allowed
     * @param callable(array<string, string>, list<string>&): object $map
     * @param callable(object): mixed $handle
     */
    private function execute(
        array $allowed,
        callable $map,
        callable $handle,
        string $success,
        string $section,
        bool $studentRequired = false,
    ): string {
        $autosave = $section !== 'draft' && $this->autosaveResponder->isRequested();
        $request = (new Request())->input();
        if (!$this->csrf->isValid($this->rawScalar($request, '_csrf_token'))) {
            if ($autosave) {
                return $this->autosaveResponder->failure(
                    $section,
                    ['No se pudo verificar la solicitud.'],
                    403,
                );
            }

            return $this->error('No se pudo verificar la solicitud.', 403);
        }

        $errors = [];
        $values = $this->inputMapper->scalarValues($request, $allowed, $errors);
        $studentId = $this->inputMapper->parsePositiveInteger($values['student_id'] ?? null);
        if ($studentRequired && $studentId === null && !in_array('Seleccione un estudiante válido.', $errors, true)) {
            $errors[] = 'Seleccione un estudiante válido.';
        }
        $mapped = $map($values, $errors);
        if ($errors !== []) {
            return $this->commandFailure(
                $autosave,
                $studentId,
                $values,
                $errors,
                $section,
                422,
            );
        }

        try {
            $handle($mapped);
        } catch (RepresentativeAcknowledgementsRequired) {
            if ($autosave) {
                return $this->autosaveResponder->failure(
                    $section,
                    ['Complete las aceptaciones institucionales antes de actualizar la matrícula.'],
                    409,
                    $this->csrf->token(),
                    false,
                    '/representative/acknowledgements',
                );
            }
            $this->session->put(
                self::FLASH_ERROR_KEY,
                'Complete las aceptaciones institucionales antes de actualizar la matrícula.',
            );

            return $this->redirect('/representative/acknowledgements', 303);
        } catch (RepresentativeEnrollmentFamilySelectionRequired) {
            if ($autosave) {
                return $this->autosaveResponder->failure(
                    $section,
                    ['El contexto de la matrícula cambió. Recargue la página.'],
                    409,
                    $this->csrf->token(),
                    false,
                    '/representative',
                );
            }

            return $this->redirect('/representative', 303);
        } catch (RepresentativeEnrollmentAcademicPeriodUnavailable) {
            if ($autosave) {
                return $this->autosaveResponder->failure(
                    $section,
                    ['El contexto de la matrícula cambió. Recargue la página.'],
                    409,
                    $this->csrf->token(),
                    true,
                );
            }
            $this->session->put(self::FLASH_ERROR_KEY, 'No hay un período académico activo configurado.');

            return $this->redirect('/representative/enrollment', 303);
        } catch (RepresentativeEnrollmentContextChanged) {
            return $this->commandFailure(
                $autosave,
                $studentId,
                $values,
                [$autosave
                    ? 'El contexto de la matrícula cambió. Recargue la página.'
                    : 'El contexto de la matrícula cambió. Recargue la página e inténtelo nuevamente.'],
                $section,
                409,
                true,
            );
        } catch (RepresentativeEnrollmentStudentUnavailable|RepresentativeEnrollmentContextUnavailable) {
            if ($autosave) {
                return $this->autosaveResponder->failure(
                    $section,
                    ['El contexto de la matrícula cambió. Recargue la página.'],
                    409,
                    $this->csrf->token(),
                    true,
                );
            }

            return $this->forbidden();
        } catch (RepresentativeEnrollmentUnavailable) {
            return $this->commandFailure(
                $autosave,
                $studentId,
                $values,
                ['Esta matrícula ya no se puede editar.'],
                $section,
                409,
                true,
            );
        } catch (InvalidEnrollmentState) {
            try {
                $annualReadOnly = in_array($section, ['billing', 'medical', 'transport', 'leave-alone'], true)
                    && $studentId !== null
                    && !$this->getState->handle($studentId)->enrollmentDraftMaintenanceEnabled;
            } catch (Throwable) {
                $annualReadOnly = false;
            }

            return $this->commandFailure(
                $autosave,
                $studentId,
                $values,
                [$annualReadOnly ? 'Esta matrícula ya no se puede editar.' : 'Revise la información ingresada.'],
                $section,
                $annualReadOnly ? 409 : 422,
                $annualReadOnly,
            );
        } catch (EnrollmentAlreadyExists) {
            return $this->commandFailure(
                $autosave,
                $studentId,
                $values,
                [$autosave
                    ? 'El contexto de la matrícula cambió. Recargue la página.'
                    : 'La matrícula cambió mientras trabajaba. Recargue la página e inténtelo nuevamente.'],
                $section,
                409,
                true,
            );
        } catch (RepresentativeRequiresContactEmail|DomainException) {
            return $this->commandFailure(
                $autosave,
                $studentId,
                $values,
                ['Revise la información ingresada.'],
                $section,
                422,
            );
        } catch (RuntimeException) {
            if ($autosave) {
                return $this->autosaveResponder->failure(
                    $section,
                    ['No se pudo guardar la información.'],
                    500,
                    $this->csrf->token(),
                );
            }

            return $this->renderFailure(
                $studentId,
                $values,
                ['No se pudo confirmar la operación.'],
                $section,
                422,
            );
        } catch (Throwable) {
            if ($autosave) {
                return $this->autosaveResponder->failure(
                    $section,
                    ['No se pudo guardar la información.'],
                    500,
                    $this->csrf->token(),
                );
            }

            return $this->error('No se pudo completar la operación.', 500);
        }

        if ($autosave) {
            try {
                return $this->autosaveResponder->success(
                    $section,
                    $this->getState->handle($studentId),
                    $this->csrf->token(),
                );
            } catch (Throwable) {
                return $this->autosaveResponder->failure(
                    $section,
                    ['No se pudo guardar la información.'],
                    500,
                    $this->csrf->token(),
                );
            }
        }

        $this->session->put(self::FLASH_SUCCESS_KEY, $success);

        return $this->redirect($this->studentLocation($studentId), 303);
    }

    /** @param callable(int, int, int, bool): object $createInput */
    private function booleanSection(
        string $field,
        callable $createInput,
        callable $handle,
        string $success,
        string $section,
    ): string {
        return $this->execute(
            ['expected_family_id', 'expected_academic_period_id', 'student_id', $field],
            function (array $values, array &$errors) use ($field, $createInput): object {
                [$familyId, $periodId] = $this->context($values, $errors);
                $studentId = $this->inputMapper->positiveInteger($values, 'student_id', $errors);
                $value = $this->inputMapper->boolean($values, $field, $errors);

                return $createInput($familyId ?? 0, $periodId ?? 0, $studentId ?? 0, $value ?? false);
            },
            $handle,
            $success,
            $section,
            true,
        );
    }

    /** @param list<string> $errors @return array{?int, ?int} */
    private function context(array $values, array &$errors): array
    {
        return [
            $this->inputMapper->positiveInteger($values, 'expected_family_id', $errors),
            $this->inputMapper->positiveInteger($values, 'expected_academic_period_id', $errors),
        ];
    }

    /** @param list<string> $errors */
    private function validatePersonOptions(?int $maritalStatusId, ?int $educationLevelId, array &$errors): void
    {
        $options = $this->formOptions->get();
        if ($maritalStatusId !== null && !$options->hasMaritalStatus($maritalStatusId)) {
            $errors[] = 'Seleccione un estado civil activo.';
        }
        if ($educationLevelId !== null && !$options->hasEducationLevel($educationLevelId)) {
            $errors[] = 'Seleccione un nivel educativo activo.';
        }
    }

    /** @return list<string> */
    private function personalFields(): array
    {
        return [
            'expected_family_id', 'expected_academic_period_id', 'student_id',
            'first_name', 'middle_name', 'first_surname', 'second_surname', 'birth_date',
            'marital_status_id', 'education_level_id',
        ];
    }

    /** @param array<string, string> $values @param list<string> $errors */
    private function renderFailure(
        ?int $studentId,
        array $values,
        array $errors,
        string $section,
        int $status,
    ): string {
        try {
            return $this->portalView($studentId, $values, $errors, $section, $status);
        } catch (RepresentativeEnrollmentFamilySelectionRequired) {
            return $this->redirect('/representative', 303);
        } catch (RepresentativeEnrollmentStudentUnavailable|RepresentativeEnrollmentContextUnavailable) {
            return $this->forbidden();
        } catch (Throwable) {
            return $this->error('No se pudo cargar la matrícula.', 422);
        }
    }

    /** @param array<string, string> $values @param list<string> $errors */
    private function commandFailure(
        bool $autosave,
        ?int $studentId,
        array $values,
        array $errors,
        string $section,
        int $status,
        bool $reloadRequired = false,
    ): string {
        if ($autosave) {
            return $this->autosaveResponder->failure(
                $section,
                $errors,
                $status,
                $this->csrf->token(),
                $reloadRequired,
            );
        }

        return $this->renderFailure($studentId, $values, $errors, $section, $status);
    }

    /** @param array<string, string> $values @param list<string> $errors */
    private function portalView(
        ?int $studentId,
        array $values = [],
        array $errors = [],
        ?string $failedSection = null,
        int $status = 200,
    ): string {
        $state = $this->getState->handle($studentId);
        $placement = null;
        if ($state->enrollment?->academicPlacement !== null) {
            $grade = $this->academicReferences->findGradeById(
                $state->enrollment->academicPlacement->gradeId,
            );
            $section = $state->enrollment->academicPlacement->sectionId === null
                ? null
                : $this->academicReferences->findSectionById(
                    $state->enrollment->academicPlacement->sectionId,
                );
            if ($grade === null
                || ($state->enrollment->academicPlacement->sectionId !== null && $section === null)
            ) {
                throw new RuntimeException('AcademicPlacement references are unavailable.');
            }
            $placement = ['grade' => $grade, 'section' => $section];
        }

        http_response_code($status);

        return $this->view('representative-portal.enrollment', [
            'title' => 'Matrícula del representante',
            'state' => $state,
            'options' => $this->formOptions->get(),
            'academicPlacement' => $placement,
            'csrfToken' => $this->csrf->token(),
            'values' => $values,
            'errors' => $errors,
            'failedSection' => $failedSection,
            'successMessage' => $status === 200 ? $this->flash(self::FLASH_SUCCESS_KEY) : null,
            'errorMessage' => $status === 200 ? $this->flash(self::FLASH_ERROR_KEY) : null,
        ]);
    }

    private function rawScalar(array $input, string $key): string
    {
        $value = $input[$key] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function flash(string $key): ?string
    {
        $value = $this->session->pull($key);

        return is_string($value) ? $value : null;
    }

    private function studentLocation(?int $studentId): string
    {
        return $studentId === null
            ? '/representative/enrollment'
            : '/representative/enrollment?student_id=' . $studentId;
    }

    private function forbidden(): string
    {
        http_response_code(403);

        return $this->view('representative-portal.forbidden', [
            'title' => 'Matrícula no disponible',
            'csrfToken' => $this->csrf->token(),
        ]);
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
