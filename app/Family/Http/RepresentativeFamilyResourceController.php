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
use App\IdentityAccess\Application\FamilyContext;
use App\IdentityAccess\Application\RepresentativeFamilyAccess;
use App\IdentityAccess\Application\ResolveFamilyContext;
use App\Person\Application\Exception\PersonNotFound;
use App\Person\Application\GetPerson;
use App\Student\Application\Exception\StudentNotFound;
use App\Student\Application\GetStudent;
use Core\Http\Request;
use DateTimeImmutable;
use DateTimeZone;

final class RepresentativeFamilyResourceController extends Controller
{
    private const FLASH_SUCCESS_KEY = '_flash_representative_family_resources_success';
    private const FLASH_ERROR_KEY = '_flash_representative_family_resources_error';

    public function __construct(
        private readonly ResolveFamilyContext $resolveFamilyContext,
        private readonly GetFamilyResources $getFamilyResources,
        private readonly GetFamilyMembership $getFamilyMembership,
        private readonly GetStudent $getStudent,
        private readonly GetPerson $getPerson,
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
        [$access, $response] = $this->access(false);
        if (!$access instanceof RepresentativeFamilyAccess) {
            return $response;
        }

        try {
            [$resources, $family, $options, $students] = $this->loadContext($access->context);
        } catch (FamilyNotFound|StudentNotFound|PersonNotFound) {
            return $this->forbidden();
        } catch (InvalidPersistedFamilyResult) {
            return $this->contextError('The operation could not be confirmed.', 422);
        }

        return $this->resourceView(
            $access,
            $resources,
            $family,
            $options,
            $students,
            [],
            [],
            $this->flash(self::FLASH_SUCCESS_KEY),
            $this->flash(self::FLASH_ERROR_KEY),
        );
    }

    public function createAddress(): string
    {
        return $this->execute(
            fn (array $values, FamilyResourcesOutput $resources): array =>
                $this->addressInput($values, $resources, null, false),
            fn (FamilyContext $context, array $data): mixed => $this->createAddress->handle(
                new CreateFamilyAddressInput($context->familyId, ...$data)
            ),
            'Address created successfully.',
        );
    }

    public function updateAddress(): string
    {
        return $this->execute(
            fn (array $values, FamilyResourcesOutput $resources, FamilyOutput $family, FamilyContext $context): array =>
                $this->addressInput($values, $resources, $context, true),
            fn (FamilyContext $context, array $data): mixed => $this->updateAddress->handle(
                new UpdateFamilyAddressInput($context->familyId, ...$data)
            ),
            'Address updated successfully.',
        );
    }

    public function activateAddress(): string
    {
        return $this->resourceStatus(
            'family_address_id',
            'addresses',
            fn (FamilyContext $context, int $id): mixed => $this->activateAddress->handle(
                new ActivateFamilyAddressInput($context->familyId, $id)
            ),
            'Address activated successfully.',
        );
    }

    public function deactivateAddress(): string
    {
        return $this->resourceStatus(
            'family_address_id',
            'addresses',
            fn (FamilyContext $context, int $id): mixed => $this->deactivateAddress->handle(
                new DeactivateFamilyAddressInput($context->familyId, $id)
            ),
            'Address deactivated successfully.',
            fn (int $id, FamilyResourcesOutput $resources, FamilyContext $context): ?string =>
                $this->isAddressUsedByAnotherRepresentative($id, $resources, $context)
                    ? 'This address cannot be changed from your account.'
                    : null,
        );
    }

    public function assignRepresentativeAddress(): string
    {
        return $this->execute(
            function (
                array $values,
                FamilyResourcesOutput $resources,
                FamilyOutput $family,
                FamilyContext $context,
            ): array {
                $errors = [];
                $addressId = $this->requiredActiveResourceId(
                    $values,
                    'family_address_id',
                    $resources->addresses,
                    $errors,
                );
                $startedAt = $this->requiredTimestamp($values, 'started_at', $errors);

                return [$errors, [$context->representativeId, $addressId, $startedAt]];
            },
            fn (FamilyContext $context, array $data): mixed => $this->assignRepresentativeAddress->handle(
                new AssignRepresentativeAddressInput($context->familyId, ...$data)
            ),
            'Your Address assignment was created successfully.',
        );
    }

    public function endRepresentativeAddress(): string
    {
        return $this->endAssignment(
            'representativeAddressAssignments',
            static fn (object $assignment, FamilyContext $context, array $students): bool =>
                $assignment->representativeId === $context->representativeId,
            fn (FamilyContext $context, object $assignment, DateTimeImmutable $endedAt): mixed =>
                $this->endRepresentativeAddress->handle(new EndRepresentativeAddressAssignmentInput(
                    $context->familyId,
                    $context->representativeId,
                    $endedAt,
                )),
            'Your Address assignment was ended successfully.',
        );
    }

    public function assignStudentAddress(): string
    {
        return $this->execute(
            function (
                array $values,
                FamilyResourcesOutput $resources,
                FamilyOutput $family,
                FamilyContext $context,
                array $students,
            ): array {
                $errors = [];
                $studentId = $this->requiredStudentId($values, $students, $errors);
                $addressId = $this->requiredActiveResourceId(
                    $values,
                    'family_address_id',
                    $resources->addresses,
                    $errors,
                );
                $startedAt = $this->requiredTimestamp($values, 'started_at', $errors);

                return [$errors, [$studentId, $addressId, $startedAt]];
            },
            fn (FamilyContext $context, array $data): mixed => $this->assignStudentAddress->handle(
                new AssignStudentAddressInput($context->familyId, ...$data)
            ),
            'Student Address assignment was created successfully.',
        );
    }

    public function endStudentAddress(): string
    {
        return $this->endAssignment(
            'studentAddressAssignments',
            fn (object $assignment, FamilyContext $context, array $students): bool =>
                $this->hasStudent($students, $assignment->studentId),
            fn (FamilyContext $context, object $assignment, DateTimeImmutable $endedAt): mixed =>
                $this->endStudentAddress->handle(new EndStudentAddressAssignmentInput(
                    $context->familyId,
                    $assignment->studentId,
                    $endedAt,
                )),
            'Student Address assignment was ended successfully.',
        );
    }

    public function createEmergencyContact(): string
    {
        return $this->execute(
            fn (
                array $values,
                FamilyResourcesOutput $resources,
                FamilyOutput $family,
                FamilyContext $context,
                array $students,
                FamilyResourceFormOptions $options,
            ): array => $this->emergencyContactInput($values, $resources, $options, false),
            fn (FamilyContext $context, array $data): mixed => $this->createEmergencyContact->handle(
                new CreateFamilyEmergencyContactInput($context->familyId, ...$data)
            ),
            'Emergency Contact created successfully.',
        );
    }

    public function updateEmergencyContact(): string
    {
        return $this->execute(
            fn (
                array $values,
                FamilyResourcesOutput $resources,
                FamilyOutput $family,
                FamilyContext $context,
                array $students,
                FamilyResourceFormOptions $options,
            ): array => $this->emergencyContactInput($values, $resources, $options, true),
            fn (FamilyContext $context, array $data): mixed => $this->updateEmergencyContact->handle(
                new UpdateFamilyEmergencyContactInput($context->familyId, ...$data)
            ),
            'Emergency Contact updated successfully.',
        );
    }

    public function activateEmergencyContact(): string
    {
        return $this->resourceStatus(
            'family_emergency_contact_id',
            'emergencyContacts',
            fn (FamilyContext $context, int $id): mixed => $this->activateEmergencyContact->handle(
                new ActivateFamilyEmergencyContactInput($context->familyId, $id)
            ),
            'Emergency Contact activated successfully.',
        );
    }

    public function deactivateEmergencyContact(): string
    {
        return $this->resourceStatus(
            'family_emergency_contact_id',
            'emergencyContacts',
            fn (FamilyContext $context, int $id): mixed => $this->deactivateEmergencyContact->handle(
                new DeactivateFamilyEmergencyContactInput($context->familyId, $id)
            ),
            'Emergency Contact deactivated successfully.',
        );
    }

    public function assignEmergencyContact(): string
    {
        return $this->execute(
            function (
                array $values,
                FamilyResourcesOutput $resources,
                FamilyOutput $family,
                FamilyContext $context,
                array $students,
            ): array {
                $errors = [];
                $contactId = $this->requiredActiveResourceId(
                    $values,
                    'family_emergency_contact_id',
                    $resources->emergencyContacts,
                    $errors,
                );
                $studentId = $this->requiredStudentId($values, $students, $errors);
                $priority = $this->optionalPositiveInteger($values['priority'] ?? '', 'priority', $errors);
                $startedAt = $this->requiredTimestamp($values, 'started_at', $errors);

                return [$errors, [$contactId, $studentId, $priority, $startedAt]];
            },
            fn (FamilyContext $context, array $data): mixed => $this->assignEmergencyContact->handle(
                new AssignEmergencyContactInput($context->familyId, ...$data)
            ),
            'Emergency Contact assignment was created successfully.',
        );
    }

    public function endEmergencyContact(): string
    {
        return $this->endAssignment(
            'emergencyContactAssignments',
            fn (object $assignment, FamilyContext $context, array $students): bool =>
                $this->hasStudent($students, $assignment->studentId),
            fn (FamilyContext $context, object $assignment, DateTimeImmutable $endedAt): mixed =>
                $this->endEmergencyContact->handle(new EndEmergencyContactAssignmentInput(
                    $context->familyId,
                    $assignment->familyEmergencyContactId,
                    $assignment->studentId,
                    $endedAt,
                )),
            'Emergency Contact assignment was ended successfully.',
        );
    }

    public function createAuthorizedPickup(): string
    {
        return $this->execute(
            fn (
                array $values,
                FamilyResourcesOutput $resources,
                FamilyOutput $family,
                FamilyContext $context,
                array $students,
                FamilyResourceFormOptions $options,
            ): array => $this->authorizedPickupInput($values, $resources, $options, false),
            fn (FamilyContext $context, array $data): mixed => $this->createAuthorizedPickup->handle(
                new CreateFamilyAuthorizedPickupInput($context->familyId, ...$data)
            ),
            'Authorized Pickup created successfully.',
        );
    }

    public function updateAuthorizedPickup(): string
    {
        return $this->execute(
            fn (
                array $values,
                FamilyResourcesOutput $resources,
                FamilyOutput $family,
                FamilyContext $context,
                array $students,
                FamilyResourceFormOptions $options,
            ): array => $this->authorizedPickupInput($values, $resources, $options, true),
            fn (FamilyContext $context, array $data): mixed => $this->updateAuthorizedPickup->handle(
                new UpdateFamilyAuthorizedPickupInput($context->familyId, ...$data)
            ),
            'Authorized Pickup updated successfully.',
        );
    }

    public function activateAuthorizedPickup(): string
    {
        return $this->resourceStatus(
            'family_authorized_pickup_id',
            'authorizedPickups',
            fn (FamilyContext $context, int $id): mixed => $this->activateAuthorizedPickup->handle(
                new ActivateFamilyAuthorizedPickupInput($context->familyId, $id)
            ),
            'Authorized Pickup activated successfully.',
        );
    }

    public function deactivateAuthorizedPickup(): string
    {
        return $this->resourceStatus(
            'family_authorized_pickup_id',
            'authorizedPickups',
            fn (FamilyContext $context, int $id): mixed => $this->deactivateAuthorizedPickup->handle(
                new DeactivateFamilyAuthorizedPickupInput($context->familyId, $id)
            ),
            'Authorized Pickup deactivated successfully.',
        );
    }

    public function assignAuthorizedPickup(): string
    {
        return $this->execute(
            function (
                array $values,
                FamilyResourcesOutput $resources,
                FamilyOutput $family,
                FamilyContext $context,
                array $students,
            ): array {
                $errors = [];
                $pickupId = $this->requiredActiveResourceId(
                    $values,
                    'family_authorized_pickup_id',
                    $resources->authorizedPickups,
                    $errors,
                );
                $studentId = $this->requiredStudentId($values, $students, $errors);
                $startedAt = $this->requiredTimestamp($values, 'started_at', $errors);

                return [$errors, [$pickupId, $studentId, $startedAt]];
            },
            fn (FamilyContext $context, array $data): mixed => $this->assignAuthorizedPickup->handle(
                new AssignAuthorizedPickupInput($context->familyId, ...$data)
            ),
            'Authorized Pickup assignment was created successfully.',
        );
    }

    public function endAuthorizedPickup(): string
    {
        return $this->endAssignment(
            'authorizedPickupAssignments',
            fn (object $assignment, FamilyContext $context, array $students): bool =>
                $this->hasStudent($students, $assignment->studentId),
            fn (FamilyContext $context, object $assignment, DateTimeImmutable $endedAt): mixed =>
                $this->endAuthorizedPickup->handle(new EndAuthorizedPickupAssignmentInput(
                    $context->familyId,
                    $assignment->familyAuthorizedPickupId,
                    $assignment->studentId,
                    $endedAt,
                )),
            'Authorized Pickup assignment was ended successfully.',
        );
    }

    /**
     * @param callable(array<string, string>, FamilyResourcesOutput, FamilyOutput, FamilyContext,
     *     list<RepresentativeFamilyStudentOption>, FamilyResourceFormOptions): array{list<string>, array<int, mixed>} $validate
     * @param callable(FamilyContext, array<int, mixed>): mixed $handle
     */
    private function execute(callable $validate, callable $handle, string $success): string
    {
        [$access, $response] = $this->access(true);
        if (!$access instanceof RepresentativeFamilyAccess) {
            return $response;
        }
        $context = $access->context;

        $input = (new Request())->input();
        $scalarErrors = [];
        $values = $this->safeValues($input, $scalarErrors);
        if ($this->positiveInteger($values['family_id'] ?? null) !== $context->familyId) {
            return $this->forbidden();
        }

        if (!$this->csrf->isValid($this->scalar($input, '_csrf_token'))) {
            $this->session->put(self::FLASH_ERROR_KEY, 'Your form expired. Please try again.');

            return $this->redirect('/representative/resources', 303);
        }

        try {
            [$resources, $family, $options, $students] = $this->loadContext($context);
        } catch (FamilyNotFound|StudentNotFound|PersonNotFound) {
            return $this->forbidden();
        } catch (InvalidPersistedFamilyResult) {
            return $this->contextError('The operation could not be confirmed.', 422);
        }

        try {
            [$errors, $data] = $validate($values, $resources, $family, $context, $students, $options);
            $errors = array_merge($scalarErrors, $errors);
            if ($errors !== []) {
                return $this->resourceView(
                    $access,
                    $resources,
                    $family,
                    $options,
                    $students,
                    $values,
                    $errors,
                    null,
                    null,
                    422,
                );
            }
            $handle($context, $data);
        } catch (FamilyNotFound|StudentNotFound|PersonNotFound) {
            return $this->forbidden();
        } catch (RelationshipTypeNotFound) {
            return $this->renderFailure($access, $values, ['Select an active relationship type.']);
        } catch (DocumentTypeNotFound) {
            return $this->renderFailure($access, $values, ['Select an active document type.']);
        } catch (InvalidPersistedFamilyResult) {
            return $this->renderFailure($access, $values, ['The operation could not be confirmed.']);
        } catch (InvalidFamilyState) {
            return $this->renderFailure(
                $access,
                $values,
                ['Selected resource is not available for this Family.'],
            );
        }

        $this->session->put(self::FLASH_SUCCESS_KEY, $success);

        return $this->redirect('/representative/resources', 303);
    }

    /**
     * @param callable(FamilyContext, int): mixed $handle
     * @param null|callable(int, FamilyResourcesOutput, FamilyContext): ?string $authorize
     */
    private function resourceStatus(
        string $field,
        string $collection,
        callable $handle,
        string $success,
        ?callable $authorize = null,
    ): string {
        return $this->execute(
            function (
                array $values,
                FamilyResourcesOutput $resources,
                FamilyOutput $family,
                FamilyContext $context,
            ) use ($field, $collection, $authorize): array {
                $errors = [];
                $id = $this->requiredResourceId($values, $field, $resources->{$collection}, $errors);
                if ($id !== null && $authorize !== null) {
                    $authorizationError = $authorize($id, $resources, $context);
                    if ($authorizationError !== null) {
                        $errors[] = $authorizationError;
                    }
                }

                return [$errors, [$id]];
            },
            fn (FamilyContext $context, array $data): mixed => $handle($context, $data[0]),
            $success,
        );
    }

    /**
     * @param callable(object, FamilyContext, list<RepresentativeFamilyStudentOption>): bool $authorize
     * @param callable(FamilyContext, object, DateTimeImmutable): mixed $handle
     */
    private function endAssignment(
        string $collection,
        callable $authorize,
        callable $handle,
        string $success,
    ): string {
        return $this->execute(
            function (
                array $values,
                FamilyResourcesOutput $resources,
                FamilyOutput $family,
                FamilyContext $context,
                array $students,
            ) use ($collection, $authorize): array {
                $errors = [];
                $assignmentId = $this->positiveInteger($values['assignment_id'] ?? null);
                $assignment = $assignmentId === null
                    ? null
                    : $this->findById($resources->{$collection}, $assignmentId, true);
                if ($assignment === null || !$authorize($assignment, $context, $students)) {
                    $errors[] = 'Selected resource is not available for this Family.';
                }
                $endedAt = $this->requiredTimestamp($values, 'ended_at', $errors);

                return [$errors, [$assignment, $endedAt]];
            },
            fn (FamilyContext $context, array $data): mixed => $handle($context, $data[0], $data[1]),
            $success,
        );
    }

    /** @return array{list<string>, array<int, mixed>} */
    private function addressInput(
        array $values,
        FamilyResourcesOutput $resources,
        ?FamilyContext $context,
        bool $updating,
    ): array {
        $errors = [];
        $data = [];
        if ($updating) {
            $addressId = $this->requiredResourceId(
                $values,
                'family_address_id',
                $resources->addresses,
                $errors,
            );
            $data[] = $addressId;
            if ($addressId !== null
                && $context !== null
                && $this->isAddressUsedByAnotherRepresentative($addressId, $resources, $context)
            ) {
                $errors[] = 'This address cannot be changed from your account.';
            }
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

    /**
     * @return array{FamilyResourcesOutput, FamilyOutput, FamilyResourceFormOptions,
     *     list<RepresentativeFamilyStudentOption>}
     */
    private function loadContext(FamilyContext $context): array
    {
        $resources = $this->getFamilyResources->handle($context->familyId);
        $family = $this->getFamilyMembership->handle($context->familyId);
        $students = [];
        foreach ($family->students as $membership) {
            if (!$membership->isActive) {
                continue;
            }
            $student = $this->getStudent->handle($membership->studentId);
            $person = $this->getPerson->handle($student->personId);
            $displayName = trim(implode(' ', array_filter([
                $person->firstName,
                $person->middleName,
                $person->firstSurname,
                $person->secondSurname,
            ], static fn (?string $part): bool => $part !== null && $part !== '')));
            if ($displayName === '') {
                throw new PersonNotFound('Authorized Student Person was not found.');
            }
            $students[] = new RepresentativeFamilyStudentOption($student->id, $displayName);
        }

        return [$resources, $family, $this->optionsProvider->get(), $students];
    }

    private function renderFailure(RepresentativeFamilyAccess $access, array $values, array $errors): string
    {
        try {
            [$resources, $family, $options, $students] = $this->loadContext($access->context);
        } catch (FamilyNotFound|StudentNotFound|PersonNotFound) {
            return $this->forbidden();
        } catch (InvalidPersistedFamilyResult) {
            return $this->contextError('The operation could not be confirmed.', 422);
        }

        return $this->resourceView(
            $access,
            $resources,
            $family,
            $options,
            $students,
            $values,
            $errors,
            null,
            null,
            422,
        );
    }

    /**
     * @param list<RepresentativeFamilyStudentOption> $students
     * @param list<string> $errors
     */
    private function resourceView(
        RepresentativeFamilyAccess $access,
        FamilyResourcesOutput $resources,
        FamilyOutput $family,
        FamilyResourceFormOptions $options,
        array $students,
        array $values,
        array $errors,
        ?string $successMessage,
        ?string $errorMessage,
        int $status = 200,
    ): string {
        http_response_code($status);
        $studentIds = array_fill_keys(
            array_map(static fn (RepresentativeFamilyStudentOption $student): int => $student->studentId, $students),
            true,
        );

        return $this->view('representative-portal.resources', [
            'title' => 'Family resources',
            'context' => $access->context,
            'canChangeFamily' => count($access->authorizedFamilies) > 1,
            'resources' => $resources,
            'family' => $family,
            'options' => $options,
            'students' => $students,
            'ownRepresentativeAddressAssignments' => array_values(array_filter(
                $resources->representativeAddressAssignments,
                fn (object $assignment): bool =>
                    $assignment->representativeId === $access->context->representativeId,
            )),
            'studentAddressAssignments' => array_values(array_filter(
                $resources->studentAddressAssignments,
                static fn (object $assignment): bool => isset($studentIds[$assignment->studentId]),
            )),
            'emergencyContactAssignments' => array_values(array_filter(
                $resources->emergencyContactAssignments,
                static fn (object $assignment): bool => isset($studentIds[$assignment->studentId]),
            )),
            'authorizedPickupAssignments' => array_values(array_filter(
                $resources->authorizedPickupAssignments,
                static fn (object $assignment): bool => isset($studentIds[$assignment->studentId]),
            )),
            'csrfToken' => $this->csrf->token(),
            'values' => $values,
            'errors' => $errors,
            'successMessage' => $successMessage,
            'errorMessage' => $errorMessage,
        ]);
    }

    /** @return array{?RepresentativeFamilyAccess, string} */
    private function access(bool $post): array
    {
        $access = $this->resolveFamilyContext->handle();
        if ($access === null) {
            return [null, $this->forbidden()];
        }
        if ($access->authorizedFamilies === []) {
            return [null, $this->forbidden('No family context is currently available.')];
        }
        if ($access->context === null || $access->requiresSelection) {
            return [null, $this->redirect('/representative', $post ? 303 : 302)];
        }

        return [$access, ''];
    }

    private function isAddressUsedByAnotherRepresentative(
        int $addressId,
        FamilyResourcesOutput $resources,
        FamilyContext $context,
    ): bool {
        foreach ($resources->representativeAddressAssignments as $assignment) {
            if ($assignment->isActive
                && $assignment->familyAddressId === $addressId
                && $assignment->representativeId !== $context->representativeId
            ) {
                return true;
            }
        }

        return false;
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

    /** @param list<RepresentativeFamilyStudentOption> $students */
    private function requiredStudentId(array $values, array $students, array &$errors): ?int
    {
        $studentId = $this->positiveInteger($values['student_id'] ?? null);
        if ($studentId === null || !$this->hasStudent($students, $studentId)) {
            $errors[] = 'Selected resource is not available for this Family.';

            return null;
        }

        return $studentId;
    }

    /** @param list<RepresentativeFamilyStudentOption> $students */
    private function hasStudent(array $students, int $studentId): bool
    {
        foreach ($students as $student) {
            if ($student->studentId === $studentId) {
                return true;
            }
        }

        return false;
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

    private function flash(string $key): ?string
    {
        $value = $this->session->pull($key);

        return is_string($value) ? $value : null;
    }

    private function forbidden(string $message = 'The requested Family resource is not available.'): string
    {
        return $this->contextError($message, 403);
    }

    private function contextError(string $message, int $status): string
    {
        http_response_code($status);
        $escaped = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');

        return '<h1>Family resources unavailable</h1><p role="alert">' . $escaped
            . '</p><p><a href="/representative">Back to Representative Portal</a></p>';
    }

    private function redirect(string $location, int $status): string
    {
        header('Location: ' . $location);
        http_response_code($status);

        return '';
    }
}
