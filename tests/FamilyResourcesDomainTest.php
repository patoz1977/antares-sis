<?php

declare(strict_types=1);

namespace Tests;

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
use App\Family\Domain\ValueObject\DocumentTypeId;
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
use App\Family\Domain\ValueObject\Geolocation;
use App\Family\Domain\ValueObject\PickupIdentification;
use App\Family\Domain\ValueObject\RelationshipTypeId;
use App\Family\Domain\ValueObject\RepresentativeAddressAssignmentId;
use App\Family\Domain\ValueObject\RepresentativeId;
use App\Family\Domain\ValueObject\StudentAddressAssignmentId;
use App\Family\Domain\ValueObject\StudentId;
use DateTimeImmutable;
use DateTimeZone;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Tests\Support\TestRunner;

function registerFamilyResourcesDomainTests(TestRunner $runner): void
{
    $runner->add('Family Resource identities are separate positive immutable values', function (): void {
        $classes = [
            FamilyAddressId::class,
            RepresentativeAddressAssignmentId::class,
            StudentAddressAssignmentId::class,
            FamilyEmergencyContactId::class,
            EmergencyContactAssignmentId::class,
            FamilyAuthorizedPickupId::class,
            AuthorizedPickupAssignmentId::class,
            DocumentTypeId::class,
        ];

        foreach ($classes as $class) {
            $identity = new $class(7);
            assertSameValue(7, $identity->value());
            assertSameValue(true, $identity->equals(new $class(7)));
            assertSameValue(false, $identity->equals(new $class(8)));
            assertSameValue(true, (new ReflectionClass($class))->isReadOnly());
            assertThrows(static fn (): object => new $class(0), InvalidFamilyState::class);
            assertThrows(static fn (): object => new $class(-1), InvalidFamilyState::class);
        }
    });

    $runner->add('Address normalizes approved fields and represents absence without geography catalogs', function (): void {
        $address = new Address(
            '  Main Street  ',
            '  10-A  ',
            '  Secondary  ',
            '  North  ',
            '  Blue gate  ',
            new Geolocation('-0.1234567', '-78.5000000'),
        );

        assertSameValue('Main Street', $address->mainStreet());
        assertSameValue('10-A', $address->streetNumber());
        assertSameValue('Secondary', $address->secondaryStreet());
        assertSameValue('North', $address->sector());
        assertSameValue('Blue gate', $address->reference());
        assertSameValue('-0.1234567', $address->geolocation()?->latitude());
        assertSameValue(true, $address->equals(new Address(
            'Main Street',
            '10-A',
            'Secondary',
            'North',
            'Blue gate',
            new Geolocation('-0.1234567', '-78.5000000'),
        )));
        assertSameValue(true, (new ReflectionClass(Address::class))->isReadOnly());

        $properties = array_map(
            static fn (\ReflectionProperty $property): string => strtolower($property->getName()),
            (new ReflectionClass(Address::class))->getProperties(),
        );
        foreach (['province', 'canton', 'parish'] as $forbidden) {
            assertSameValue(false, in_array($forbidden, $properties, true));
        }
    });

    $runner->add('Address accepts exact schema limits and normalizes empty optionals to null', function (): void {
        $address = new Address(
            str_repeat('m', 200),
            str_repeat('n', 50),
            str_repeat('s', 200),
            str_repeat('x', 150),
            str_repeat('r', 255),
            null,
        );
        assertSameValue(200, mb_strlen($address->mainStreet(), 'UTF-8'));
        assertSameValue(null, (new Address('Main', '', ' ', null, "\t", null))->streetNumber());
        assertSameValue(null, (new Address('Main', '', ' ', null, "\t", null))->secondaryStreet());
        assertSameValue(null, (new Address('Main', '', ' ', null, "\t", null))->sector());
        assertSameValue(null, (new Address('Main', '', ' ', null, "\t", null))->reference());
        assertSameValue(null, (new Address('Main', null, null, null, null, null))->geolocation());
    });

    $runner->add('Address rejects missing or overlong physical values', function (): void {
        assertThrows(
            static fn (): Address => new Address(' ', null, null, null, null, null),
            InvalidFamilyState::class,
        );
        foreach ([
            [str_repeat('m', 201), null, null, null, null],
            ['Main', str_repeat('n', 51), null, null, null],
            ['Main', null, str_repeat('s', 201), null, null],
            ['Main', null, null, str_repeat('x', 151), null],
            ['Main', null, null, null, str_repeat('r', 256)],
        ] as [$main, $number, $secondary, $sector, $reference]) {
            assertThrows(
                static fn (): Address => new Address($main, $number, $secondary, $sector, $reference, null),
                InvalidFamilyState::class,
            );
        }
    });

    $runner->add('AddressLabel is free trimmed text with the approved maximum', function (): void {
        assertSameValue('Home base', (new AddressLabel('  Home base  '))->value());
        assertSameValue(100, mb_strlen((new AddressLabel(str_repeat('x', 100)))->value(), 'UTF-8'));
        assertSameValue(true, (new AddressLabel('A'))->equals(new AddressLabel('A')));
        assertThrows(static fn (): AddressLabel => new AddressLabel(' '), InvalidFamilyState::class);
        assertThrows(
            static fn (): AddressLabel => new AddressLabel(str_repeat('x', 101)),
            InvalidFamilyState::class,
        );
    });

    $runner->add('Geolocation preserves DECIMAL 10 7 precision and accepts geographic bounds', function (): void {
        $precise = new Geolocation('-12.3456789', '123.4567890');
        assertSameValue('-12.3456789', $precise->latitude());
        assertSameValue('123.4567890', $precise->longitude());
        assertSameValue('-90.0000000', (new Geolocation('-90', '-180'))->latitude());
        assertSameValue('90.0000000', (new Geolocation('90', '180'))->latitude());
        assertSameValue('-180.0000000', (new Geolocation('-90', '-180'))->longitude());
        assertSameValue('180.0000000', (new Geolocation('90', '180'))->longitude());
        assertSameValue(true, (new ReflectionClass(Geolocation::class))->isReadOnly());

        foreach ([['90.0000001', '0'], ['-90.0000001', '0'], ['0', '180.0000001'], ['0', '-180.0000001']] as [$lat, $lon]) {
            assertThrows(static fn (): Geolocation => new Geolocation($lat, $lon), InvalidFamilyState::class);
        }
        assertThrows(static fn (): Geolocation => new Geolocation('1.12345678', '0'), InvalidFamilyState::class);
    });

    $runner->add('Family creation preserves membership semantics and starts with zero resources', function (): void {
        $family = familyResourcesNewFamily();
        assertSameValue(1, count($family->activeRepresentatives()));
        assertSameValue(true, $family->primaryRepresentative()->isPrimary());
        assertSameValue([], $family->students());
        assertSameValue([], $family->addresses());
        assertSameValue([], $family->representativeAddressAssignments());
        assertSameValue([], $family->studentAddressAssignments());
        assertSameValue([], $family->emergencyContacts());
        assertSameValue([], $family->emergencyContactAssignments());
        assertSameValue([], $family->authorizedPickups());
        assertSameValue([], $family->authorizedPickupAssignments());
    });

    $runner->add('FamilyAddress supports nullable and persisted identity update and lifecycle', function (): void {
        $created = new FamilyAddress(
            null,
            new AddressLabel('Home'),
            familyResourcesAddress('First'),
            FamilyResourceStatus::Active,
        );
        assertSameValue(null, $created->id());
        assertSameValue(true, $created->isActive());

        $persisted = familyResourcesAddressEntity(11);
        $persisted->update(new AddressLabel('Updated'), familyResourcesAddress('Second'));
        assertSameValue(11, $persisted->id()?->value());
        assertSameValue('Updated', $persisted->label()->value());
        assertSameValue('Second', $persisted->address()->mainStreet());
        $persisted->deactivate();
        assertSameValue(FamilyResourceStatus::Inactive, $persisted->status());
        $persisted->activate();
        assertSameValue(true, $persisted->isActive());
    });

    $runner->add('Family owns multiple addresses and protects its resource collection', function (): void {
        $family = familyResourcesNewFamily();
        $returned = $family->addAddress(new AddressLabel('One'), familyResourcesAddress('One'));
        $family->addAddress(
            new AddressLabel('Two'),
            familyResourcesAddress('Two'),
            FamilyResourceStatus::Inactive,
        );
        $addresses = $family->addresses();
        array_pop($addresses);
        $returned->deactivate();

        assertSameValue(2, count($family->addresses()));
        assertSameValue(1, count($family->activeAddresses()));
        assertSameValue(true, $family->addresses()[0]->isActive());
    });

    $runner->add('Representative address assignment replaces atomically and retains history', function (): void {
        $family = familyResourcesAggregate();
        $first = $family->assignAddressToRepresentative(
            new RepresentativeId(101),
            new FamilyAddressId(11),
            familyResourcesTime('2026-08-02 10:00:00.123456'),
        );
        assertSameValue(null, $first->id());
        assertSameValue('2026-08-02 10:00:00.000000', $first->startedAt()->format('Y-m-d H:i:s.u'));

        $family->assignAddressToRepresentative(
            new RepresentativeId(101),
            new FamilyAddressId(12),
            familyResourcesTime('2026-08-03 11:12:13.999999'),
        );
        [$historical, $active] = $family->representativeAddressAssignments();
        assertSameValue('2026-08-03 11:12:13', $historical->endedAt()?->format('Y-m-d H:i:s'));
        assertSameValue(false, $historical->isActive());
        assertSameValue(true, $active->isActive());
        assertSameValue(12, $active->familyAddressId()->value());
    });

    $runner->add('Representative address assignment rejects same active resource unknown member and unknown resource', function (): void {
        $family = familyResourcesAggregate();
        $family->assignAddressToRepresentative(new RepresentativeId(101), new FamilyAddressId(11), familyResourcesTime());

        assertThrows(
            static fn () => $family->assignAddressToRepresentative(
                new RepresentativeId(101),
                new FamilyAddressId(11),
                familyResourcesTime('2026-08-03'),
            ),
            InvalidFamilyState::class,
        );
        assertThrows(
            static fn () => $family->assignAddressToRepresentative(
                new RepresentativeId(999),
                new FamilyAddressId(11),
                familyResourcesTime(),
            ),
            InvalidFamilyState::class,
        );
        assertThrows(
            static fn () => $family->assignAddressToRepresentative(
                new RepresentativeId(101),
                new FamilyAddressId(999),
                familyResourcesTime(),
            ),
            InvalidFamilyState::class,
        );
        assertSameValue(1, count($family->representativeAddressAssignments()));
    });

    $runner->add('Address resource may be shared by Representative and active Students', function (): void {
        $family = familyResourcesAggregate();
        $family->assignAddressToRepresentative(new RepresentativeId(101), new FamilyAddressId(11), familyResourcesTime());
        $family->assignAddressToStudent(new StudentId(301), new FamilyAddressId(11), familyResourcesTime());
        $family->assignAddressToStudent(new StudentId(302), new FamilyAddressId(11), familyResourcesTime());

        assertSameValue(1, count($family->representativeAddressAssignments()));
        assertSameValue(2, count($family->studentAddressAssignments()));
    });

    $runner->add('Student address assignment requires active membership and replaces one active address', function (): void {
        $family = familyResourcesAggregate();
        $family->assignAddressToStudent(new StudentId(301), new FamilyAddressId(11), familyResourcesTime());
        $family->assignAddressToStudent(
            new StudentId(301),
            new FamilyAddressId(12),
            familyResourcesTime('2026-08-03 10:00:00'),
        );
        assertSameValue(false, $family->studentAddressAssignments()[0]->isActive());
        assertSameValue(true, $family->studentAddressAssignments()[1]->isActive());

        foreach ([303, 999] as $studentId) {
            assertThrows(
                static fn () => $family->assignAddressToStudent(
                    new StudentId($studentId),
                    new FamilyAddressId(11),
                    familyResourcesTime(),
                ),
                InvalidFamilyState::class,
            );
        }
    });

    $runner->add('Assigned resources and member relations require explicit ending before deactivation', function (): void {
        $family = familyResourcesAggregate();
        $family->assignAddressToStudent(new StudentId(301), new FamilyAddressId(11), familyResourcesTime());
        assertThrows(
            static fn () => $family->deactivateAddress(new FamilyAddressId(11)),
            InvalidFamilyState::class,
        );
        assertThrows(
            static fn () => $family->endStudentMembership(new StudentId(301), familyResourcesTime('2026-08-04')),
            InvalidFamilyState::class,
        );

        $family->endStudentAddressAssignment(new StudentId(301), familyResourcesTime('2026-08-03'));
        $family->deactivateAddress(new FamilyAddressId(11));
        $family->endStudentMembership(new StudentId(301), familyResourcesTime('2026-08-04'));
        assertSameValue(false, $family->addresses()[0]->isActive());
        assertSameValue(1, count($family->activeStudents()));
    });

    $runner->add('Emergency contact values follow exact schema normalization and validation', function (): void {
        $names = new FamilyResourceName('  MarÃ­a Contacto  ');
        $information = new EmergencyContactInformation(
            '  +593 99 ABC  ',
            '  02 123  ',
            '  maria@example.test  ',
            '  Call first  ',
        );
        assertSameValue('MarÃ­a Contacto', $names->value());
        assertSameValue('+593 99 ABC', $information->mobilePhone());
        assertSameValue('02 123', $information->phone());
        assertSameValue('maria@example.test', $information->email());
        assertSameValue('Call first', $information->observations());
        assertSameValue(null, (new EmergencyContactInformation('mobile', '', ' ', null))->phone());
        assertSameValue(null, (new EmergencyContactInformation('mobile', '', ' ', null))->email());
        assertThrows(
            static fn (): EmergencyContactInformation => new EmergencyContactInformation(' ', null, null, null),
            InvalidFamilyState::class,
        );
        assertThrows(
            static fn (): EmergencyContactInformation => new EmergencyContactInformation('mobile', null, 'bad', null),
            InvalidFamilyState::class,
        );
        assertThrows(
            static fn (): EmergencyContactInformation => new EmergencyContactInformation(str_repeat('x', 31), null, null, null),
            InvalidFamilyState::class,
        );
    });

    $runner->add('FamilyEmergencyContact preserves identity across approved update and lifecycle', function (): void {
        $contact = familyResourcesContactEntity(21);
        $contact->update(
            new FamilyResourceName('Updated'),
            new RelationshipTypeId(202),
            new EmergencyContactInformation('new mobile', null, null, null),
        );
        assertSameValue(21, $contact->id()?->value());
        assertSameValue('Updated', $contact->names()->value());
        assertSameValue(202, $contact->relationshipTypeId()->value());
        $contact->deactivate();
        assertSameValue(false, $contact->isActive());
        $contact->activate();
        assertSameValue(true, $contact->isActive());
        assertSameValue(false, property_exists($contact, 'personId'));
    });

    $runner->add('Emergency contact assignments support reuse optional priority and active uniqueness', function (): void {
        $family = familyResourcesAggregate();
        $first = $family->assignEmergencyContactToStudent(
            new StudentId(301),
            new FamilyEmergencyContactId(21),
            new EmergencyContactPriority(1),
            familyResourcesTime(),
        );
        $family->assignEmergencyContactToStudent(
            new StudentId(302),
            new FamilyEmergencyContactId(21),
            null,
            familyResourcesTime(),
        );
        $family->assignEmergencyContactToStudent(
            new StudentId(301),
            new FamilyEmergencyContactId(22),
            null,
            familyResourcesTime(),
        );
        assertSameValue(1, $first->priority()?->value());
        assertSameValue(3, count($family->emergencyContactAssignments()));

        assertThrows(
            static fn () => $family->assignEmergencyContactToStudent(
                new StudentId(301),
                new FamilyEmergencyContactId(21),
                null,
                familyResourcesTime('2026-08-03'),
            ),
            InvalidFamilyState::class,
        );
        assertThrows(
            static fn () => $family->assignEmergencyContactToStudent(
                new StudentId(301),
                new FamilyEmergencyContactId(22),
                new EmergencyContactPriority(1),
                familyResourcesTime('2026-08-03'),
            ),
            InvalidFamilyState::class,
        );
    });

    $runner->add('Emergency contact priority is positive without arbitrary upper bound or renumbering', function (): void {
        assertSameValue(999, (new EmergencyContactPriority(999))->value());
        assertThrows(static fn (): EmergencyContactPriority => new EmergencyContactPriority(0), InvalidFamilyState::class);
        assertThrows(static fn (): EmergencyContactPriority => new EmergencyContactPriority(-1), InvalidFamilyState::class);
    });

    $runner->add('Emergency contact assignment history may reuse contact and priority after explicit ending', function (): void {
        $family = familyResourcesAggregate();
        $family->assignEmergencyContactToStudent(
            new StudentId(301),
            new FamilyEmergencyContactId(21),
            new EmergencyContactPriority(5),
            familyResourcesTime(),
        );
        $family->endEmergencyContactAssignment(
            new StudentId(301),
            new FamilyEmergencyContactId(21),
            familyResourcesTime('2026-08-03'),
        );
        $family->assignEmergencyContactToStudent(
            new StudentId(301),
            new FamilyEmergencyContactId(21),
            new EmergencyContactPriority(5),
            familyResourcesTime('2026-08-04'),
        );
        assertSameValue(2, count($family->emergencyContactAssignments()));
        assertSameValue(false, $family->emergencyContactAssignments()[0]->isActive());
        assertSameValue(true, $family->emergencyContactAssignments()[1]->isActive());
        assertThrows(
            static fn () => $family->emergencyContactAssignments()[0]->end(familyResourcesTime('2026-08-05')),
            InvalidFamilyState::class,
        );
    });

    $runner->add('Emergency assignment requires active Student and known active Family contact', function (): void {
        $family = familyResourcesAggregate();
        foreach ([[999, 21], [303, 21], [301, 999]] as [$studentId, $contactId]) {
            assertThrows(
                static fn () => $family->assignEmergencyContactToStudent(
                    new StudentId($studentId),
                    new FamilyEmergencyContactId($contactId),
                    null,
                    familyResourcesTime(),
                ),
                InvalidFamilyState::class,
            );
        }
    });

    $runner->add('Authorized pickup values support optional contact fields and identification pair', function (): void {
        $information = new AuthorizedPickupInformation('  mobile ABC  ', ' ', '  note  ');
        assertSameValue('mobile ABC', $information->mobilePhone());
        assertSameValue(null, $information->phone());
        assertSameValue('note', $information->observations());
        assertSameValue(null, PickupIdentification::fromPair(null, null));
        $identification = PickupIdentification::fromPair(new DocumentTypeId(4), '  ID-99  ');
        assertSameValue(4, $identification?->documentTypeId()->value());
        assertSameValue('ID-99', $identification?->documentNumber());
        assertThrows(
            static fn (): ?PickupIdentification => PickupIdentification::fromPair(new DocumentTypeId(4), null),
            InvalidFamilyState::class,
        );
        assertThrows(
            static fn (): ?PickupIdentification => PickupIdentification::fromPair(null, 'ID-99'),
            InvalidFamilyState::class,
        );
        assertThrows(
            static fn (): AuthorizedPickupInformation => new AuthorizedPickupInformation('', null, null),
            InvalidFamilyState::class,
        );
    });

    $runner->add('FamilyAuthorizedPickup preserves identity and does not become Person', function (): void {
        $pickup = familyResourcesPickupEntity(31);
        $pickup->update(
            new FamilyResourceName('Updated pickup'),
            new RelationshipTypeId(203),
            new AuthorizedPickupInformation('new phone', null, null),
            null,
        );
        assertSameValue(31, $pickup->id()?->value());
        assertSameValue('Updated pickup', $pickup->names()->value());
        assertSameValue(null, $pickup->identification());
        $pickup->deactivate();
        assertSameValue(false, $pickup->isActive());
        $pickup->activate();
        assertSameValue(true, $pickup->isActive());
        assertSameValue(false, property_exists($pickup, 'personId'));
    });

    $runner->add('Authorized pickup assignments support Student reuse uniqueness and history', function (): void {
        $family = familyResourcesAggregate();
        $family->assignAuthorizedPickupToStudent(
            new StudentId(301),
            new FamilyAuthorizedPickupId(31),
            familyResourcesTime(),
        );
        $family->assignAuthorizedPickupToStudent(
            new StudentId(302),
            new FamilyAuthorizedPickupId(31),
            familyResourcesTime(),
        );
        assertThrows(
            static fn () => $family->assignAuthorizedPickupToStudent(
                new StudentId(301),
                new FamilyAuthorizedPickupId(31),
                familyResourcesTime('2026-08-03'),
            ),
            InvalidFamilyState::class,
        );
        $family->endAuthorizedPickupAssignment(
            new StudentId(301),
            new FamilyAuthorizedPickupId(31),
            familyResourcesTime('2026-08-03'),
        );
        $family->assignAuthorizedPickupToStudent(
            new StudentId(301),
            new FamilyAuthorizedPickupId(31),
            familyResourcesTime('2026-08-04'),
        );
        assertSameValue(3, count($family->authorizedPickupAssignments()));
    });

    $runner->add('Authorized pickup assignment requires active Student and known active Family pickup', function (): void {
        $family = familyResourcesAggregate();
        foreach ([[999, 31], [303, 31], [301, 999]] as [$studentId, $pickupId]) {
            assertThrows(
                static fn () => $family->assignAuthorizedPickupToStudent(
                    new StudentId($studentId),
                    new FamilyAuthorizedPickupId($pickupId),
                    familyResourcesTime(),
                ),
                InvalidFamilyState::class,
            );
        }
    });

    $runner->add('All historical assignments enforce second precision ordering and no reactivation', function (): void {
        $cases = [
            new RepresentativeAddressAssignment(
                new RepresentativeAddressAssignmentId(1),
                new FamilyAddressId(11),
                new RepresentativeId(101),
                familyResourcesTime('2026-08-02 10:00:00.999999'),
                null,
            ),
            new StudentAddressAssignment(
                new StudentAddressAssignmentId(2),
                new FamilyAddressId(11),
                new StudentId(301),
                familyResourcesTime('2026-08-02 10:00:00.999999'),
                null,
            ),
            new EmergencyContactAssignment(
                new EmergencyContactAssignmentId(3),
                new FamilyEmergencyContactId(21),
                new StudentId(301),
                null,
                familyResourcesTime('2026-08-02 10:00:00.999999'),
                null,
            ),
            new AuthorizedPickupAssignment(
                new AuthorizedPickupAssignmentId(4),
                new FamilyAuthorizedPickupId(31),
                new StudentId(301),
                familyResourcesTime('2026-08-02 10:00:00.999999'),
                null,
            ),
        ];

        foreach ($cases as $assignment) {
            assertSameValue('2026-08-02 10:00:00.000000', $assignment->startedAt()->format('Y-m-d H:i:s.u'));
            assertThrows(
                static fn () => $assignment->end(familyResourcesTime('2026-08-01')),
                InvalidFamilyState::class,
            );
            assertSameValue(true, $assignment->isActive());
            $assignment->end(familyResourcesTime('2026-08-03 10:00:00.123456'));
            assertSameValue('2026-08-03 10:00:00.000000', $assignment->endedAt()?->format('Y-m-d H:i:s.u'));
            assertThrows(
                static fn () => $assignment->end(familyResourcesTime('2026-08-04')),
                InvalidFamilyState::class,
            );
            assertSameValue(false, method_exists($assignment, 'activate'));
            assertSameValue(false, method_exists($assignment, 'reactivate'));
        }
    });

    $runner->add('Family reconstitutes all nine internal collections and protects them from mutation', function (): void {
        $representativeAssignment = familyResourcesRepresentativeAddressAssignment(11, 101);
        $studentAssignment = familyResourcesStudentAddressAssignment(11, 301);
        $contactAssignment = familyResourcesEmergencyAssignment(21, 301, 1);
        $pickupAssignment = familyResourcesPickupAssignment(31, 301);
        $family = familyResourcesReconstitute(
            [$representativeAssignment],
            [$studentAssignment],
            [$contactAssignment],
            [$pickupAssignment],
        );

        assertSameValue(2, count($family->addresses()));
        assertSameValue(1, count($family->representativeAddressAssignments()));
        assertSameValue(1, count($family->studentAddressAssignments()));
        assertSameValue(2, count($family->emergencyContacts()));
        assertSameValue(1, count($family->emergencyContactAssignments()));
        assertSameValue(2, count($family->authorizedPickups()));
        assertSameValue(1, count($family->authorizedPickupAssignments()));

        $representativeAssignment->end(familyResourcesTime('2026-08-03'));
        $family->studentAddressAssignments()[0]->end(familyResourcesTime('2026-08-03'));
        $family->emergencyContacts()[0]->deactivate();
        assertSameValue(true, $family->representativeAddressAssignments()[0]->isActive());
        assertSameValue(true, $family->studentAddressAssignments()[0]->isActive());
        assertSameValue(true, $family->emergencyContacts()[0]->isActive());
    });

    $runner->add('Family reconstruction rejects wrong Entity types and unknown resource or member references', function (): void {
        assertThrows(
            static fn (): Family => familyResourcesReconstitute([], [], [], [], ['wrong']),
            InvalidFamilyState::class,
        );
        assertThrows(
            static fn (): Family => familyResourcesReconstitute(
                [familyResourcesRepresentativeAddressAssignment(999, 101)],
            ),
            InvalidFamilyState::class,
        );
        assertThrows(
            static fn (): Family => familyResourcesReconstitute(
                [],
                [familyResourcesStudentAddressAssignment(11, 999)],
            ),
            InvalidFamilyState::class,
        );
    });

    $runner->add('Family reconstruction rejects duplicate active address assignments but permits history', function (): void {
        assertThrows(
            static fn (): Family => familyResourcesReconstitute([
                familyResourcesRepresentativeAddressAssignment(11, 101),
                familyResourcesRepresentativeAddressAssignment(12, 101, '2026-08-03'),
            ]),
            InvalidFamilyState::class,
        );
        assertThrows(
            static fn (): Family => familyResourcesReconstitute([], [
                familyResourcesStudentAddressAssignment(11, 301),
                familyResourcesStudentAddressAssignment(12, 301, '2026-08-03'),
            ]),
            InvalidFamilyState::class,
        );

        $valid = familyResourcesReconstitute(
            [familyResourcesRepresentativeAddressAssignment(11, 101, '2026-08-01', '2026-08-02'), familyResourcesRepresentativeAddressAssignment(12, 101, '2026-08-03', id: 82)],
            [familyResourcesStudentAddressAssignment(11, 301, '2026-08-01', '2026-08-02'), familyResourcesStudentAddressAssignment(12, 301, '2026-08-03', id: 83)],
        );
        assertSameValue(2, count($valid->representativeAddressAssignments()));
        assertSameValue(2, count($valid->studentAddressAssignments()));
    });

    $runner->add('Family reconstruction rejects duplicate active emergency contact and priority', function (): void {
        assertThrows(
            static fn (): Family => familyResourcesReconstitute([], [], [
                familyResourcesEmergencyAssignment(21, 301, null),
                familyResourcesEmergencyAssignment(21, 301, 2, '2026-08-03'),
            ]),
            InvalidFamilyState::class,
        );
        assertThrows(
            static fn (): Family => familyResourcesReconstitute([], [], [
                familyResourcesEmergencyAssignment(21, 301, 2),
                familyResourcesEmergencyAssignment(22, 301, 2, '2026-08-03'),
            ]),
            InvalidFamilyState::class,
        );

        $history = familyResourcesReconstitute([], [], [
            familyResourcesEmergencyAssignment(21, 301, 2, '2026-08-01', '2026-08-02'),
            familyResourcesEmergencyAssignment(21, 301, 2, '2026-08-03', id: 84),
        ]);
        assertSameValue(2, count($history->emergencyContactAssignments()));
    });

    $runner->add('Family reconstruction rejects duplicate active pickup assignment and duplicate persisted identities', function (): void {
        assertThrows(
            static fn (): Family => familyResourcesReconstitute([], [], [], [
                familyResourcesPickupAssignment(31, 301),
                familyResourcesPickupAssignment(31, 301, '2026-08-03'),
            ]),
            InvalidFamilyState::class,
        );

        $duplicateAddress = familyResourcesAddressEntity(11);
        assertThrows(
            static fn (): Family => familyResourcesReconstitute([], [], [], [], [
                familyResourcesAddressEntity(11),
                $duplicateAddress,
            ]),
            InvalidFamilyState::class,
        );
        assertThrows(
            static fn (): Family => familyResourcesReconstitute([
                familyResourcesRepresentativeAddressAssignment(11, 101, id: 91),
                familyResourcesRepresentativeAddressAssignment(12, 102, '2026-08-03', id: 91),
            ]),
            InvalidFamilyState::class,
        );
    });

    $runner->add('Family Resource Domain remains isolated from infrastructure other modules and excluded scope', function (): void {
        $directory = __DIR__ . '/../app/Family/Domain';
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
        $source = '';
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php' && $file->getFilename() !== 'FamilyRepository.php') {
                $source .= (string) file_get_contents($file->getPathname());
            }
        }

        foreach ([
            'App\\Person\\',
            'App\\Representative\\',
            'App\\Student\\',
            'Infrastructure',
            'PDO',
            'SQL',
            'Http',
            'Controller',
            'Session',
            'IdentityAccess',
            'Application',
            'Enrollment',
            'Submission',
            'Billing',
            'Medical',
            'Transport',
            'InstitutionalDocument',
            'DocumentAcceptance',
            'ProvinceId',
            'CantonId',
            'ParishId',
            'province_id',
            'canton_id',
            'parish_id',
        ] as $forbidden) {
            assertSameValue(false, str_contains($source, $forbidden));
        }
    });
}

function familyResourcesNewFamily(): Family
{
    return Family::create(
        new DisplayName('Resource Family'),
        FamilyStatus::Active,
        new RepresentativeId(101),
        new RelationshipTypeId(201),
        familyResourcesTime('2026-08-01 09:00:00.123456'),
    );
}

function familyResourcesAggregate(): Family
{
    return familyResourcesReconstitute();
}

/**
 * @param list<RepresentativeAddressAssignment> $representativeAssignments
 * @param list<StudentAddressAssignment> $studentAssignments
 * @param list<EmergencyContactAssignment> $emergencyAssignments
 * @param list<AuthorizedPickupAssignment> $pickupAssignments
 * @param list<FamilyAddress>|list<mixed>|null $addresses
 */
function familyResourcesReconstitute(
    array $representativeAssignments = [],
    array $studentAssignments = [],
    array $emergencyAssignments = [],
    array $pickupAssignments = [],
    ?array $addresses = null,
): Family {
    return Family::reconstitute(
        new FamilyId(500),
        new DisplayName('Persisted Resource Family'),
        FamilyStatus::Active,
        [
            new FamilyRepresentative(
                new FamilyRepresentativeId(1),
                new RepresentativeId(101),
                new RelationshipTypeId(201),
                true,
                familyResourcesTime('2026-01-01'),
                null,
            ),
            new FamilyRepresentative(
                new FamilyRepresentativeId(2),
                new RepresentativeId(102),
                new RelationshipTypeId(201),
                false,
                familyResourcesTime('2026-01-01'),
                null,
            ),
        ],
        [
            new FamilyStudent(new FamilyStudentId(3), new StudentId(301), familyResourcesTime('2026-01-01'), null),
            new FamilyStudent(new FamilyStudentId(4), new StudentId(302), familyResourcesTime('2026-01-01'), null),
            new FamilyStudent(
                new FamilyStudentId(5),
                new StudentId(303),
                familyResourcesTime('2025-01-01'),
                familyResourcesTime('2025-12-31'),
            ),
        ],
        $addresses ?? [familyResourcesAddressEntity(11), familyResourcesAddressEntity(12)],
        $representativeAssignments,
        $studentAssignments,
        [familyResourcesContactEntity(21), familyResourcesContactEntity(22)],
        $emergencyAssignments,
        [familyResourcesPickupEntity(31), familyResourcesPickupEntity(32)],
        $pickupAssignments,
    );
}

function familyResourcesAddress(string $mainStreet): Address
{
    return new Address($mainStreet, null, null, null, null, null);
}

function familyResourcesAddressEntity(int $id): FamilyAddress
{
    return new FamilyAddress(
        new FamilyAddressId($id),
        new AddressLabel('Address ' . $id),
        familyResourcesAddress('Street ' . $id),
        FamilyResourceStatus::Active,
    );
}

function familyResourcesContactEntity(int $id): FamilyEmergencyContact
{
    return new FamilyEmergencyContact(
        new FamilyEmergencyContactId($id),
        new FamilyResourceName('Contact ' . $id),
        new RelationshipTypeId(201),
        new EmergencyContactInformation('mobile ' . $id, null, null, null),
        FamilyResourceStatus::Active,
    );
}

function familyResourcesPickupEntity(int $id): FamilyAuthorizedPickup
{
    return new FamilyAuthorizedPickup(
        new FamilyAuthorizedPickupId($id),
        new FamilyResourceName('Pickup ' . $id),
        new RelationshipTypeId(201),
        new AuthorizedPickupInformation('mobile ' . $id, null, null),
        null,
        FamilyResourceStatus::Active,
    );
}

function familyResourcesRepresentativeAddressAssignment(
    int $addressId,
    int $representativeId,
    string $startedAt = '2026-08-02',
    ?string $endedAt = null,
    int $id = 81,
): RepresentativeAddressAssignment {
    return new RepresentativeAddressAssignment(
        new RepresentativeAddressAssignmentId($id),
        new FamilyAddressId($addressId),
        new RepresentativeId($representativeId),
        familyResourcesTime($startedAt),
        $endedAt === null ? null : familyResourcesTime($endedAt),
    );
}

function familyResourcesStudentAddressAssignment(
    int $addressId,
    int $studentId,
    string $startedAt = '2026-08-02',
    ?string $endedAt = null,
    int $id = 82,
): StudentAddressAssignment {
    return new StudentAddressAssignment(
        new StudentAddressAssignmentId($id),
        new FamilyAddressId($addressId),
        new StudentId($studentId),
        familyResourcesTime($startedAt),
        $endedAt === null ? null : familyResourcesTime($endedAt),
    );
}

function familyResourcesEmergencyAssignment(
    int $contactId,
    int $studentId,
    ?int $priority,
    string $startedAt = '2026-08-02',
    ?string $endedAt = null,
    int $id = 83,
): EmergencyContactAssignment {
    return new EmergencyContactAssignment(
        new EmergencyContactAssignmentId($id + $contactId),
        new FamilyEmergencyContactId($contactId),
        new StudentId($studentId),
        $priority === null ? null : new EmergencyContactPriority($priority),
        familyResourcesTime($startedAt),
        $endedAt === null ? null : familyResourcesTime($endedAt),
    );
}

function familyResourcesPickupAssignment(
    int $pickupId,
    int $studentId,
    string $startedAt = '2026-08-02',
    ?string $endedAt = null,
    int $id = 84,
): AuthorizedPickupAssignment {
    return new AuthorizedPickupAssignment(
        new AuthorizedPickupAssignmentId($id + $pickupId),
        new FamilyAuthorizedPickupId($pickupId),
        new StudentId($studentId),
        familyResourcesTime($startedAt),
        $endedAt === null ? null : familyResourcesTime($endedAt),
    );
}

function familyResourcesTime(string $value = '2026-08-02 10:00:00'): DateTimeImmutable
{
    return new DateTimeImmutable($value, new DateTimeZone('UTC'));
}
