<?php

declare(strict_types=1);

namespace App\Family\Http;

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
    ) {
    }

    public function index(): string
    {
        $familyId = $this->positiveInteger((new Request())->query()['family_id'] ?? null);
        if ($familyId === null) {
            return $this->contextError('Enter a valid positive Family ID.', 422);
        }

        try {
            [$resources, $family, $options] = $this->loadContext($familyId);
        } catch (FamilyNotFound) {
            return $this->notFound();
        } catch (InvalidPersistedFamilyResult) {
            return $this->contextError('The operation could not be confirmed.', 422);
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
            'Address created successfully.',
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
            'Address updated successfully.',
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
            'Address activated successfully.',
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
            'Address deactivated successfully.',
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
            'Representative Address assignment created successfully.',
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
            'Representative Address assignment ended successfully.',
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
            'Student Address assignment created successfully.',
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
            'Student Address assignment ended successfully.',
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
            'Emergency Contact created successfully.',
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
            'Emergency Contact updated successfully.',
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
            'Emergency Contact activated successfully.',
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
            'Emergency Contact deactivated successfully.',
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
            'Emergency Contact assignment created successfully.',
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
            'Emergency Contact assignment ended successfully.',
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
            'Authorized Pickup created successfully.',
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
            'Authorized Pickup updated successfully.',
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
            'Authorized Pickup activated successfully.',
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
            'Authorized Pickup deactivated successfully.',
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
            'Authorized Pickup assignment created successfully.',
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
            'Authorized Pickup assignment ended successfully.',
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
            return $this->contextError('The Family selection expired. Open the Family again.', 422);
        }

        if (!$this->csrf->isValid($this->scalar($input, '_csrf_token'))) {
            $this->restoreTrustedFamilyId($trustedFamilyId);
            $this->session->put(self::FLASH_ERROR_KEY, 'Your form expired. Please try again.');

            return $this->redirect('/families/resources?family_id=' . $trustedFamilyId, 303);
        }

        $scalarErrors = [];
        $values = $this->safeValues($input, $scalarErrors);
        if ($this->positiveInteger($values['family_id'] ?? null) !== $trustedFamilyId) {
            return $this->renderFailure(
                $trustedFamilyId,
                $values,
                ['Family identity cannot be changed.'],
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
                ['Select an active relationship type.'],
            );
        } catch (DocumentTypeNotFound) {
            return $this->renderFailure(
                $trustedFamilyId,
                $values,
                ['Select an active document type.'],
            );
        } catch (InvalidPersistedFamilyResult) {
            return $this->renderFailure(
                $trustedFamilyId,
                $values,
                ['The operation could not be confirmed.'],
            );
        } catch (InvalidFamilyState) {
            return $this->renderFailure(
                $trustedFamilyId,
                $values,
                ['Selected resource is not available for this Family.'],
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
                    $errors[] = 'Selected resource is not available for this Family.';
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
            $errors[] = 'Address label is required.';
        }
        if ($mainStreet === '') {
            $errors[] = 'Main street is required.';
        }
        $latitude = $this->nullable($values['latitude'] ?? '');
        $longitude = $this->nullable($values['longitude'] ?? '');
        if (($latitude === null) !== ($longitude === null)) {
            $errors[] = 'Latitude and longitude must be supplied together.';
        }
        if ($latitude !== null && !is_numeric($latitude)) {
            $errors[] = 'Latitude must be numeric.';
        }
        if ($longitude !== null && !is_numeric($longitude)) {
            $errors[] = 'Longitude must be numeric.';
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
            $errors[] = 'Names are required.';
        }
        if ($mobilePhone === '') {
            $errors[] = 'Mobile phone is required.';
        }
        $relationshipTypeId = $this->positiveInteger($values['relationship_type_id'] ?? null);
        if ($relationshipTypeId === null || !$options->hasRelationshipType($relationshipTypeId)) {
            $errors[] = 'Select an active relationship type.';
        }
        $email = $this->nullable($values['email'] ?? '');
        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'Enter a valid email.';
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
            $errors[] = 'Names are required.';
        }
        if ($mobilePhone === '') {
            $errors[] = 'Mobile phone is required.';
        }
        $relationshipTypeId = $this->positiveInteger($values['relationship_type_id'] ?? null);
        if ($relationshipTypeId === null || !$options->hasRelationshipType($relationshipTypeId)) {
            $errors[] = 'Select an active relationship type.';
        }
        $documentTypeId = $this->optionalPositiveInteger(
            $values['document_type_id'] ?? '',
            'document type',
            $errors,
        );
        $documentNumber = $this->nullable($values['document_number'] ?? '');
        if (($documentTypeId === null) !== ($documentNumber === null)) {
            $errors[] = 'Document type and number must be supplied together.';
        }
        if ($documentTypeId !== null && !$options->hasDocumentType($documentTypeId)) {
            $errors[] = 'Select an active document type.';
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
            return $this->contextError('The operation could not be confirmed.', 422);
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
            'title' => 'Family resources',
            'resources' => $resources,
            'family' => $family,
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
            $errors[] = 'Selected resource is not available for this Family.';

            return null;
        }

        return $id;
    }

    private function requiredActiveResourceId(array $values, string $field, array $resources, array &$errors): ?int
    {
        $id = $this->positiveInteger($values[$field] ?? null);
        $resource = $id === null ? null : $this->findById($resources, $id);
        if ($resource === null || $resource->status !== 'ACTIVE') {
            $errors[] = 'Selected resource is not available for this Family.';

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
        $errors[] = 'Selected resource is not available for this Family.';

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
            $errors[] = sprintf('%s must use the YYYY-MM-DDTHH:MM format.', ucfirst(str_replace('_', ' ', $field)));

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
            $errors[] = sprintf('Select a valid %s.', $label);
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

        return $this->contextError('Family not found.', 404);
    }

    private function contextError(string $message, int $status): string
    {
        http_response_code($status);
        $escaped = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');

        return '<h1>Family resources unavailable</h1><p role="alert">' . $escaped
            . '</p><p><a href="/families">Back to Families</a></p>';
    }

    private function redirect(string $location, int $status): string
    {
        header('Location: ' . $location);
        http_response_code($status);

        return '';
    }
}
