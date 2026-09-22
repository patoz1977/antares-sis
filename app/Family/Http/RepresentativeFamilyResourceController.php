<?php

declare(strict_types=1);

namespace App\Family\Http;

use App\Shared\Http\SafeErrorPage;
use App\Controllers\Controller;
use App\Family\Application\Exception\DocumentTypeNotFound;
use App\Family\Application\Exception\FamilyNotFound;
use App\Family\Application\Exception\InvalidPersistedFamilyResult;
use App\Family\Application\Exception\RelationshipTypeNotFound;
use App\Family\Application\RepresentativeResources\Dto\RepresentativeFamilyResourcesOutput;
use App\Family\Application\RepresentativeResources\Exception\RepresentativeFamilyAddressModificationNotAllowed;
use App\Family\Application\RepresentativeResources\Exception\RepresentativeFamilyContextChanged;
use App\Family\Application\RepresentativeResources\Exception\RepresentativeFamilyContextUnavailable;
use App\Family\Application\RepresentativeResources\Exception\RepresentativeFamilyResourceUnavailable;
use App\Family\Application\RepresentativeResources\Exception\RepresentativeFamilySelectionRequired;
use App\Family\Application\RepresentativeResources\Exception\RepresentativeFamilyStudentUnavailable;
use App\Family\Application\RepresentativeResources\GetRepresentativeFamilyResources;
use App\Family\Application\RepresentativeResources\RepresentativeFamilyAddressService;
use App\Family\Application\RepresentativeResources\RepresentativeFamilyAuthorizedPickupService;
use App\Family\Application\RepresentativeResources\RepresentativeFamilyEmergencyContactService;
use App\Family\Domain\Exception\InvalidFamilyState;
use App\IdentityAccess\Application\Contract\CsrfTokenManager;
use App\IdentityAccess\Application\Contract\Clock;
use App\IdentityAccess\Application\Contract\SessionManager;
use App\InstitutionalDocuments\Application\RepresentativePortal\Exception\ActiveAcademicPeriodUnavailable;
use App\InstitutionalDocuments\Application\RepresentativePortal\Exception\RepresentativeAcknowledgementAccessUnavailable;
use App\InstitutionalDocuments\Application\RepresentativePortal\Exception\RepresentativeAcknowledgementsRequired;
use App\Person\Application\Exception\PersonNotFound;
use App\Student\Application\Exception\StudentNotFound;
use Core\Http\Request;
use DateTimeImmutable;

final class RepresentativeFamilyResourceController extends Controller
{
    private const FLASH_SUCCESS_KEY = '_flash_representative_family_resources_success';
    private const FLASH_ERROR_KEY = '_flash_representative_family_resources_error';

    public function __construct(
        private readonly GetRepresentativeFamilyResources $getResources,
        private readonly RepresentativeFamilyAddressService $addresses,
        private readonly RepresentativeFamilyEmergencyContactService $emergencyContacts,
        private readonly RepresentativeFamilyAuthorizedPickupService $authorizedPickups,
        private readonly CsrfTokenManager $csrf,
        private readonly SessionManager $session,
        private readonly FamilyResourceFormOptionsProvider $optionsProvider,
        private readonly Clock $clock,
    ) {
    }

    public function index(): string
    {
        return $this->redirect('/representative/data', 302);
    }

    public function addresses(): string
    {
        return $this->show('addresses');
    }

    public function emergencyContacts(): string
    {
        return $this->show('emergency-contacts');
    }

    public function authorizedPickups(): string
    {
        return $this->show('authorized-pickups');
    }

    private function show(string $screen): string
    {
        try {
            $resources = $this->getResources->handle();
        } catch (RepresentativeAcknowledgementsRequired) {
            return $this->redirect('/representative/acknowledgements', 303);
        } catch (ActiveAcademicPeriodUnavailable) {
            return $this->redirect('/representative', 303);
        } catch (RepresentativeAcknowledgementAccessUnavailable) {
            return $this->forbidden();
        } catch (RepresentativeFamilySelectionRequired) {
            return $this->redirect('/representative', 302);
        } catch (RepresentativeFamilyContextUnavailable|FamilyNotFound|StudentNotFound|PersonNotFound) {
            return $this->forbidden();
        } catch (InvalidPersistedFamilyResult) {
            return $this->contextError('No se pudo confirmar la operación.', 422);
        }

        return $this->resourceView(
            $resources,
            $this->optionsProvider->get(),
            [],
            [],
            $this->flash(self::FLASH_SUCCESS_KEY),
            $this->flash(self::FLASH_ERROR_KEY),
            200,
            $screen,
        );
    }

    public function createAddress(): string
    {
        return $this->execute(
            fn (array $values): array => $this->addressInput($values, false),
            fn (int $familyId, array $data): mixed => $this->addresses->create($familyId, ...$data),
            'Dirección creada correctamente.',
        );
    }

    public function updateAddress(): string
    {
        return $this->execute(
            fn (array $values): array => $this->addressInput($values, true),
            fn (int $familyId, array $data): mixed => $this->addresses->update($familyId, ...$data),
            'Dirección actualizada correctamente.',
        );
    }

    public function activateAddress(): string
    {
        return $this->resourceStatus(
            'family_address_id',
            fn (int $familyId, int $id): mixed => $this->addresses->activate($familyId, $id),
            'Dirección activada correctamente.',
        );
    }

    public function deactivateAddress(): string
    {
        return $this->resourceStatus(
            'family_address_id',
            fn (int $familyId, int $id): mixed => $this->addresses->deactivate($familyId, $id),
            'Dirección desactivada correctamente.',
        );
    }

    public function assignRepresentativeAddress(): string
    {
        return $this->execute(
            function (array $values): array {
                $errors = [];
                $addressId = $this->requiredPositiveInteger(
                    $values,
                    'family_address_id',
                    'El recurso seleccionado no está disponible para esta familia.',
                    $errors,
                );
                $startedAt = $this->clock->now();

                return [$errors, [$addressId, $startedAt]];
            },
            fn (int $familyId, array $data): mixed => $this->addresses->assignSelf($familyId, ...$data),
            'Su dirección fue asignada correctamente.',
        );
    }

    public function endRepresentativeAddress(): string
    {
        return $this->endAssignment(
            fn (int $familyId, int $assignmentId, DateTimeImmutable $endedAt): mixed =>
                $this->addresses->endSelf($familyId, $assignmentId, $endedAt),
            'Su asignación de dirección finalizó correctamente.',
        );
    }

    public function assignStudentAddress(): string
    {
        return $this->execute(
            function (array $values): array {
                $errors = [];
                $studentId = $this->requiredPositiveInteger(
                    $values,
                    'student_id',
                    'El recurso seleccionado no está disponible para esta familia.',
                    $errors,
                );
                $addressId = $this->requiredPositiveInteger(
                    $values,
                    'family_address_id',
                    'El recurso seleccionado no está disponible para esta familia.',
                    $errors,
                );
                $startedAt = $this->clock->now();

                return [$errors, [$studentId, $addressId, $startedAt]];
            },
            fn (int $familyId, array $data): mixed => $this->addresses->assignStudent($familyId, ...$data),
            'Dirección asignada al estudiante correctamente.',
        );
    }

    public function endStudentAddress(): string
    {
        return $this->endAssignment(
            fn (int $familyId, int $assignmentId, DateTimeImmutable $endedAt): mixed =>
                $this->addresses->endStudent($familyId, $assignmentId, $endedAt),
            'Asignación de dirección al estudiante finalizada correctamente.',
        );
    }

    public function createEmergencyContact(): string
    {
        return $this->execute(
            fn (array $values, FamilyResourceFormOptions $options): array =>
                $this->emergencyContactInput($values, $options, false),
            fn (int $familyId, array $data): mixed => $this->emergencyContacts->create($familyId, ...$data),
            'Contacto de emergencia creado correctamente.',
        );
    }

    public function updateEmergencyContact(): string
    {
        return $this->execute(
            fn (array $values, FamilyResourceFormOptions $options): array =>
                $this->emergencyContactInput($values, $options, true),
            fn (int $familyId, array $data): mixed => $this->emergencyContacts->update($familyId, ...$data),
            'Contacto de emergencia actualizado correctamente.',
        );
    }

    public function activateEmergencyContact(): string
    {
        return $this->resourceStatus(
            'family_emergency_contact_id',
            fn (int $familyId, int $id): mixed => $this->emergencyContacts->activate($familyId, $id),
            'Contacto de emergencia activado correctamente.',
        );
    }

    public function deactivateEmergencyContact(): string
    {
        return $this->resourceStatus(
            'family_emergency_contact_id',
            fn (int $familyId, int $id): mixed => $this->emergencyContacts->deactivate($familyId, $id),
            'Contacto de emergencia desactivado correctamente.',
        );
    }

    public function assignEmergencyContact(): string
    {
        return $this->execute(
            function (array $values): array {
                $errors = [];
                $contactId = $this->requiredPositiveInteger(
                    $values,
                    'family_emergency_contact_id',
                    'El recurso seleccionado no está disponible para esta familia.',
                    $errors,
                );
                $studentId = $this->requiredPositiveInteger(
                    $values,
                    'student_id',
                    'El recurso seleccionado no está disponible para esta familia.',
                    $errors,
                );
                $priority = $this->optionalPositiveInteger($values['priority'] ?? '', 'priority', $errors);
                if ($priority !== null && $priority > 10) {
                    $errors[] = 'Seleccione una prioridad entre 1 y 10.';
                }
                $startedAt = $this->clock->now();

                return [$errors, [$contactId, $studentId, $priority, $startedAt]];
            },
            fn (int $familyId, array $data): mixed => $this->emergencyContacts->assign($familyId, ...$data),
            'Contacto de emergencia asignado correctamente.',
        );
    }

    public function endEmergencyContact(): string
    {
        return $this->endAssignment(
            fn (int $familyId, int $assignmentId, DateTimeImmutable $endedAt): mixed =>
                $this->emergencyContacts->end($familyId, $assignmentId, $endedAt),
            'Asignación de contacto de emergencia finalizada correctamente.',
        );
    }

    public function createAuthorizedPickup(): string
    {
        return $this->execute(
            fn (array $values, FamilyResourceFormOptions $options): array =>
                $this->authorizedPickupInput($values, $options, false),
            fn (int $familyId, array $data): mixed => $this->authorizedPickups->create($familyId, ...$data),
            'Persona autorizada para retirar creada correctamente.',
        );
    }

    public function updateAuthorizedPickup(): string
    {
        return $this->execute(
            fn (array $values, FamilyResourceFormOptions $options): array =>
                $this->authorizedPickupInput($values, $options, true),
            fn (int $familyId, array $data): mixed => $this->authorizedPickups->update($familyId, ...$data),
            'Persona autorizada para retirar actualizada correctamente.',
        );
    }

    public function activateAuthorizedPickup(): string
    {
        return $this->resourceStatus(
            'family_authorized_pickup_id',
            fn (int $familyId, int $id): mixed => $this->authorizedPickups->activate($familyId, $id),
            'Persona autorizada para retirar activada correctamente.',
        );
    }

    public function deactivateAuthorizedPickup(): string
    {
        return $this->resourceStatus(
            'family_authorized_pickup_id',
            fn (int $familyId, int $id): mixed => $this->authorizedPickups->deactivate($familyId, $id),
            'Persona autorizada para retirar desactivada correctamente.',
        );
    }

    public function assignAuthorizedPickup(): string
    {
        return $this->execute(
            function (array $values): array {
                $errors = [];
                $pickupId = $this->requiredPositiveInteger(
                    $values,
                    'family_authorized_pickup_id',
                    'El recurso seleccionado no está disponible para esta familia.',
                    $errors,
                );
                $studentId = $this->requiredPositiveInteger(
                    $values,
                    'student_id',
                    'El recurso seleccionado no está disponible para esta familia.',
                    $errors,
                );
                $startedAt = $this->clock->now();

                return [$errors, [$pickupId, $studentId, $startedAt]];
            },
            fn (int $familyId, array $data): mixed => $this->authorizedPickups->assign($familyId, ...$data),
            'Persona autorizada para retirar asignada correctamente.',
        );
    }

    public function endAuthorizedPickup(): string
    {
        return $this->endAssignment(
            fn (int $familyId, int $assignmentId, DateTimeImmutable $endedAt): mixed =>
                $this->authorizedPickups->end($familyId, $assignmentId, $endedAt),
            'Asignación de persona autorizada para retirar finalizada correctamente.',
        );
    }

    /**
     * @param callable(array<string, string>, FamilyResourceFormOptions): array{list<string>, array<int, mixed>} $validate
     * @param callable(int, array<int, mixed>): mixed $handle
     */
    private function execute(callable $validate, callable $handle, string $success): string
    {
        $input = (new Request())->input();
        $scalarErrors = [];
        $values = $this->safeValues($input, $scalarErrors);

        if (!$this->csrf->isValid($this->scalar($input, '_csrf_token'))) {
            $this->session->put(self::FLASH_ERROR_KEY, 'El formulario venció. Inténtelo nuevamente.');

            return $this->redirect($this->resourceLocation(), 303);
        }

        $familyId = $this->positiveInteger($values['family_id'] ?? null);
        if ($familyId === null) {
            $scalarErrors[] = 'El recurso seleccionado no está disponible para esta familia.';
        }
        $options = $this->optionsProvider->get();
        [$errors, $data] = $validate($values, $options);
        $errors = array_merge($scalarErrors, $errors);
        if ($errors !== []) {
            return $this->renderFailure($values, $errors);
        }

        try {
            $handle($familyId, $data);
        } catch (RepresentativeAcknowledgementsRequired) {
            $this->session->put(
                self::FLASH_ERROR_KEY,
                'Complete los acuses institucionales antes de actualizar los datos familiares.',
            );

            return $this->redirect('/representative/acknowledgements', 303);
        } catch (ActiveAcademicPeriodUnavailable) {
            $this->session->put(
                self::FLASH_ERROR_KEY,
                'No hay un período académico activo configurado.',
            );

            return $this->redirect('/representative', 303);
        } catch (RepresentativeAcknowledgementAccessUnavailable) {
            return $this->forbidden();
        } catch (RepresentativeFamilySelectionRequired) {
            return $this->redirect('/representative', 303);
        } catch (RepresentativeFamilyContextUnavailable|RepresentativeFamilyContextChanged
            |FamilyNotFound|StudentNotFound|PersonNotFound) {
            return $this->forbidden();
        } catch (RepresentativeFamilyAddressModificationNotAllowed) {
            return $this->renderFailure($values, ['No puede cambiar esta dirección desde su cuenta.']);
        } catch (InvalidFamilyState $error) {
            return $this->renderFailure($values, [FamilyResourceFeedback::forInvalidState($error)]);
        } catch (RepresentativeFamilyResourceUnavailable|RepresentativeFamilyStudentUnavailable) {
            return $this->renderFailure($values, ['El recurso seleccionado no está disponible para esta familia.']);
        } catch (RelationshipTypeNotFound) {
            return $this->renderFailure($values, ['Seleccione un parentesco activo.']);
        } catch (DocumentTypeNotFound) {
            return $this->renderFailure($values, ['Seleccione un tipo de documento activo.']);
        } catch (InvalidPersistedFamilyResult) {
            return $this->renderFailure($values, ['No se pudo confirmar la operación.']);
        }

        $this->session->put(self::FLASH_SUCCESS_KEY, $success);

        return $this->redirect($this->resourceLocation(), 303);
    }

    /** @param callable(int, int): mixed $handle */
    private function resourceStatus(string $field, callable $handle, string $success): string
    {
        return $this->execute(
            function (array $values) use ($field): array {
                $errors = [];
                $id = $this->requiredPositiveInteger(
                    $values,
                    $field,
                    'El recurso seleccionado no está disponible para esta familia.',
                    $errors,
                );

                return [$errors, [$id]];
            },
            fn (int $familyId, array $data): mixed => $handle($familyId, $data[0]),
            $success,
        );
    }

    /** @param callable(int, int, DateTimeImmutable): mixed $handle */
    private function endAssignment(callable $handle, string $success): string
    {
        return $this->execute(
            function (array $values): array {
                $errors = [];
                $assignmentId = $this->requiredPositiveInteger(
                    $values,
                    'assignment_id',
                    'El recurso seleccionado no está disponible para esta familia.',
                    $errors,
                );
                $endedAt = $this->clock->now();

                return [$errors, [$assignmentId, $endedAt]];
            },
            fn (int $familyId, array $data): mixed => $handle($familyId, $data[0], $data[1]),
            $success,
        );
    }

    /** @return array{list<string>, array<int, mixed>} */
    private function addressInput(array $values, bool $updating): array
    {
        $errors = [];
        $data = [];
        if ($updating) {
            $data[] = $this->requiredPositiveInteger(
                $values,
                'family_address_id',
                'El recurso seleccionado no está disponible para esta familia.',
                $errors,
            );
        }
        $label = $values['label'] ?? '';
        $mainStreet = $values['main_street'] ?? '';
        if ($label === '') {
            $errors[] = 'El nombre de la dirección es obligatorio.';
        }
        if ($mainStreet === '') {
            $errors[] = 'La calle principal es obligatoria.';
        }
        $latitude = $this->nullable($values['latitude'] ?? '');
        $longitude = $this->nullable($values['longitude'] ?? '');
        if (($latitude === null) !== ($longitude === null)) {
            $errors[] = 'Ingrese la latitud y la longitud juntas.';
        }
        if ($latitude !== null && !is_numeric($latitude)) {
            $errors[] = 'La latitud debe ser numérica.';
        }
        if ($longitude !== null && !is_numeric($longitude)) {
            $errors[] = 'La longitud debe ser numérica.';
        }

        return [$errors, array_merge($data, [
            $label,
            $mainStreet,
            $this->nullable($values['street_number'] ?? ''),
            $this->nullable($values['secondary_street'] ?? ''),
            $this->nullable($values['sector'] ?? ''),
            $this->nullable($values['reference'] ?? ''),
            $latitude,
            $longitude,
        ])];
    }

    /** @return array{list<string>, array<int, mixed>} */
    private function emergencyContactInput(
        array $values,
        FamilyResourceFormOptions $options,
        bool $updating,
    ): array {
        $errors = [];
        $data = [];
        if ($updating) {
            $data[] = $this->requiredPositiveInteger(
                $values,
                'family_emergency_contact_id',
                'El recurso seleccionado no está disponible para esta familia.',
                $errors,
            );
        }
        $names = $values['names'] ?? '';
        $mobilePhone = $values['mobile_phone'] ?? '';
        if ($names === '') {
            $errors[] = 'Los nombres son obligatorios.';
        }
        if ($mobilePhone === '') {
            $errors[] = 'El teléfono móvil es obligatorio.';
        }
        $relationshipTypeId = $this->positiveInteger($values['relationship_type_id'] ?? null);
        if ($relationshipTypeId === null || !$options->hasRelationshipType($relationshipTypeId)) {
            $errors[] = 'Seleccione un parentesco activo.';
        }
        $email = $this->nullable($values['email'] ?? '');
        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'Ingrese un correo electrónico válido.';
        }

        return [$errors, array_merge($data, [
            $names,
            $relationshipTypeId,
            $mobilePhone,
            $this->nullable($values['phone'] ?? ''),
            $email,
            $this->nullable($values['observations'] ?? ''),
        ])];
    }

    /** @return array{list<string>, array<int, mixed>} */
    private function authorizedPickupInput(
        array $values,
        FamilyResourceFormOptions $options,
        bool $updating,
    ): array {
        $errors = [];
        $data = [];
        if ($updating) {
            $data[] = $this->requiredPositiveInteger(
                $values,
                'family_authorized_pickup_id',
                'El recurso seleccionado no está disponible para esta familia.',
                $errors,
            );
        }
        $names = $values['names'] ?? '';
        $mobilePhone = $values['mobile_phone'] ?? '';
        if ($names === '') {
            $errors[] = 'Los nombres son obligatorios.';
        }
        if ($mobilePhone === '') {
            $errors[] = 'El teléfono móvil es obligatorio.';
        }
        $relationshipTypeId = $this->positiveInteger($values['relationship_type_id'] ?? null);
        if ($relationshipTypeId === null || !$options->hasRelationshipType($relationshipTypeId)) {
            $errors[] = 'Seleccione un parentesco activo.';
        }
        $documentTypeId = $this->optionalPositiveInteger(
            $values['document_type_id'] ?? '',
            'document type',
            $errors,
        );
        $documentNumber = $this->nullable($values['document_number'] ?? '');
        if (($documentTypeId === null) !== ($documentNumber === null)) {
            $errors[] = 'Ingrese el tipo y el número de documento juntos.';
        }
        if ($documentTypeId !== null && !$options->hasDocumentType($documentTypeId)) {
            $errors[] = 'Seleccione un tipo de documento activo.';
        }

        return [$errors, array_merge($data, [
            $names,
            $relationshipTypeId,
            $mobilePhone,
            $this->nullable($values['phone'] ?? ''),
            $documentTypeId,
            $documentNumber,
            $this->nullable($values['observations'] ?? ''),
        ])];
    }

    private function renderFailure(array $values, array $errors): string
    {
        try {
            $resources = $this->getResources->handle();
        } catch (RepresentativeAcknowledgementsRequired) {
            return $this->redirect('/representative/acknowledgements', 303);
        } catch (ActiveAcademicPeriodUnavailable) {
            return $this->redirect('/representative', 303);
        } catch (RepresentativeAcknowledgementAccessUnavailable) {
            return $this->forbidden();
        } catch (RepresentativeFamilySelectionRequired) {
            return $this->redirect('/representative', 303);
        } catch (RepresentativeFamilyContextUnavailable|FamilyNotFound|StudentNotFound|PersonNotFound) {
            return $this->forbidden();
        } catch (InvalidPersistedFamilyResult) {
            return $this->contextError('No se pudo confirmar la operación.', 422);
        }

        return $this->resourceView(
            $resources,
            $this->optionsProvider->get(),
            $values,
            $errors,
            null,
            null,
            422,
            $this->resourceScreen(),
        );
    }

    /** @param list<string> $errors */
    private function resourceView(
        RepresentativeFamilyResourcesOutput $resources,
        FamilyResourceFormOptions $options,
        array $values,
        array $errors,
        ?string $successMessage,
        ?string $errorMessage,
        int $status = 200,
        string $screen = 'addresses',
    ): string {
        $query = (new Request())->query();
        $returnStudentId = $this->authorizedReturnStudentId($resources);
        if (array_key_exists('student_id', $query) && $returnStudentId === null) {
            return $this->forbidden();
        }
        http_response_code($status);

        return $this->view('representative-portal.resources', [
            'title' => 'Recursos familiares',
            'context' => $resources,
            'canChangeFamily' => $resources->canChangeFamily,
            'resources' => $resources,
            'options' => $options,
            'students' => $resources->students,
            'ownRepresentativeAddressAssignments' => $resources->ownRepresentativeAddressAssignments,
            'studentAddressAssignments' => $resources->studentAddressAssignments,
            'emergencyContactAssignments' => $resources->emergencyContactAssignments,
            'authorizedPickupAssignments' => $resources->authorizedPickupAssignments,
            'csrfToken' => $this->csrf->token(),
            'values' => $values,
            'errors' => $errors,
            'successMessage' => $successMessage,
            'errorMessage' => $errorMessage,
            'screen' => $screen,
            'returnStudentId' => $returnStudentId,
        ]);
    }

    private function authorizedReturnStudentId(RepresentativeFamilyResourcesOutput $resources): ?int
    {
        $studentId = $this->positiveInteger((new Request())->query()['student_id'] ?? null);
        if ($studentId === null) {
            return null;
        }
        foreach ($resources->students as $student) {
            if ($student->studentId === $studentId) {
                return $studentId;
            }
        }

        return null;
    }

    private function resourceScreen(): string
    {
        $path = (new Request())->uri();
        if (str_starts_with($path, '/representative/resources/emergency-contacts/')) {
            return 'emergency-contacts';
        }
        if (str_starts_with($path, '/representative/resources/authorized-pickups/')) {
            return 'authorized-pickups';
        }

        return 'addresses';
    }

    private function resourceLocation(): string
    {
        $location = '/representative/resources/' . $this->resourceScreen();
        $studentId = $this->positiveInteger((new Request())->query()['student_id'] ?? null);

        return $studentId === null ? $location : $location . '?student_id=' . $studentId;
    }

    private function requiredPositiveInteger(
        array $values,
        string $field,
        string $message,
        array &$errors,
    ): ?int {
        $id = $this->positiveInteger($values[$field] ?? null);
        if ($id === null) {
            $errors[] = $message;
        }

        return $id;
    }

    private function optionalPositiveInteger(string $value, string $label, array &$errors): ?int
    {
        if ($value === '') {
            return null;
        }
        $id = $this->positiveInteger($value);
        if ($id === null) {
            $errors[] = 'Seleccione un valor válido.';
        }

        return $id;
    }

    /** @return array<string, string> */
    private function safeValues(array $input, array &$errors): array
    {
        $values = [];
        foreach ($input as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $values[$key] = trim((string) $value);
            } elseif (is_string($key)) {
                $errors[] = 'Ingrese un único valor por campo.';
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

    private function nullable(string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    private function flash(string $key): ?string
    {
        $value = $this->session->pull($key);

        return is_string($value) ? $value : null;
    }

    private function forbidden(string $message = 'El recurso familiar solicitado no está disponible.'): string
    {
        return $this->contextError($message, 403);
    }

    private function contextError(string $message, int $status): string
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
