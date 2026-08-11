<?php

declare(strict_types=1);

namespace Tests;

use App\Family\Domain\Family;
use App\Family\Domain\FamilyAddress;
use App\Family\Domain\FamilyAuthorizedPickup;
use App\Family\Domain\FamilyEmergencyContact;
use App\Family\Domain\FamilyResourceStatus;
use App\Family\Domain\EmergencyContactAssignment;
use App\Family\Domain\RepresentativeAddressAssignment;
use App\Family\Domain\ValueObject\Address;
use App\Family\Domain\ValueObject\AddressLabel;
use App\Family\Domain\ValueObject\AuthorizedPickupInformation;
use App\Family\Domain\ValueObject\DisplayName;
use App\Family\Domain\ValueObject\DocumentTypeId;
use App\Family\Domain\ValueObject\EmergencyContactInformation;
use App\Family\Domain\ValueObject\EmergencyContactPriority;
use App\Family\Domain\ValueObject\FamilyAddressId;
use App\Family\Domain\ValueObject\FamilyAuthorizedPickupId;
use App\Family\Domain\ValueObject\FamilyEmergencyContactId;
use App\Family\Domain\ValueObject\FamilyResourceName;
use App\Family\Domain\ValueObject\Geolocation;
use App\Family\Domain\ValueObject\PickupIdentification;
use App\Family\Domain\ValueObject\RelationshipTypeId;
use App\Family\Domain\ValueObject\RepresentativeId;
use App\Family\Domain\ValueObject\StudentId;
use App\Family\Infrastructure\Persistence\PdoFamilyRepository;
use PDO;
use PDOException;
use RuntimeException;
use Tests\Support\TestRunner;

function registerFamilyResourcesPersistenceTests(TestRunner $runner): void
{
    $runner->add('pdo Family insert atomically persists new owned resources with database identities', function (): void {
        $pdo = sqliteFamilyDatabase();
        $repository = familyPersistenceRepositoryWithPdo($pdo);
        $family = newFamilyPersistenceFixture(1, displayName: 'Atomic Resource Family');
        $family->addAddress(new AddressLabel('Casa'), simplePersistedAddress('Main street'));
        $family->addEmergencyContact(new FamilyResourceName('Emergency'), new RelationshipTypeId(1),
            new EmergencyContactInformation('mobile', null, null, null));
        $family->addAuthorizedPickup(new FamilyResourceName('Pickup'), new RelationshipTypeId(1),
            new AuthorizedPickupInformation('mobile', null, null), null);

        $persisted = $repository->save($family);
        assertSameValue(null, $family->id());
        assertSameValue(true, requiredFamilyPersistenceId($persisted)->value() > 0);
        assertSameValue([1], familyResourceIds($persisted->addresses()));
        assertSameValue([1], familyResourceIds($persisted->emergencyContacts()));
        assertSameValue([1], familyResourceIds($persisted->authorizedPickups()));
        assertSameValue(false, $pdo->inTransaction());
    });

    $runner->add('pdo Family repository reconstructs the complete Resources aggregate deterministically', function (): void {
        [$pdo, $repository, $family] = persistedFamilyResourcesFixture();

        assertSameValue([1], familyResourceIds($family->addresses()));
        assertSameValue([1], familyResourceIds($family->emergencyContacts()));
        assertSameValue([1], familyResourceIds($family->authorizedPickups()));
        assertSameValue([1], familyResourceIds($family->representativeAddressAssignments()));
        assertSameValue([1], familyResourceIds($family->studentAddressAssignments()));
        assertSameValue([1], familyResourceIds($family->emergencyContactAssignments()));
        assertSameValue([1], familyResourceIds($family->authorizedPickupAssignments()));
        assertSameValue('Av. O\'Brien ñ', $family->addresses()[0]->address()->mainStreet());
        assertSameValue('-0.1234567', $family->addresses()[0]->address()->geolocation()?->latitude());
        assertSameValue('179.9999999', $family->addresses()[0]->address()->geolocation()?->longitude());
        assertSameValue('2026-08-11 15:01:02', $family->studentAddressAssignments()[0]->startedAt()->format('Y-m-d H:i:s'));
        assertSameValue(1, $family->emergencyContactAssignments()[0]->priority()?->value());
        assertSameValue(1, (int) $pdo->query('SELECT COUNT(*) FROM family_addresses')->fetchColumn());
        assertSameValue($family->id()?->value(), $repository->findById(requiredFamilyPersistenceId($family))?->id()?->value());
    });

    $runner->add('pdo Family repository updates resource fields statuses and assignment endings exactly', function (): void {
        [, $repository, $family] = persistedFamilyResourcesFixture();
        $addressId = requiredFamilyAddressId($family);
        $contactId = requiredFamilyEmergencyContactId($family);
        $pickupId = requiredFamilyAuthorizedPickupId($family);

        $family->endRepresentativeAddressAssignment(new RepresentativeId(1), familyPersistenceTime('2026-08-12 01:02:03'));
        $family->endStudentAddressAssignment(new StudentId(1), familyPersistenceTime('2026-08-12 01:02:04'));
        $family->endEmergencyContactAssignment(new StudentId(1), $contactId, familyPersistenceTime('2026-08-12 01:02:05'));
        $family->endAuthorizedPickupAssignment(new StudentId(1), $pickupId, familyPersistenceTime('2026-08-12 01:02:06'));
        $family->updateAddress($addressId, new AddressLabel('Casa actualizada'), new Address(
            'Calle José y María', null, null, 'Norte', "Referencia d'Artagnan", null,
        ));
        $family->updateEmergencyContact($contactId, new FamilyResourceName('Zoë O\'Connor'), new RelationshipTypeId(2),
            new EmergencyContactInformation('móvil actualizado', null, 'zoe@example.test', 'Observación exacta'));
        $family->updateAuthorizedPickup($pickupId, new FamilyResourceName('Ángel D\'Amico'), new RelationshipTypeId(2),
            new AuthorizedPickupInformation('pickup móvil', null, 'Actualizado'), null);
        $family->deactivateAddress($addressId);
        $family->deactivateEmergencyContact($contactId);
        $family->deactivateAuthorizedPickup($pickupId);

        $updated = $repository->save($family);
        assertSameValue('Casa actualizada', $updated->addresses()[0]->label()->value());
        assertSameValue(null, $updated->addresses()[0]->address()->geolocation());
        assertSameValue(FamilyResourceStatus::Inactive, $updated->addresses()[0]->status());
        assertSameValue('Zoë O\'Connor', $updated->emergencyContacts()[0]->names()->value());
        assertSameValue(FamilyResourceStatus::Inactive, $updated->emergencyContacts()[0]->status());
        assertSameValue(null, $updated->authorizedPickups()[0]->identification());
        assertSameValue(FamilyResourceStatus::Inactive, $updated->authorizedPickups()[0]->status());
        assertSameValue(false, $updated->representativeAddressAssignments()[0]->isActive());
        assertSameValue(false, $updated->studentAddressAssignments()[0]->isActive());
        assertSameValue(false, $updated->emergencyContactAssignments()[0]->isActive());
        assertSameValue(false, $updated->authorizedPickupAssignments()[0]->isActive());
        assertSameValue($updated->id()?->value(), $repository->save($updated)->id()?->value());
    });

    $runner->add('pdo Family resource inserts use database identities without sequence assumptions', function (): void {
        $pdo = sqliteFamilyDatabase();
        $repository = familyPersistenceRepositoryWithPdo($pdo);
        $family = $repository->save(newFamilyPersistenceFixture(1));
        $pdo->exec("INSERT INTO family_addresses (id, family_id, label, main_street, status_id) VALUES (50, {$family->id()?->value()}, 'gap', 'gap', 1)");
        $pdo->exec('DELETE FROM family_addresses WHERE id = 50');
        $family->addAddress(new AddressLabel('Uno'), simplePersistedAddress('Uno'));
        $family->addAddress(new AddressLabel('Dos'), simplePersistedAddress('Dos'));

        $persisted = $repository->save($family);
        $ids = familyResourceIds($persisted->addresses());
        assertSameValue(2, count($ids));
        assertSameValue(true, $ids[0] > 0 && $ids[1] > 0 && $ids[0] !== $ids[1]);
        assertSameValue(false, in_array(0, $ids, true));
    });

    $runner->add('pdo Family synchronization rejects omitted unknown and cross-Family resources', function (): void {
        [$pdo, $repository, $family] = persistedFamilyResourcesFixture(false);
        $omitted = Family::reconstitute(
            requiredFamilyPersistenceId($family), $family->displayName(), $family->status(),
            $family->representatives(), $family->students(), [], [], [],
            $family->emergencyContacts(), [], $family->authorizedPickups(), [],
        );
        assertThrows(static fn (): Family => $repository->save($omitted), RuntimeException::class);

        $unknownAddress = new FamilyAddress(new FamilyAddressId(99999), new AddressLabel('Unknown'),
            simplePersistedAddress('Unknown'), FamilyResourceStatus::Active);
        $unknown = Family::reconstitute(
            requiredFamilyPersistenceId($family), $family->displayName(), $family->status(),
            $family->representatives(), $family->students(), [$unknownAddress], [], [],
            $family->emergencyContacts(), [], $family->authorizedPickups(), [],
        );
        assertThrows(static fn (): Family => $repository->save($unknown), RuntimeException::class);

        $other = $repository->save(newFamilyPersistenceFixture(2, displayName: 'Other Family'));
        $other->addAddress(new AddressLabel('Other'), simplePersistedAddress('Other'));
        $other = $repository->save($other);
        $foreign = Family::reconstitute(
            requiredFamilyPersistenceId($family), $family->displayName(), $family->status(),
            $family->representatives(), $family->students(), [$other->addresses()[0]], [], [],
            $family->emergencyContacts(), [], $family->authorizedPickups(), [],
        );
        assertThrows(static fn (): Family => $repository->save($foreign), RuntimeException::class);
        assertSameValue(2, (int) $pdo->query('SELECT COUNT(*) FROM family_addresses')->fetchColumn());
    });

    $runner->add('pdo Family synchronization rejects omitted and changed persisted assignments', function (): void {
        [, $repository, $family] = persistedFamilyResourcesFixture();
        $omitted = Family::reconstitute(
            requiredFamilyPersistenceId($family), $family->displayName(), $family->status(),
            $family->representatives(), $family->students(), $family->addresses(), [],
            $family->studentAddressAssignments(), $family->emergencyContacts(),
            $family->emergencyContactAssignments(), $family->authorizedPickups(),
            $family->authorizedPickupAssignments(),
        );
        assertThrows(static fn (): Family => $repository->save($omitted), RuntimeException::class);

        $representativeAssignment = $family->representativeAddressAssignments()[0];
        $changedRepresentative = new RepresentativeAddressAssignment(
            $representativeAssignment->id(),
            $representativeAssignment->familyAddressId(),
            $representativeAssignment->representativeId(),
            familyPersistenceTime('2026-08-11 16:00:01'),
            null,
        );
        $changed = Family::reconstitute(
            requiredFamilyPersistenceId($family), $family->displayName(), $family->status(),
            $family->representatives(), $family->students(), $family->addresses(), [$changedRepresentative],
            $family->studentAddressAssignments(), $family->emergencyContacts(),
            $family->emergencyContactAssignments(), $family->authorizedPickups(),
            $family->authorizedPickupAssignments(),
        );
        assertThrows(static fn (): Family => $repository->save($changed), RuntimeException::class);

        $emergencyAssignment = $family->emergencyContactAssignments()[0];
        $changedPriority = new EmergencyContactAssignment(
            $emergencyAssignment->id(),
            $emergencyAssignment->familyEmergencyContactId(),
            $emergencyAssignment->studentId(),
            new EmergencyContactPriority(2),
            $emergencyAssignment->startedAt(),
            null,
        );
        $changed = Family::reconstitute(
            requiredFamilyPersistenceId($family), $family->displayName(), $family->status(),
            $family->representatives(), $family->students(), $family->addresses(),
            $family->representativeAddressAssignments(), $family->studentAddressAssignments(),
            $family->emergencyContacts(), [$changedPriority], $family->authorizedPickups(),
            $family->authorizedPickupAssignments(),
        );
        assertThrows(static fn (): Family => $repository->save($changed), RuntimeException::class);
    });

    $runner->add('pdo Family resource persistence rejects disappearing rows and corrupted statuses', function (): void {
        [$pdo, $repository, $family] = persistedFamilyResourcesFixture(false);
        $addressId = requiredFamilyAddressId($family);
        $pdo->exec('DELETE FROM family_addresses WHERE id = ' . $addressId->value());
        assertThrows(static fn (): Family => $repository->save($family), RuntimeException::class);

        [$pdo2, $repository2, $family2] = persistedFamilyResourcesFixture(false);
        $pdo2->exec('UPDATE family_addresses SET status_id = 3 WHERE id = ' . requiredFamilyAddressId($family2)->value());
        assertThrows(
            static fn (): ?Family => $repository2->findById(requiredFamilyPersistenceId($family2)),
            RuntimeException::class,
        );
        $pdo2->exec('UPDATE family_addresses SET status_id = 4 WHERE id = ' . requiredFamilyAddressId($family2)->value());
        assertThrows(
            static fn (): ?Family => $repository2->findById(requiredFamilyPersistenceId($family2)),
            RuntimeException::class,
        );
    });

    $runner->add('SQLite Family resource constraints enforce checks composite ownership and active uniqueness', function (): void {
        [$pdo, , $family] = persistedFamilyResourcesFixture();
        $familyId = requiredFamilyPersistenceId($family)->value();
        $addressId = requiredFamilyAddressId($family)->value();
        $contactId = requiredFamilyEmergencyContactId($family)->value();
        $pickupId = requiredFamilyAuthorizedPickupId($family)->value();
        assertThrows(static function () use ($pdo, $familyId): void {
            $pdo->exec("INSERT INTO family_authorized_pickups (family_id,names,relationship_type_id,mobile_phone,document_type_id,document_number,status_id) VALUES ($familyId,'Bad',1,'x',1,NULL,1)");
        }, PDOException::class);
        assertThrows(static function () use ($pdo, $familyId, $contactId): void {
            $pdo->exec("INSERT INTO emergency_contact_assignments (family_id,family_emergency_contact_id,student_id,priority,started_at) VALUES ($familyId,$contactId,1,0,'2026-09-01 00:00:00')");
        }, PDOException::class);
        assertThrows(static function () use ($pdo, $familyId, $addressId): void {
            $pdo->exec("INSERT INTO student_address_assignments (family_id,family_address_id,student_id,started_at) VALUES ($familyId,$addressId,1,'2026-09-01 00:00:00')");
        }, PDOException::class);
        assertSameValue(true, $pickupId > 0);
    });

    $runner->add('pdo Family resource failure follows own and outer transaction boundaries', function (): void {
        $pdo = sqliteFamilyDatabase();
        $repository = familyPersistenceRepositoryWithPdo($pdo);
        $family = $repository->save(newFamilyPersistenceFixture(1));
        $family->addEmergencyContact(new FamilyResourceName('Invalid FK'), new RelationshipTypeId(99999),
            new EmergencyContactInformation('mobile', null, null, null));
        assertThrows(static fn (): Family => $repository->save($family), PDOException::class);
        assertSameValue(false, $pdo->inTransaction());
        assertSameValue(0, (int) $pdo->query('SELECT COUNT(*) FROM family_emergency_contacts')->fetchColumn());

        $outerFamily = $repository->findById(requiredFamilyPersistenceId($family));
        if ($outerFamily === null) {
            throw new RuntimeException('Expected Family before outer transaction probe.');
        }
        $outerFamily->addEmergencyContact(new FamilyResourceName('Outer invalid'), new RelationshipTypeId(99998),
            new EmergencyContactInformation('mobile', null, null, null));
        $pdo->beginTransaction();
        assertThrows(static fn (): Family => $repository->save($outerFamily), PDOException::class);
        assertSameValue(true, $pdo->inTransaction());
        $pdo->rollBack();
    });

    $runner->add('Family resource persistence SQL omits manual and generated identities and destructive writes', function (): void {
        $source = familyPersistenceSource('app/Family/Infrastructure/Persistence/PdoFamilyRepository.php');
        foreach ([
            'family_addresses', 'representative_address_assignments', 'student_address_assignments',
            'family_emergency_contacts', 'emergency_contact_assignments', 'family_authorized_pickups',
            'authorized_pickup_assignments',
        ] as $table) {
            assertSameValue(false, str_contains($source, 'INSERT INTO ' . $table . ' (id,'));
        }
        foreach (['active_family_representative_key,', 'active_student_id,', 'active_contact_student_key,',
            'active_student_priority_key,', 'active_pickup_student_key,'] as $generatedColumn) {
            assertSameValue(false, str_contains($source, $generatedColumn));
        }
        assertSameValue(false, preg_match('/\b(?:DELETE|REPLACE)\b/i', $source) === 1);
        assertSameValue(false, str_contains($source, 'MAX('));
        assertSameValue(false, str_contains($source, 'SAVEPOINT'));
    });
}

/** @return array{PDO, PdoFamilyRepository, Family} */
function persistedFamilyResourcesFixture(bool $withAssignments = true): array
{
    $pdo = sqliteFamilyDatabase();
    $repository = familyPersistenceRepositoryWithPdo($pdo);
    $family = newFamilyPersistenceFixture(1, displayName: 'Family Resources');
    $family->addStudent(new StudentId(1), familyPersistenceTime('2026-08-11 10:00:00'));
    $family = $repository->save($family);
    $family->addAddress(new AddressLabel('Casa O\'Brien'), new Address(
        'Av. O\'Brien ñ', 'N-42', 'Calle secundaria', 'Sector', 'Referencia',
        new Geolocation('-0.1234567', '179.9999999'),
    ));
    $family->addEmergencyContact(new FamilyResourceName('María D\'Angelo'), new RelationshipTypeId(1),
        new EmergencyContactInformation('móvil +593', 'teléfono', 'maria@example.test', 'Observación'));
    $family->addAuthorizedPickup(new FamilyResourceName('José O\'Neil'), new RelationshipTypeId(1),
        new AuthorizedPickupInformation('pickup +593', 'fijo', 'Autorizado'),
        new PickupIdentification(new DocumentTypeId(1), 'ID-Ñ-001'));
    $family = $repository->save($family);

    if ($withAssignments) {
        $family->assignAddressToRepresentative(new RepresentativeId(1), requiredFamilyAddressId($family),
            familyPersistenceTime('2026-08-11 15:00:01'));
        $family->assignAddressToStudent(new StudentId(1), requiredFamilyAddressId($family),
            familyPersistenceTime('2026-08-11 15:01:02'));
        $family->assignEmergencyContactToStudent(new StudentId(1), requiredFamilyEmergencyContactId($family),
            new EmergencyContactPriority(1), familyPersistenceTime('2026-08-11 15:02:03'));
        $family->assignAuthorizedPickupToStudent(new StudentId(1), requiredFamilyAuthorizedPickupId($family),
            familyPersistenceTime('2026-08-11 15:03:04'));
        $family = $repository->save($family);
    }

    return [$pdo, $repository, $family];
}

/** @param list<object> $resources @return list<int> */
function familyResourceIds(array $resources): array
{
    return array_map(static fn (object $resource): int => $resource->id()?->value() ?? 0, $resources);
}

function requiredFamilyAddressId(Family $family): FamilyAddressId
{
    return $family->addresses()[0]->id() ?? throw new RuntimeException('Expected persisted FamilyAddress.');
}

function requiredFamilyEmergencyContactId(Family $family): FamilyEmergencyContactId
{
    return $family->emergencyContacts()[0]->id() ?? throw new RuntimeException('Expected persisted contact.');
}

function requiredFamilyAuthorizedPickupId(Family $family): FamilyAuthorizedPickupId
{
    return $family->authorizedPickups()[0]->id() ?? throw new RuntimeException('Expected persisted pickup.');
}

function simplePersistedAddress(string $street): Address
{
    return new Address($street, null, null, null, null, null);
}
