<?php

declare(strict_types=1);

namespace App\Family\Http;

use App\Shared\Http\SafeErrorPage;
use App\Controllers\Controller;
use App\Family\Application\ActivateFamilyAddress;
use App\Family\Application\ActivateFamilyAuthorizedPickup;
use App\Family\Application\ActivateFamilyEmergencyContact;
use App\Family\Application\AssignAuthorizedPickup;
use App\Family\Application\AssignEmergencyContact;
use App\Family\Application\AssignRepresentativeAddress;
use App\Family\Application\AssignStudentAddress;
use App\Family\Application\CreateFamilyAddress;
use App\Family\Application\CreateFamilyAuthorizedPickup;
use App\Family\Application\CreateFamilyEmergencyContact;
use App\Family\Application\DeactivateFamilyAddress;
use App\Family\Application\DeactivateFamilyAuthorizedPickup;
use App\Family\Application\DeactivateFamilyEmergencyContact;
use App\Family\Application\Dto\ActivateFamilyAddressInput;
use App\Family\Application\Dto\ActivateFamilyAuthorizedPickupInput;
use App\Family\Application\Dto\ActivateFamilyEmergencyContactInput;
use App\Family\Application\Dto\AssignAuthorizedPickupInput;
use App\Family\Application\Dto\AssignEmergencyContactInput;
use App\Family\Application\Dto\AssignRepresentativeAddressInput;
use App\Family\Application\Dto\AssignStudentAddressInput;
use App\Family\Application\Dto\CreateFamilyAddressInput;
use App\Family\Application\Dto\CreateFamilyAuthorizedPickupInput;
use App\Family\Application\Dto\CreateFamilyEmergencyContactInput;
use App\Family\Application\Dto\DeactivateFamilyAddressInput;
use App\Family\Application\Dto\DeactivateFamilyAuthorizedPickupInput;
use App\Family\Application\Dto\DeactivateFamilyEmergencyContactInput;
use App\Family\Application\Dto\EndAuthorizedPickupAssignmentInput;
use App\Family\Application\Dto\EndEmergencyContactAssignmentInput;
use App\Family\Application\Dto\EndRepresentativeAddressAssignmentInput;
use App\Family\Application\Dto\EndStudentAddressAssignmentInput;
use App\Family\Application\Dto\FamilyOutput;
use App\Family\Application\Dto\FamilyResourcesOutput;
use App\Family\Application\Dto\UpdateFamilyAddressInput;
use App\Family\Application\Dto\UpdateFamilyAuthorizedPickupInput;
use App\Family\Application\Dto\UpdateFamilyEmergencyContactInput;
use App\Family\Application\EndAuthorizedPickupAssignment;
use App\Family\Application\EndEmergencyContactAssignment;
use App\Family\Application\EndRepresentativeAddressAssignment;
use App\Family\Application\EndStudentAddressAssignment;
use App\Family\Application\Exception\DocumentTypeNotFound;
use App\Family\Application\Exception\FamilyNotFound;
use App\Family\Application\Exception\InvalidPersistedFamilyResult;
use App\Family\Application\Exception\RelationshipTypeNotFound;
use App\Family\Application\GetFamilyMembership;
use App\Family\Application\GetFamilyResources;
use App\Family\Application\UpdateFamilyAddress;
use App\Family\Application\UpdateFamilyAuthorizedPickup;
use App\Family\Application\UpdateFamilyEmergencyContact;
use App\Family\Domain\Exception\InvalidFamilyState;
use App\IdentityAccess\Application\Contract\CsrfTokenManager;
use App\IdentityAccess\Application\Contract\SessionManager;
use Core\Http\Request;
use DateTimeImmutable;
use DateTimeZone;

final class FamilyResourceController extends Controller
{
    private const TRUSTED_FAMILY_ID_KEY = '_family_resources_trusted_family_id';
    private const FLASH_SUCCESS_KEY = '_flash_family_resources_success';
    private const FLASH_ERROR_KEY = '_flash_family_resources_error';

    public function __construct(
        private readonly GetFamilyResources $getFamilyResources,
        private readonly GetFamilyMembership $getFamilyMembership,
        private readonly CreateFamilyAddress $createAddress,
        private readonly UpdateFamilyAddress $updateAddress,
        private readonly ActivateFamilyAddress $activateAddress,
        private readonly DeactivateFamilyAddress $deactivateAddress,
        private readonly AssignRepresentativeAddress $assignRepresentativeAddress,
        private readonly EndRepresentativeAddressAssignment $endRepresentativeAddress,
        private readonly AssignStudentAddress $assignStudentAddress,
        private readonly EndStudentAddressAssignment $endStudentAddress,
        private readonly CreateFamilyEmergencyContact $createEmergencyContact,
        private readonly UpdateFamilyEmergencyContact $updateEmergencyContact,
        private readonly ActivateFamilyEmergencyContact $activateEmergencyContact,
        private readonly DeactivateFamilyEmergencyContact $deactivateEmergencyContact,
        private readonly AssignEmergencyContact $assignEmergencyContact,
        private readonly EndEmergencyContactAssignment $endEmergencyContact,
        private readonly CreateFamilyAuthorizedPickup $createAuthorizedPickup,
        private readonly UpdateFamilyAuthorizedPickup $updateAuthorizedPickup,
        private readonly ActivateFamilyAuthorizedPickup $activateAuthorizedPickup,
        private readonly DeactivateFamilyAuthorizedPickup $deactivateAuthorizedPickup,
        private readonly AssignAuthorizedPickup $assignAuthorizedPickup,
        private readonly EndAuthorizedPickupAssignment $endAuthorizedPickup,
        private readonly CsrfTokenManager $csrf,
        private readonly SessionManager $session,
        private readonly FamilyResourceFormOptionsProvider $optionsProvider,
        private readonly FamilyMemberLabelsProvider $memberLabels,
    ) {
    }

    public function index(): string
    {
        $familyId = $this->positiveInteger((new Request())->query()['family_id'] ?? null);
        if ($familyId === null) {
            return $this->contextError('Ingrese un identificador de familia válido.', 422);
        }

        try {
            [$resources, $family, $options] = $this->loadContext($familyId);
        } catch (FamilyNotFound) {
            return $this->notFound();
        } catch (InvalidPersistedFamilyResult) {
            return $this->contextError('No se pudo confirmar la operación.', 422);
        }

        $this->session->put(self::TRUSTED_FAMILY_ID_KEY, $familyId);

        return $this->resourceView(
            $resources,
            $family,
            $options,
            [],
            [],
            $this->flash(self::FLASH_SUCCESS_KEY),
            $this->flash(self::FLASH_ERROR_KEY),
        );
    }

    public function createAddress(): string
    {
        return $this->execute(
            fn (array $values, FamilyResourcesOutput $resources, FamilyOutput $family, FamilyResourceFormOptions $options): array =>
                $this->addressInput($values, $resources, false),
            fn (int $familyId, array $data): mixed => $this->createAddress->handle(
                new CreateFamilyAddressInput($familyId, ...$data)
            ),
            'Dirección creada correctamente.',
        );
    }

    public function updateAddress(): string
    {
        return $this->execute(
            fn (array $values, FamilyResourcesOutput $resources, FamilyOutput $family, FamilyResourceFormOptions $options): array =>
                $this->addressInput($values, $resources, true),
            fn (int $familyId, array $data): mixed => $this->updateAddress->handle(
                new UpdateFamilyAddressInput($familyId, ...$data)
            ),
            'Dirección actualizada correctamente.',
        );
    }

    public function activateAddress(): string
    {
        return $this->resourceStatus(
            'family_address_id',
            'addresses',
            fn (int $familyId, int $id): mixed => $this->activateAddress->handle(
                new ActivateFamilyAddressInput($familyId, $id)
            ),
            'Dirección activada correctamente.',
        );
    }

    public function deactivateAddress(): string
    {
        return $this->resourceStatus(
            'family_address_id',
            'addresses',
            fn (int $familyId, int $id): mixed => $this->deactivateAddress->handle(
                new DeactivateFamilyAddressInput($familyId, $id)
            ),
            'Dirección desactivada correctamente.',
        );
    }

    public function assignRepresentativeAddress(): string
    {
        return $this->execute(
            function (array $values, FamilyResourcesOutput $resources, FamilyOutput $family): array {
                $errors = [];
                $representativeId = $this->requiredActiveMembershipId(
                    $values,
                    'representative_id',
                    $family->representatives,
                    'representativeId',
                    $errors,
                );
                $addressId = $this->requiredActiveResourceId(
                    $values,
                    'family_address_id',
                    $resources->addresses,
                    $errors,
                );
                $startedAt = $this->requiredTimestamp($values, 'started_at', $errors);

                return [$errors, [$representativeId, $addressId, $startedAt]];
            },
            fn (int $familyId, array $data): mixed => $this->assignRepresentativeAddress->handle(
                new AssignRepresentativeAddressInput($familyId, ...$data)
            ),
            'Dirección asignada al representante correctamente.',
        );
    }

    public function endRepresentativeAddress(): string
    {
        return $this->endAssignment(
            'representativeAddressAssignments',
            function (int $familyId, object $assignment, DateTimeImmutable $endedAt): mixed {
                return $this->endRepresentativeAddress->handle(new EndRepresentativeAddressAssignmentInput(
                    $familyId,
                    $assignment->representativeId,
                    $endedAt,
                ));
            },
            'Asignación de dirección al representante finalizada correctamente.',
        );
    }

    public function assignStudentAddress(): string
    {
        return $this->execute(
            function (array $values, FamilyResourcesOutput $resources, FamilyOutput $family): array {
                $errors = [];
                $studentId = $this->requiredActiveMembershipId(
                    $values,
                    'student_id',
                    $family->students,
                    'studentId',
                    $errors,
                );
                $addressId = $this->requiredActiveResourceId(
                    $values,
                    'family_address_id',
                    $resources->addresses,
                    $errors,
                );
                $startedAt = $this->requiredTimestamp($values, 'started_at', $errors);

                return [$errors, [$studentId, $addressId, $startedAt]];
            },
            fn (int $familyId, array $data): mixed => $this->assignStudentAddress->handle(
                new AssignStudentAddressInput($familyId, ...$data)
            ),
            'Dirección asignada al estudiante correctamente.',
        );
    }

    public function endStudentAddress(): string
    {
        return $this->endAssignment(
            'studentAddressAssignments',
            function (int $familyId, object $assignment, DateTimeImmutable $endedAt): mixed {
                return $this->endStudentAddress->handle(new EndStudentAddressAssignmentInput(
                    $familyId,
                    $assignment->studentId,
                    $endedAt,
                ));
            },
            'Asignación de dirección al estudiante finalizada correctamente.',
        );
    }

    public function createEmergencyContact(): string
    {
        return $this->execute(
            fn (array $values, FamilyResourcesOutput $resources, FamilyOutput $family, FamilyResourceFormOptions $options): array =>
                $this->emergencyContactInput($values, $resources, $options, false),
            fn (int $familyId, array $data): mixed => $this->createEmergencyContact->handle(
                new CreateFamilyEmergencyContactInput($familyId, ...$data)
            ),
            'Contacto de emergencia creado correctamente.',
        );
    }

    public function updateEmergencyContact(): string
    {
        return $this->execute(
            fn (array $values, FamilyResourcesOutput $resources, FamilyOutput $family, FamilyResourceFormOptions $options): array =>
                $this->emergencyContactInput($values, $resources, $options, true),
            fn (int $familyId, array $data): mixed => $this->updateEmergencyContact->handle(
                new UpdateFamilyEmergencyContactInput($familyId, ...$data)
            ),
            'Contacto de emergencia actualizado correctamente.',
        );
    }

    public function activateEmergencyContact(): string
    {
        return $this->resourceStatus(
            'family_emergency_contact_id',
            'emergencyContacts',
            fn (int $familyId, int $id): mixed => $this->activateEmergencyContact->handle(
                new ActivateFamilyEmergencyContactInput($familyId, $id)
            ),
            'Contacto de emergencia activado correctamente.',
        );
    }

    public function deactivateEmergencyContact(): string
    {
        return $this->resourceStatus(
            'family_emergency_contact_id',
            'emergencyContacts',
            fn (int $familyId, int $id): mixed => $this->deactivateEmergencyContact->handle(
                new DeactivateFamilyEmergencyContactInput($familyId, $id)
            ),
            'Contacto de emergencia desactivado correctamente.',
        );
    }

    public function assignEmergencyContact(): string
    {
        return $this->execute(
            function (array $values, FamilyResourcesOutput $resources, FamilyOutput $family): array {
                $errors = [];
                $contactId = $this->requiredActiveResourceId(
                    $values,
                    'family_emergency_contact_id',
                    $resources->emergencyContacts,
                    $errors,
                );
                $studentId = $this->requiredActiveMembershipId(
                    $values,
                    'student_id',
                    $family->students,
                    'studentId',
                    $errors,
                );
                $priority = $this->optionalPositiveInteger($values['priority'] ?? '', 'priority', $errors);
                $startedAt = $this->requiredTimestamp($values, 'started_at', $errors);

                return [$errors, [$contactId, $studentId, $priority, $startedAt]];
            },
            fn (int $familyId, array $data): mixed => $this->assignEmergencyContact->handle(
                new AssignEmergencyContactInput($familyId, ...$data)
            ),
            'Contacto de emergencia asignado correctamente.',
        );
    }

    public function endEmergencyContact(): string
    {
        return $this->endAssignment(
            'emergencyContactAssignments',
            function (int $familyId, object $assignment, DateTimeImmutable $endedAt): mixed {
                return $this->endEmergencyContact->handle(new EndEmergencyContactAssignmentInput(
                    $familyId,
                    $assignment->familyEmergencyContactId,
                    $assignment->studentId,
                    $endedAt,
                ));
            },
            'Asignación de contacto de emergencia finalizada correctamente.',
        );
    }

    public function createAuthorizedPickup(): string
    {
        return $this->execute(
            fn (array $values, FamilyResourcesOutput $resources, FamilyOutput $family, FamilyResourceFormOptions $options): array =>
                $this->authorizedPickupInput($values, $resources, $options, false),
            fn (int $familyId, array $data): mixed => $this->createAuthorizedPickup->handle(
                new CreateFamilyAuthorizedPickupInput($familyId, ...$data)
            ),
            'Persona autorizada para retirar creada correctamente.',
        );
    }

    public function updateAuthorizedPickup(): string
    {
        return $this->execute(
            fn (array $values, FamilyResourcesOutput $resources, FamilyOutput $family, FamilyResourceFormOptions $options): array =>
                $this->authorizedPickupInput($values, $resources, $options, true),
            fn (int $familyId, array $data): mixed => $this->updateAuthorizedPickup->handle(
                new UpdateFamilyAuthorizedPickupInput($familyId, ...$data)
            ),
            'Persona autorizada para retirar actualizada correctamente.',
        );
    }

    public function activateAuthorizedPickup(): string
    {
        return $this->resourceStatus(
            'family_authorized_pickup_id',
            'authorizedPickups',
            fn (int $familyId, int $id): mixed => $this->activateAuthorizedPickup->handle(
                new ActivateFamilyAuthorizedPickupInput($familyId, $id)
            ),
            'Persona autorizada para retirar activada correctamente.',
        );
    }

    public function deactivateAuthorizedPickup(): string
    {
        return $this->resourceStatus(
            'family_authorized_pickup_id',
            'authorizedPickups',
            fn (int $familyId, int $id): mixed => $this->deactivateAuthorizedPickup->handle(
                new DeactivateFamilyAuthorizedPickupInput($familyId, $id)
            ),
            'Persona autorizada para retirar desactivada correctamente.',
        );
    }

    public function assignAuthorizedPickup(): string
    {
        return $this->execute(
            function (array $values, FamilyResourcesOutput $resources, FamilyOutput $family): array {
                $errors = [];
                $pickupId = $this->requiredActiveResourceId(
                    $values,
                    'family_authorized_pickup_id',
                    $resources->authorizedPickups,
                    $errors,
                );
                $studentId = $this->requiredActiveMembershipId(
                    $values,
                    'student_id',
                    $family->students,
                    'studentId',
                    $errors,
                );
                $startedAt = $this->requiredTimestamp($values, 'started_at', $errors);

                return [$errors, [$pickupId, $studentId, $startedAt]];
            },
            fn (int $familyId, array $data): mixed => $this->assignAuthorizedPickup->handle(
                new AssignAuthorizedPickupInput($familyId, ...$data)
            ),
            'Persona autorizada para retirar asignada correctamente.',
        );
    }

    public function endAuthorizedPickup(): string
    {
        return $this->endAssignment(
            'authorizedPickupAssignments',
            function (int $familyId, object $assignment, DateTimeImmutable $endedAt): mixed {
                return $this->endAuthorizedPickup->handle(new EndAuthorizedPickupAssignmentInput(
                    $familyId,
                    $assignment->familyAuthorizedPickupId,
                    $assignment->studentId,
                    $endedAt,
                ));
            },
            'Asignación de persona autorizada para retirar finalizada correctamente.',
        );
    }

    /**
     * @param callable(array<string, string>, FamilyResourcesOutput, FamilyOutput, FamilyResourceFormOptions): array{list<string>, array<int, mixed>} $validate
     * @param callable(int, array<int, mixed>): mixed $handle
     */
    private function execute(callable $validate, callable $handle, string $success): string
    {
        $input = (new Request())->input();
        $trustedFamilyId = $this->pullTrustedFamilyId();
        if ($trustedFamilyId === null) {
            return $this->contextError('La selección de familia venció. Abra la familia nuevamente.', 422);
        }

        if (!$this->csrf->isValid($this->scalar($input, '_csrf_token'))) {
            $this->restoreTrustedFamilyId($trustedFamilyId);
            $this->session->put(self::FLASH_ERROR_KEY, 'El formulario venció. Inténtelo nuevamente.');

            return $this->redirect('/families/resources?family_id=' . $trustedFamilyId, 303);
        }

        $scalarErrors = [];
        $values = $this->safeValues($input, $scalarErrors);
        if ($this->positiveInteger($values['family_id'] ?? null) !== $trustedFamilyId) {
            return $this->renderFailure(
                $trustedFamilyId,
                $values,
                ['No se puede cambiar la familia seleccionada.'],
            );
        }

        try {
            [$resources, $family, $options] = $this->loadContext($trustedFamilyId);
            [$errors, $data] = $validate($values, $resources, $family, $options);
            $errors = array_merge($scalarErrors, $errors);
            if ($errors !== []) {
                $this->restoreTrustedFamilyId($trustedFamilyId);

                return $this->resourceView($resources, $family, $options, $values, $errors, null, null, 422);
            }
            $handle($trustedFamilyId, $data);
        } catch (FamilyNotFound) {
            return $this->notFound();
        } catch (RelationshipTypeNotFound) {
            return $this->renderFailure(
                $trustedFamilyId,
                $values,
                ['Seleccione un parentesco activo.'],
            );
        } catch (DocumentTypeNotFound) {
            return $this->renderFailure(
                $trustedFamilyId,
                $values,
                ['Seleccione un tipo de documento activo.'],
            );
        } catch (InvalidPersistedFamilyResult) {
            return $this->renderFailure(
                $trustedFamilyId,
                $values,
                ['No se pudo confirmar la operación.'],
            );
        } catch (InvalidFamilyState $error) {
            return $this->renderFailure(
                $trustedFamilyId,
                $values,
                [FamilyResourceFeedback::forInvalidState($error)],
            );
        }

        $this->session->put(self::FLASH_SUCCESS_KEY, $success);

        return $this->redirect('/families/resources?family_id=' . $trustedFamilyId, 303);
    }

    /** @param callable(int, int): mixed $handle */
    private function resourceStatus(
        string $field,
        string $collection,
        callable $handle,
        string $success,
    ): string {
        return $this->execute(
            function (array $values, FamilyResourcesOutput $resources) use ($field, $collection): array {
                $errors = [];
                $id = $this->requiredResourceId($values, $field, $resources->{$collection}, $errors);

                return [$errors, [$id]];
            },
            fn (int $familyId, array $data): mixed => $handle($familyId, $data[0]),
            $success,
        );
    }

    /** @param callable(int, object, DateTimeImmutable): mixed $handle */
    private function endAssignment(string $collection, callable $handle, string $success): string
    {
        return $this->execute(
            function (array $values, FamilyResourcesOutput $resources) use ($collection): array {
                $errors = [];
                $assignmentId = $this->positiveInteger($values['assignment_id'] ?? null);
                $assignment = $assignmentId === null
                    ? null
                    : $this->findById($resources->{$collection}, $assignmentId, true);
                if ($assignment === null) {
                    $errors[] = 'El recurso seleccionado no está disponible para esta familia.';
                }
                $endedAt = $this->requiredTimestamp($values, 'ended_at', $errors);

                return [$errors, [$assignment, $endedAt]];
            },
            fn (int $familyId, array $data): mixed => $handle($familyId, $data[0], $data[1]),
            $success,
        );
    }

    /** @return array{list<string>, array<int, mixed>} */
    private function addressInput(array $values, FamilyResourcesOutput $resources, bool $updating): array
    {
        $errors = [];
        $data = [];
        if ($updating) {
            $data[] = $this->requiredResourceId(
                $values,
                'family_address_id',
                $resources->addresses,
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
        FamilyResourcesOutput $resources,
        FamilyResourceFormOptions $options,
        bool $updating,
    ): array {
        $errors = [];
        $data = [];
        if ($updating) {
            $data[] = $this->requiredResourceId(
                $values,
                'family_emergency_contact_id',
                $resources->emergencyContacts,
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
        FamilyResourcesOutput $resources,
        FamilyResourceFormOptions $options,
        bool $updating,
    ): array {
        $errors = [];
        $data = [];
        if ($updating) {
            $data[] = $this->requiredResourceId(
                $values,
                'family_authorized_pickup_id',
                $resources->authorizedPickups,
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

    /** @return array{FamilyResourcesOutput, FamilyOutput, FamilyResourceFormOptions} */
    private function loadContext(int $familyId): array
    {
        return [
            $this->getFamilyResources->handle($familyId),
            $this->getFamilyMembership->handle($familyId),
            $this->optionsProvider->get(),
        ];
    }

    private function renderFailure(int $familyId, array $values, array $errors): string
    {
        try {
            [$resources, $family, $options] = $this->loadContext($familyId);
        } catch (FamilyNotFound) {
            return $this->notFound();
        } catch (InvalidPersistedFamilyResult) {
            return $this->contextError('No se pudo confirmar la operación.', 422);
        }
        $this->restoreTrustedFamilyId($familyId);

        return $this->resourceView($resources, $family, $options, $values, $errors, null, null, 422);
    }

    private function resourceView(
        FamilyResourcesOutput $resources,
        FamilyOutput $family,
        FamilyResourceFormOptions $options,
        array $values,
        array $errors,
        ?string $successMessage,
        ?string $errorMessage,
        int $status = 200,
    ): string {
        http_response_code($status);

        return $this->view('families.resources', [
            'title' => 'Recursos familiares',
            'resources' => $resources,
            'family' => $family,
            'memberLabels' => $this->memberLabels->forFamily($family->id),
            'options' => $options,
            'csrfToken' => $this->csrf->token(),
            'values' => $values,
            'errors' => $errors,
            'successMessage' => $successMessage,
            'errorMessage' => $errorMessage,
        ]);
    }

    private function requiredResourceId(array $values, string $field, array $resources, array &$errors): ?int
    {
        $id = $this->positiveInteger($values[$field] ?? null);
        if ($id === null || $this->findById($resources, $id) === null) {
            $errors[] = 'El recurso seleccionado no está disponible para esta familia.';

            return null;
        }

        return $id;
    }

    private function requiredActiveResourceId(array $values, string $field, array $resources, array &$errors): ?int
    {
        $id = $this->positiveInteger($values[$field] ?? null);
        $resource = $id === null ? null : $this->findById($resources, $id);
        if ($resource === null || $resource->status !== 'ACTIVE') {
            $errors[] = 'El recurso seleccionado no está disponible para esta familia.';

            return null;
        }

        return $id;
    }

    private function requiredActiveMembershipId(
        array $values,
        string $field,
        array $memberships,
        string $identityProperty,
        array &$errors,
    ): ?int {
        $id = $this->positiveInteger($values[$field] ?? null);
        foreach ($memberships as $membership) {
            if ($id !== null && $membership->{$identityProperty} === $id && $membership->isActive) {
                return $id;
            }
        }
        $errors[] = 'El recurso seleccionado no está disponible para esta familia.';

        return null;
    }

    private function requiredTimestamp(array $values, string $field, array &$errors): ?DateTimeImmutable
    {
        $value = $values[$field] ?? '';
        $timestamp = DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i',
            $value,
            new DateTimeZone('UTC'),
        );
        if (!$timestamp instanceof DateTimeImmutable || $timestamp->format('Y-m-d\TH:i') !== $value) {
            $errors[] = 'Ingrese una fecha y hora válidas (AAAA-MM-DDTHH:MM).';

            return null;
        }

        return $timestamp;
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

    private function findById(array $items, int $id, bool $activeOnly = false): ?object
    {
        foreach ($items as $item) {
            if ($item->id === $id && (!$activeOnly || $item->isActive)) {
                return $item;
            }
        }

        return null;
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

    private function pullTrustedFamilyId(): ?int
    {
        $value = $this->session->pull(self::TRUSTED_FAMILY_ID_KEY);

        return is_int($value) && $value > 0 ? $value : null;
    }

    private function restoreTrustedFamilyId(int $familyId): void
    {
        $this->session->put(self::TRUSTED_FAMILY_ID_KEY, $familyId);
    }

    private function flash(string $key): ?string
    {
        $value = $this->session->pull($key);

        return is_string($value) ? $value : null;
    }

    private function notFound(): string
    {
        http_response_code(404);

        return $this->contextError('No se encontró la familia.', 404);
    }

    private function contextError(string $message, int $status): string
    {
        http_response_code($status);
        return SafeErrorPage::render($status, $message, '/families', 'Volver a familias');
    }

    private function redirect(string $location, int $status): string
    {
        header('Location: ' . $location);
        http_response_code($status);

        return '';
    }
}
