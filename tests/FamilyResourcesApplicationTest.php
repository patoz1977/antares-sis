<?php

declare(strict_types=1);

namespace Tests;

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
use App\Family\Application\DocumentTypeLookup;
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
use App\Family\Application\GetFamilyResources;
use App\Family\Application\UpdateFamilyAddress;
use App\Family\Application\UpdateFamilyAuthorizedPickup;
use App\Family\Application\UpdateFamilyEmergencyContact;
use App\Family\Domain\AuthorizedPickupAssignment;
use App\Family\Domain\EmergencyContactAssignment;
use App\Family\Domain\Exception\InvalidFamilyState;
use App\Family\Domain\Family;
use App\Family\Domain\FamilyAddress;
use App\Family\Domain\FamilyAuthorizedPickup;
use App\Family\Domain\FamilyEmergencyContact;
use App\Family\Domain\FamilyRepresentative;
use App\Family\Domain\FamilyResourceStatus;
use App\Family\Domain\FamilyStatus;
use App\Family\Domain\FamilyStudent;
use App\Family\Domain\RepresentativeAddressAssignment;
use App\Family\Domain\StudentAddressAssignment;
use App\Family\Domain\ValueObject\Address;
use App\Family\Domain\ValueObject\AddressLabel;
use App\Family\Domain\ValueObject\AuthorizedPickupAssignmentId;
use App\Family\Domain\ValueObject\AuthorizedPickupInformation;
use App\Family\Domain\ValueObject\DisplayName;
use App\Family\Domain\ValueObject\EmergencyContactAssignmentId;
use App\Family\Domain\ValueObject\EmergencyContactInformation;
use App\Family\Domain\ValueObject\EmergencyContactPriority;
use App\Family\Domain\ValueObject\FamilyAddressId;
use App\Family\Domain\ValueObject\FamilyAuthorizedPickupId;
use App\Family\Domain\ValueObject\FamilyEmergencyContactId;
use App\Family\Domain\ValueObject\FamilyId;
use App\Family\Domain\ValueObject\FamilyRepresentativeId;
use App\Family\Domain\ValueObject\FamilyResourceName;
use App\Family\Domain\ValueObject\FamilyStudentId;
use App\Family\Domain\ValueObject\RelationshipTypeId;
use App\Family\Domain\ValueObject\RepresentativeAddressAssignmentId;
use App\Family\Domain\ValueObject\RepresentativeId;
use App\Family\Domain\ValueObject\StudentAddressAssignmentId;
use App\Family\Domain\ValueObject\StudentId;
use DateTimeImmutable;
use DateTimeZone;
use ReflectionClass;
use Tests\Support\TestRunner;

function registerFamilyResourcesApplicationTests(TestRunner $runner): void
{
    $runner->add('GetFamilyResources returns all persisted resources active state and history', function (): void {
        $families = familyResourcesApplicationRepository();
        $output = (new GetFamilyResources($families))->handle(500);

        assertSameValue(500, $output->familyId);
        assertSameValue('Resource Application Family', $output->displayName);
        assertSameValue('ACTIVE', $output->status);
        assertSameValue([11, 12], array_map(static fn ($item): int => $item->id, $output->addresses));
        assertSameValue(['ACTIVE', 'INACTIVE'], array_map(static fn ($item): string => $item->status, $output->addresses));
        assertSameValue([81], array_map(static fn ($item): int => $item->id, $output->representativeAddressAssignments));
        assertSameValue([82], array_map(static fn ($item): int => $item->id, $output->studentAddressAssignments));
        assertSameValue([21, 22], array_map(static fn ($item): int => $item->id, $output->emergencyContacts));
        assertSameValue([83], array_map(static fn ($item): int => $item->id, $output->emergencyContactAssignments));
        assertSameValue([31, 32], array_map(static fn ($item): int => $item->id, $output->authorizedPickups));
        assertSameValue([84], array_map(static fn ($item): int => $item->id, $output->authorizedPickupAssignments));
        assertSameValue(false, $output->representativeAddressAssignments[0]->isActive);
        assertSameValue(false, $output->studentAddressAssignments[0]->isActive);
        assertSameValue(false, $output->emergencyContactAssignments[0]->isActive);
        assertSameValue(false, $output->authorizedPickupAssignments[0]->isActive);
        assertSameValue(1, $output->emergencyContactAssignments[0]->priority);
        assertSameValue(0, $families->saveCalls());
        assertSameValue(true, (new ReflectionClass($output))->isReadOnly());
    });

    $runner->add('GetFamilyResources rejects absent Family with no write', function (): void {
        $families = new InMemoryFamilyApplicationRepository();
        assertThrows(static fn () => (new GetFamilyResources($families))->handle(999), FamilyNotFound::class);
        assertSameValue(0, $families->saveCalls());
    });

    $runner->add('Address cases create update lifecycle and persist one exact mutation', function (): void {
        $families = familyResourcesApplicationRepository();
        $created = (new CreateFamilyAddress($families))->handle(familyResourcesAddressCreateInput());
        assertSameValue(true, $created->id > 0);
        assertSameValue('Home', $created->label);
        assertSameValue('-0.1806530', $created->latitude);
        assertSameValue('-78.4678380', $created->longitude);
        assertSameValue('ACTIVE', $created->status);
        assertSameValue(1, $families->saveCalls());

        $updated = (new UpdateFamilyAddress($families))->handle(new UpdateFamilyAddressInput(
            500, $created->id, 'Main home', 'Updated avenue', 'N42', 'Cross street',
            'North sector', 'Blue door', null, null,
        ));
        assertSameValue($created->id, $updated->id);
        assertSameValue('Updated avenue', $updated->mainStreet);
        assertSameValue(null, $updated->latitude);
        assertSameValue('ACTIVE', $updated->status);
        assertSameValue(2, $families->saveCalls());

        $inactive = (new DeactivateFamilyAddress($families))->handle(
            new DeactivateFamilyAddressInput(500, $created->id)
        );
        assertSameValue('INACTIVE', $inactive->status);
        $active = (new ActivateFamilyAddress($families))->handle(
            new ActivateFamilyAddressInput(500, $created->id)
        );
        assertSameValue('ACTIVE', $active->status);
        assertSameValue(4, $families->saveCalls());
    });

    $runner->add('Address assignment cases preserve history replacement and explicit times', function (): void {
        $families = familyResourcesApplicationRepository();
        (new ActivateFamilyAddress($families))->handle(new ActivateFamilyAddressInput(500, 12));
        $first = (new AssignRepresentativeAddress($families))->handle(
            new AssignRepresentativeAddressInput(500, 101, 11, familyResourcesApplicationTime('2026-08-10 10:11:12.999'))
        );
        $replacement = (new AssignRepresentativeAddress($families))->handle(
            new AssignRepresentativeAddressInput(500, 101, 12, familyResourcesApplicationTime('2026-08-11 12:13:14.777'))
        );
        assertSameValue(true, $first->id > 0 && $replacement->id > 0 && $first->id !== $replacement->id);
        $afterReplacement = (new GetFamilyResources($families))->handle(500);
        $firstPersisted = familyResourcesFindById($afterReplacement->representativeAddressAssignments, $first->id);
        assertSameValue('2026-08-11 12:13:14', $firstPersisted->endedAt?->format('Y-m-d H:i:s'));
        assertSameValue(true, $replacement->isActive);
        $ended = (new EndRepresentativeAddressAssignment($families))->handle(
            new EndRepresentativeAddressAssignmentInput(500, 101, familyResourcesApplicationTime('2026-08-12 09:00:00'))
        );
        assertSameValue($replacement->id, $ended->id);
        assertSameValue(false, $ended->isActive);

        $studentFirst = (new AssignStudentAddress($families))->handle(
            new AssignStudentAddressInput(500, 301, 11, familyResourcesApplicationTime('2026-08-10 08:00:00'))
        );
        $studentReplacement = (new AssignStudentAddress($families))->handle(
            new AssignStudentAddressInput(500, 301, 12, familyResourcesApplicationTime('2026-08-11 08:00:00'))
        );
        assertSameValue(true, $studentFirst->id !== $studentReplacement->id);
        $studentEnded = (new EndStudentAddressAssignment($families))->handle(
            new EndStudentAddressAssignmentInput(500, 301, familyResourcesApplicationTime('2026-08-12 08:00:00'))
        );
        assertSameValue($studentReplacement->id, $studentEnded->id);
        assertSameValue(false, $studentEnded->isActive);
        assertSameValue(7, $families->saveCalls());
    });

    $runner->add('Emergency Contact cases validate catalog lifecycle priority assignment and ending', function (): void {
        $families = familyResourcesApplicationRepository();
        $relationships = new FakeRelationshipTypeLookup([201, 202]);
        $created = (new CreateFamilyEmergencyContact($families, $relationships))->handle(
            familyResourcesEmergencyCreateInput()
        );
        assertSameValue(true, $created->id > 0);
        assertSameValue('ACTIVE', $created->status);
        $updated = (new UpdateFamilyEmergencyContact($families, $relationships))->handle(
            new UpdateFamilyEmergencyContactInput(
                500, $created->id, 'Updated contact', 202, '099 123', '02 555',
                'updated@example.test', 'Updated note',
            )
        );
        assertSameValue($created->id, $updated->id);
        assertSameValue(202, $updated->relationshipTypeId);
        assertSameValue('updated@example.test', $updated->email);
        assertSameValue('INACTIVE', (new DeactivateFamilyEmergencyContact($families))->handle(
            new DeactivateFamilyEmergencyContactInput(500, $created->id)
        )->status);
        assertSameValue('ACTIVE', (new ActivateFamilyEmergencyContact($families))->handle(
            new ActivateFamilyEmergencyContactInput(500, $created->id)
        )->status);
        $assigned = (new AssignEmergencyContact($families))->handle(
            new AssignEmergencyContactInput(
                500, $created->id, 301, 7, familyResourcesApplicationTime('2026-08-12 10:00:00.333')
            )
        );
        assertSameValue(7, $assigned->priority);
        assertSameValue('2026-08-12 10:00:00', $assigned->startedAt->format('Y-m-d H:i:s'));
        $ended = (new EndEmergencyContactAssignment($families))->handle(
            new EndEmergencyContactAssignmentInput(
                500, $created->id, 301, familyResourcesApplicationTime('2026-08-13 10:00:00')
            )
        );
        assertSameValue($assigned->id, $ended->id);
        assertSameValue(false, $ended->isActive);
        assertSameValue(6, $families->saveCalls());
    });

    $runner->add('Authorized Pickup cases validate both catalogs optional identity lifecycle and assignments', function (): void {
        $families = familyResourcesApplicationRepository();
        $relationships = new FakeRelationshipTypeLookup([201, 202]);
        $documents = new FakeFamilyResourceDocumentTypeLookup([9, 10]);
        $created = (new CreateFamilyAuthorizedPickup($families, $relationships, $documents))->handle(
            familyResourcesPickupCreateInput()
        );
        assertSameValue(true, $created->id > 0);
        assertSameValue(9, $created->documentTypeId);
        assertSameValue('ID-900', $created->documentNumber);
        $updated = (new UpdateFamilyAuthorizedPickup($families, $relationships, $documents))->handle(
            new UpdateFamilyAuthorizedPickupInput(
                500, $created->id, 'Updated pickup', 202, '098 000', null, null, null, 'No document yet',
            )
        );
        assertSameValue(null, $updated->documentTypeId);
        assertSameValue(null, $updated->documentNumber);
        assertSameValue('INACTIVE', (new DeactivateFamilyAuthorizedPickup($families))->handle(
            new DeactivateFamilyAuthorizedPickupInput(500, $created->id)
        )->status);
        assertSameValue('ACTIVE', (new ActivateFamilyAuthorizedPickup($families))->handle(
            new ActivateFamilyAuthorizedPickupInput(500, $created->id)
        )->status);
        $assigned = (new AssignAuthorizedPickup($families))->handle(
            new AssignAuthorizedPickupInput(
                500, $created->id, 301, familyResourcesApplicationTime('2026-08-14 10:00:00')
            )
        );
        assertSameValue(true, $assigned->id > 0 && $assigned->isActive);
        $ended = (new EndAuthorizedPickupAssignment($families))->handle(
            new EndAuthorizedPickupAssignmentInput(
                500, $created->id, 301, familyResourcesApplicationTime('2026-08-15 10:00:00')
            )
        );
        assertSameValue($assigned->id, $ended->id);
        assertSameValue(false, $ended->isActive);
        assertSameValue(6, $families->saveCalls());
    });

    $runner->add('Family resource external references fail before persistence', function (): void {
        $families = familyResourcesApplicationRepository();
        assertThrows(
            static fn () => (new CreateFamilyEmergencyContact(
                $families,
                new FakeRelationshipTypeLookup([]),
            ))->handle(familyResourcesEmergencyCreateInput()),
            RelationshipTypeNotFound::class,
        );
        assertThrows(
            static fn () => (new CreateFamilyAuthorizedPickup(
                $families,
                new FakeRelationshipTypeLookup([201]),
                new FakeFamilyResourceDocumentTypeLookup([]),
            ))->handle(familyResourcesPickupCreateInput()),
            DocumentTypeNotFound::class,
        );
        assertSameValue(0, $families->saveCalls());
    });

    $runner->add('Family resource inputs delegate Domain pair membership status and uniqueness rules', function (): void {
        $families = familyResourcesApplicationRepository();
        $partialGeolocation = familyResourcesAddressCreateInput();
        $partialGeolocation = new CreateFamilyAddressInput(
            500, $partialGeolocation->label, $partialGeolocation->mainStreet,
            null, null, null, null, '-0.18', null,
        );
        assertThrows(
            static fn () => (new CreateFamilyAddress($families))->handle($partialGeolocation),
            InvalidFamilyState::class,
        );
        assertThrows(
            static fn () => (new AssignStudentAddress($families))->handle(
                new AssignStudentAddressInput(500, 301, 12, familyResourcesApplicationTime('2026-08-12'))
            ),
            InvalidFamilyState::class,
        );
        assertThrows(
            static fn () => (new CreateFamilyAuthorizedPickup(
                $families,
                new FakeRelationshipTypeLookup([201]),
                new FakeFamilyResourceDocumentTypeLookup([9]),
            ))->handle(new CreateFamilyAuthorizedPickupInput(
                500, 'Partial pickup', 201, '099', null, 9, null, null,
            )),
            InvalidFamilyState::class,
        );
        (new AssignEmergencyContact($families))->handle(new AssignEmergencyContactInput(
            500, 21, 301, 7, familyResourcesApplicationTime('2026-08-12 10:00:00'),
        ));
        assertThrows(
            static fn () => (new AssignEmergencyContact($families))->handle(new AssignEmergencyContactInput(
                500, 22, 301, 7, familyResourcesApplicationTime('2026-08-13 10:00:00'),
            )),
            InvalidFamilyState::class,
        );
        assertSameValue(1, $families->saveCalls());
    });

    $runner->add('Family resource cases reject wrong Family missing identities and omitted mutations', function (): void {
        $wrongFamily = familyResourcesApplicationRepository();
        $wrongFamily->returnWrongFamilyId();
        assertThrows(
            static fn () => (new CreateFamilyAddress($wrongFamily))->handle(familyResourcesAddressCreateInput()),
            InvalidPersistedFamilyResult::class,
        );

        foreach (familyResourcesIdentityFailureCases() as [$group, $operation]) {
            $families = familyResourcesApplicationRepository();
            $families->returnWithoutNewResourceIdentity($group);
            assertThrows(static fn () => $operation($families), InvalidPersistedFamilyResult::class);
            assertSameValue(1, $families->saveCalls());
        }

        $omitted = familyResourcesApplicationRepository();
        $omitted->omitRequestedMutation('address');
        assertThrows(
            static fn () => (new CreateFamilyAddress($omitted))->handle(familyResourcesAddressCreateInput()),
            InvalidPersistedFamilyResult::class,
        );
    });

    $runner->add('Family Resources Application remains explicit isolated and delivery-wired', function (): void {
        $directory = dirname(__DIR__) . '/app/Family/Application';
        $files = array_merge(
            glob($directory . '/*FamilyAddress.php') ?: [],
            glob($directory . '/*AddressAssignment.php') ?: [],
            glob($directory . '/*EmergencyContact.php') ?: [],
            glob($directory . '/*EmergencyContactAssignment.php') ?: [],
            glob($directory . '/*AuthorizedPickup.php') ?: [],
            glob($directory . '/*AuthorizedPickupAssignment.php') ?: [],
            [$directory . '/GetFamilyResources.php', $directory . '/FamilyResourcesApplicationSupport.php'],
        );
        $source = '';
        foreach (array_unique($files) as $file) {
            $source .= (string) file_get_contents($file);
        }
        foreach (['PDO', 'PDOException', 'ConnectionManager', 'Pdo', 'Http', 'Request', 'Session',
            'IdentityAccess', 'Enrollment', 'TransactionRunner', 'Carbon', 'new DateTime'] as $forbidden) {
            assertSameValue(false, str_contains($source, $forbidden));
        }

        $applicationCases = familyResourcesApplicationUseCases();
        assertSameValue(21, count($applicationCases));
        foreach ($applicationCases as $class) {
            assertSameValue(true, (new ReflectionClass($class))->isReadOnly());
        }
        foreach (familyResourcesApplicationInputs() as $class) {
            assertSameValue(true, (new ReflectionClass($class))->isReadOnly());
        }
        assertSameValue(
            ['id', 'displayName', 'status', 'representatives', 'students'],
            array_map(
                static fn ($property): string => $property->getName(),
                (new ReflectionClass(FamilyOutput::class))->getProperties(),
            ),
        );
        assertSameValue(1, count((new ReflectionClass(DocumentTypeLookup::class))->getMethods()));
        assertSameValue(1, count((new ReflectionClass(\App\Family\Application\RelationshipTypeLookup::class))->getMethods()));
        $bootstrap = (string) file_get_contents(dirname(__DIR__) . '/bootstrap/app.php');
        assertSameValue(true, str_contains($bootstrap, 'DocumentTypeLookup'));
        assertSameValue(true, str_contains($bootstrap, 'GetFamilyResources'));
    });
}

function familyResourcesApplicationRepository(): InMemoryFamilyApplicationRepository
{
    $repository = new InMemoryFamilyApplicationRepository();
    $repository->seed(familyResourcesApplicationAggregate());

    return $repository;
}

function familyResourcesApplicationAggregate(): Family
{
    return Family::reconstitute(
        new FamilyId(500),
        new DisplayName('Resource Application Family'),
        FamilyStatus::Active,
        [
            new FamilyRepresentative(
                new FamilyRepresentativeId(1), new RepresentativeId(101), new RelationshipTypeId(201),
                true, familyResourcesApplicationTime('2026-01-01'), null,
            ),
            new FamilyRepresentative(
                new FamilyRepresentativeId(2), new RepresentativeId(102), new RelationshipTypeId(201),
                false, familyResourcesApplicationTime('2026-01-01'), null,
            ),
        ],
        [
            new FamilyStudent(
                new FamilyStudentId(3), new StudentId(301), familyResourcesApplicationTime('2026-01-01'), null,
            ),
            new FamilyStudent(
                new FamilyStudentId(4), new StudentId(302), familyResourcesApplicationTime('2026-01-01'), null,
            ),
        ],
        [
            familyResourcesApplicationAddress(11, FamilyResourceStatus::Active),
            familyResourcesApplicationAddress(12, FamilyResourceStatus::Inactive),
        ],
        [new RepresentativeAddressAssignment(
            new RepresentativeAddressAssignmentId(81), new FamilyAddressId(11), new RepresentativeId(101),
            familyResourcesApplicationTime('2026-06-01'), familyResourcesApplicationTime('2026-07-01'),
        )],
        [new StudentAddressAssignment(
            new StudentAddressAssignmentId(82), new FamilyAddressId(11), new StudentId(301),
            familyResourcesApplicationTime('2026-06-01'), familyResourcesApplicationTime('2026-07-01'),
        )],
        [
            familyResourcesApplicationContact(21, FamilyResourceStatus::Active),
            familyResourcesApplicationContact(22, FamilyResourceStatus::Inactive),
        ],
        [new EmergencyContactAssignment(
            new EmergencyContactAssignmentId(83), new FamilyEmergencyContactId(21), new StudentId(301),
            new EmergencyContactPriority(1), familyResourcesApplicationTime('2026-06-01'),
            familyResourcesApplicationTime('2026-07-01'),
        )],
        [
            familyResourcesApplicationPickup(31, FamilyResourceStatus::Active),
            familyResourcesApplicationPickup(32, FamilyResourceStatus::Inactive),
        ],
        [new AuthorizedPickupAssignment(
            new AuthorizedPickupAssignmentId(84), new FamilyAuthorizedPickupId(31), new StudentId(301),
            familyResourcesApplicationTime('2026-06-01'), familyResourcesApplicationTime('2026-07-01'),
        )],
    );
}

function familyResourcesApplicationAddress(int $id, FamilyResourceStatus $status): FamilyAddress
{
    return new FamilyAddress(
        new FamilyAddressId($id),
        new AddressLabel('Address ' . $id),
        new Address('Street ' . $id, null, null, null, null, null),
        $status,
    );
}

function familyResourcesApplicationContact(int $id, FamilyResourceStatus $status): FamilyEmergencyContact
{
    return new FamilyEmergencyContact(
        new FamilyEmergencyContactId($id),
        new FamilyResourceName('Contact ' . $id),
        new RelationshipTypeId(201),
        new EmergencyContactInformation('mobile ' . $id, null, null, null),
        $status,
    );
}

function familyResourcesApplicationPickup(int $id, FamilyResourceStatus $status): FamilyAuthorizedPickup
{
    return new FamilyAuthorizedPickup(
        new FamilyAuthorizedPickupId($id),
        new FamilyResourceName('Pickup ' . $id),
        new RelationshipTypeId(201),
        new AuthorizedPickupInformation('mobile ' . $id, null, null),
        null,
        $status,
    );
}

function familyResourcesAddressCreateInput(): CreateFamilyAddressInput
{
    return new CreateFamilyAddressInput(
        500, ' Home ', ' Main avenue ', ' N10 ', ' Cross ', ' Sector ', ' Reference ',
        '-0.180653', '-78.467838',
    );
}

function familyResourcesEmergencyCreateInput(): CreateFamilyEmergencyContactInput
{
    return new CreateFamilyEmergencyContactInput(
        500, 'Emergency person', 201, '099 999', null, 'emergency@example.test', null,
    );
}

function familyResourcesPickupCreateInput(): CreateFamilyAuthorizedPickupInput
{
    return new CreateFamilyAuthorizedPickupInput(
        500, 'Pickup person', 201, '098 888', null, 9, 'ID-900', null,
    );
}

/** @return list<array{0: string, 1: callable(InMemoryFamilyApplicationRepository): mixed}> */
function familyResourcesIdentityFailureCases(): array
{
    return [
        ['address', static fn ($families) => (new CreateFamilyAddress($families))->handle(
            familyResourcesAddressCreateInput()
        )],
        ['representative_address_assignment', static fn ($families) => (new AssignRepresentativeAddress($families))->handle(
            new AssignRepresentativeAddressInput(500, 101, 11, familyResourcesApplicationTime('2026-08-10'))
        )],
        ['student_address_assignment', static fn ($families) => (new AssignStudentAddress($families))->handle(
            new AssignStudentAddressInput(500, 301, 11, familyResourcesApplicationTime('2026-08-10'))
        )],
        ['emergency_contact', static fn ($families) => (new CreateFamilyEmergencyContact(
            $families, new FakeRelationshipTypeLookup([201]),
        ))->handle(familyResourcesEmergencyCreateInput())],
        ['emergency_contact_assignment', static fn ($families) => (new AssignEmergencyContact($families))->handle(
            new AssignEmergencyContactInput(500, 21, 301, 2, familyResourcesApplicationTime('2026-08-10'))
        )],
        ['authorized_pickup', static fn ($families) => (new CreateFamilyAuthorizedPickup(
            $families, new FakeRelationshipTypeLookup([201]), new FakeFamilyResourceDocumentTypeLookup([9]),
        ))->handle(familyResourcesPickupCreateInput())],
        ['authorized_pickup_assignment', static fn ($families) => (new AssignAuthorizedPickup($families))->handle(
            new AssignAuthorizedPickupInput(500, 31, 301, familyResourcesApplicationTime('2026-08-10'))
        )],
    ];
}

/** @param list<object> $outputs */
function familyResourcesFindById(array $outputs, int $id): object
{
    foreach ($outputs as $output) {
        if ($output->id === $id) {
            return $output;
        }
    }

    throw new \RuntimeException('Expected output identity was not found.');
}

function familyResourcesApplicationTime(string $value): DateTimeImmutable
{
    return new DateTimeImmutable($value, new DateTimeZone('UTC'));
}

/** @return list<class-string> */
function familyResourcesApplicationUseCases(): array
{
    return [
        GetFamilyResources::class,
        CreateFamilyAddress::class, UpdateFamilyAddress::class,
        ActivateFamilyAddress::class, DeactivateFamilyAddress::class,
        AssignRepresentativeAddress::class, EndRepresentativeAddressAssignment::class,
        AssignStudentAddress::class, EndStudentAddressAssignment::class,
        CreateFamilyEmergencyContact::class, UpdateFamilyEmergencyContact::class,
        ActivateFamilyEmergencyContact::class, DeactivateFamilyEmergencyContact::class,
        AssignEmergencyContact::class, EndEmergencyContactAssignment::class,
        CreateFamilyAuthorizedPickup::class, UpdateFamilyAuthorizedPickup::class,
        ActivateFamilyAuthorizedPickup::class, DeactivateFamilyAuthorizedPickup::class,
        AssignAuthorizedPickup::class, EndAuthorizedPickupAssignment::class,
    ];
}

/** @return list<class-string> */
function familyResourcesApplicationInputs(): array
{
    return [
        CreateFamilyAddressInput::class, UpdateFamilyAddressInput::class,
        ActivateFamilyAddressInput::class, DeactivateFamilyAddressInput::class,
        AssignRepresentativeAddressInput::class, EndRepresentativeAddressAssignmentInput::class,
        AssignStudentAddressInput::class, EndStudentAddressAssignmentInput::class,
        CreateFamilyEmergencyContactInput::class, UpdateFamilyEmergencyContactInput::class,
        ActivateFamilyEmergencyContactInput::class, DeactivateFamilyEmergencyContactInput::class,
        AssignEmergencyContactInput::class, EndEmergencyContactAssignmentInput::class,
        CreateFamilyAuthorizedPickupInput::class, UpdateFamilyAuthorizedPickupInput::class,
        ActivateFamilyAuthorizedPickupInput::class, DeactivateFamilyAuthorizedPickupInput::class,
        AssignAuthorizedPickupInput::class, EndAuthorizedPickupAssignmentInput::class,
    ];
}
