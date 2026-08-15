<?php

declare(strict_types=1);

namespace App\Family\Http;

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
use App\IdentityAccess\Application\Contract\SessionManager;
use App\InstitutionalDocuments\Application\RepresentativePortal\Exception\ActiveAcademicPeriodUnavailable;
use App\InstitutionalDocuments\Application\RepresentativePortal\Exception\RepresentativeAcknowledgementAccessUnavailable;
use App\InstitutionalDocuments\Application\RepresentativePortal\Exception\RepresentativeAcknowledgementsRequired;
use App\Person\Application\Exception\PersonNotFound;
use App\Student\Application\Exception\StudentNotFound;
use Core\Http\Request;
use DateTimeImmutable;
use DateTimeZone;

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
    ) {
    }

    public function index(): string
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
            return $this->contextError('The operation could not be confirmed.', 422);
        }

        return $this->resourceView(
            $resources,
            $this->optionsProvider->get(),
            [],
            [],
            $this->flash(self::FLASH_SUCCESS_KEY),
            $this->flash(self::FLASH_ERROR_KEY),
        );
    }

    public function createAddress(): string
    {
        return $this->execute(
            fn (array $values): array => $this->addressInput($values, false),
            fn (int $familyId, array $data): mixed => $this->addresses->create($familyId, ...$data),
            'Address created successfully.',
        );
    }

    public function updateAddress(): string
    {
        return $this->execute(
            fn (array $values): array => $this->addressInput($values, true),
            fn (int $familyId, array $data): mixed => $this->addresses->update($familyId, ...$data),
            'Address updated successfully.',
        );
    }

    public function activateAddress(): string
    {
        return $this->resourceStatus(
            'family_address_id',
            fn (int $familyId, int $id): mixed => $this->addresses->activate($familyId, $id),
            'Address activated successfully.',
        );
    }

    public function deactivateAddress(): string
    {
        return $this->resourceStatus(
            'family_address_id',
            fn (int $familyId, int $id): mixed => $this->addresses->deactivate($familyId, $id),
            'Address deactivated successfully.',
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
                    'Selected resource is not available for this Family.',
                    $errors,
                );
                $startedAt = $this->requiredTimestamp($values, 'started_at', $errors);

                return [$errors, [$addressId, $startedAt]];
            },
            fn (int $familyId, array $data): mixed => $this->addresses->assignSelf($familyId, ...$data),
            'Your Address assignment was created successfully.',
        );
    }

    public function endRepresentativeAddress(): string
    {
        return $this->endAssignment(
            fn (int $familyId, int $assignmentId, DateTimeImmutable $endedAt): mixed =>
                $this->addresses->endSelf($familyId, $assignmentId, $endedAt),
            'Your Address assignment was ended successfully.',
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
                    'Selected resource is not available for this Family.',
                    $errors,
                );
                $addressId = $this->requiredPositiveInteger(
                    $values,
                    'family_address_id',
                    'Selected resource is not available for this Family.',
                    $errors,
                );
                $startedAt = $this->requiredTimestamp($values, 'started_at', $errors);

                return [$errors, [$studentId, $addressId, $startedAt]];
            },
            fn (int $familyId, array $data): mixed => $this->addresses->assignStudent($familyId, ...$data),
            'Student Address assignment was created successfully.',
        );
    }

    public function endStudentAddress(): string
    {
        return $this->endAssignment(
            fn (int $familyId, int $assignmentId, DateTimeImmutable $endedAt): mixed =>
                $this->addresses->endStudent($familyId, $assignmentId, $endedAt),
            'Student Address assignment was ended successfully.',
        );
    }

    public function createEmergencyContact(): string
    {
        return $this->execute(
            fn (array $values, FamilyResourceFormOptions $options): array =>
                $this->emergencyContactInput($values, $options, false),
            fn (int $familyId, array $data): mixed => $this->emergencyContacts->create($familyId, ...$data),
            'Emergency Contact created successfully.',
        );
    }

    public function updateEmergencyContact(): string
    {
        return $this->execute(
            fn (array $values, FamilyResourceFormOptions $options): array =>
                $this->emergencyContactInput($values, $options, true),
            fn (int $familyId, array $data): mixed => $this->emergencyContacts->update($familyId, ...$data),
            'Emergency Contact updated successfully.',
        );
    }

    public function activateEmergencyContact(): string
    {
        return $this->resourceStatus(
            'family_emergency_contact_id',
            fn (int $familyId, int $id): mixed => $this->emergencyContacts->activate($familyId, $id),
            'Emergency Contact activated successfully.',
        );
    }

    public function deactivateEmergencyContact(): string
    {
        return $this->resourceStatus(
            'family_emergency_contact_id',
            fn (int $familyId, int $id): mixed => $this->emergencyContacts->deactivate($familyId, $id),
            'Emergency Contact deactivated successfully.',
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
                    'Selected resource is not available for this Family.',
                    $errors,
                );
                $studentId = $this->requiredPositiveInteger(
                    $values,
                    'student_id',
                    'Selected resource is not available for this Family.',
                    $errors,
                );
                $priority = $this->optionalPositiveInteger($values['priority'] ?? '', 'priority', $errors);
                $startedAt = $this->requiredTimestamp($values, 'started_at', $errors);

                return [$errors, [$contactId, $studentId, $priority, $startedAt]];
            },
            fn (int $familyId, array $data): mixed => $this->emergencyContacts->assign($familyId, ...$data),
            'Emergency Contact assignment was created successfully.',
        );
    }

    public function endEmergencyContact(): string
    {
        return $this->endAssignment(
            fn (int $familyId, int $assignmentId, DateTimeImmutable $endedAt): mixed =>
                $this->emergencyContacts->end($familyId, $assignmentId, $endedAt),
            'Emergency Contact assignment was ended successfully.',
        );
    }

    public function createAuthorizedPickup(): string
    {
        return $this->execute(
            fn (array $values, FamilyResourceFormOptions $options): array =>
                $this->authorizedPickupInput($values, $options, false),
            fn (int $familyId, array $data): mixed => $this->authorizedPickups->create($familyId, ...$data),
            'Authorized Pickup created successfully.',
        );
    }

    public function updateAuthorizedPickup(): string
    {
        return $this->execute(
            fn (array $values, FamilyResourceFormOptions $options): array =>
                $this->authorizedPickupInput($values, $options, true),
            fn (int $familyId, array $data): mixed => $this->authorizedPickups->update($familyId, ...$data),
            'Authorized Pickup updated successfully.',
        );
    }

    public function activateAuthorizedPickup(): string
    {
        return $this->resourceStatus(
            'family_authorized_pickup_id',
            fn (int $familyId, int $id): mixed => $this->authorizedPickups->activate($familyId, $id),
            'Authorized Pickup activated successfully.',
        );
    }

    public function deactivateAuthorizedPickup(): string
    {
        return $this->resourceStatus(
            'family_authorized_pickup_id',
            fn (int $familyId, int $id): mixed => $this->authorizedPickups->deactivate($familyId, $id),
            'Authorized Pickup deactivated successfully.',
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
                    'Selected resource is not available for this Family.',
                    $errors,
                );
                $studentId = $this->requiredPositiveInteger(
                    $values,
                    'student_id',
                    'Selected resource is not available for this Family.',
                    $errors,
                );
                $startedAt = $this->requiredTimestamp($values, 'started_at', $errors);

                return [$errors, [$pickupId, $studentId, $startedAt]];
            },
            fn (int $familyId, array $data): mixed => $this->authorizedPickups->assign($familyId, ...$data),
            'Authorized Pickup assignment was created successfully.',
        );
    }

    public function endAuthorizedPickup(): string
    {
        return $this->endAssignment(
            fn (int $familyId, int $assignmentId, DateTimeImmutable $endedAt): mixed =>
                $this->authorizedPickups->end($familyId, $assignmentId, $endedAt),
            'Authorized Pickup assignment was ended successfully.',
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
            $this->session->put(self::FLASH_ERROR_KEY, 'Your form expired. Please try again.');

            return $this->redirect('/representative/resources', 303);
        }

        $familyId = $this->positiveInteger($values['family_id'] ?? null);
        if ($familyId === null) {
            $scalarErrors[] = 'Selected resource is not available for this Family.';
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
                'Complete Institutional Acknowledgements before updating family data.',
            );

            return $this->redirect('/representative/acknowledgements', 303);
        } catch (ActiveAcademicPeriodUnavailable) {
            $this->session->put(
                self::FLASH_ERROR_KEY,
                'No active Academic Period is currently configured.',
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
            return $this->renderFailure($values, ['This address cannot be changed from your account.']);
        } catch (RepresentativeFamilyResourceUnavailable|RepresentativeFamilyStudentUnavailable|InvalidFamilyState) {
            return $this->renderFailure($values, ['Selected resource is not available for this Family.']);
        } catch (RelationshipTypeNotFound) {
            return $this->renderFailure($values, ['Select an active relationship type.']);
        } catch (DocumentTypeNotFound) {
            return $this->renderFailure($values, ['Select an active document type.']);
        } catch (InvalidPersistedFamilyResult) {
            return $this->renderFailure($values, ['The operation could not be confirmed.']);
        }

        $this->session->put(self::FLASH_SUCCESS_KEY, $success);

        return $this->redirect('/representative/resources', 303);
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
                    'Selected resource is not available for this Family.',
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
                    'Selected resource is not available for this Family.',
                    $errors,
                );
                $endedAt = $this->requiredTimestamp($values, 'ended_at', $errors);

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
                'Selected resource is not available for this Family.',
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
        FamilyResourceFormOptions $options,
        bool $updating,
    ): array {
        $errors = [];
        $data = [];
        if ($updating) {
            $data[] = $this->requiredPositiveInteger(
                $values,
                'family_emergency_contact_id',
                'Selected resource is not available for this Family.',
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
        FamilyResourceFormOptions $options,
        bool $updating,
    ): array {
        $errors = [];
        $data = [];
        if ($updating) {
            $data[] = $this->requiredPositiveInteger(
                $values,
                'family_authorized_pickup_id',
                'Selected resource is not available for this Family.',
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
            return $this->contextError('The operation could not be confirmed.', 422);
        }

        return $this->resourceView(
            $resources,
            $this->optionsProvider->get(),
            $values,
            $errors,
            null,
            null,
            422,
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
    ): string {
        http_response_code($status);

        return $this->view('representative-portal.resources', [
            'title' => 'Family resources',
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
        ]);
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
