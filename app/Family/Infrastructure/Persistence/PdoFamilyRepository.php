<?php

declare(strict_types=1);

namespace App\Family\Infrastructure\Persistence;

use App\Family\Domain\Exception\InvalidFamilyState;
use App\Family\Domain\AuthorizedPickupAssignment;
use App\Family\Domain\EmergencyContactAssignment;
use App\Family\Domain\Family;
use App\Family\Domain\FamilyAddress;
use App\Family\Domain\FamilyAuthorizedPickup;
use App\Family\Domain\FamilyEmergencyContact;
use App\Family\Domain\FamilyRepresentative;
use App\Family\Domain\FamilyRepository;
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
use Core\Database\ConnectionManager;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;

final class PdoFamilyRepository implements FamilyRepository
{
    private const STATUS_TYPE = 'GENERAL_STATUS';

    private PDO $connection;

    public function __construct(ConnectionManager $connectionManager)
    {
        $this->connection = $connectionManager->connection();
    }

    public function findById(FamilyId $id): ?Family
    {
        $row = $this->findFamilyRow($id);

        return $row === null ? null : $this->mapFamily($row);
    }

    public function findActiveByRepresentativeId(RepresentativeId $representativeId): array
    {
        $statement = $this->connection->prepare(
            'SELECT DISTINCT fr.family_id FROM family_representatives fr '
            . 'WHERE fr.representative_id = :representativeId AND fr.ended_at IS NULL '
            . 'ORDER BY fr.family_id'
        );
        $statement->execute([':representativeId' => $representativeId->value()]);

        return $this->findFamiliesByIds($statement->fetchAll(PDO::FETCH_COLUMN));
    }

    public function findActiveByStudentId(StudentId $studentId): ?Family
    {
        $statement = $this->connection->prepare(
            'SELECT DISTINCT fs.family_id FROM family_students fs '
            . 'WHERE fs.student_id = :studentId AND fs.ended_at IS NULL '
            . 'ORDER BY fs.family_id'
        );
        $statement->execute([':studentId' => $studentId->value()]);
        $familyIds = $statement->fetchAll(PDO::FETCH_COLUMN);

        if (count($familyIds) > 1) {
            throw new RuntimeException('Student has more than one active persisted Family membership.');
        }

        if ($familyIds === []) {
            return null;
        }

        $family = $this->findById(new FamilyId((int) $familyIds[0]));
        if ($family === null) {
            throw new RuntimeException('Active FamilyStudent membership references a missing Family.');
        }

        return $family;
    }

    public function findActiveByStudentIdForUpdate(StudentId $studentId): ?Family
    {
        if (!$this->connection->inTransaction()) {
            throw new RuntimeException('Active FamilyStudent row lock requires an active transaction.');
        }

        $isSqlite = $this->connection->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        $sql = 'SELECT fs.id, fs.family_id, fs.student_id FROM family_students fs '
            . ($isSqlite
                ? 'WHERE fs.student_id = :studentId AND fs.ended_at IS NULL '
                : 'WHERE fs.active_student_id = :studentId ')
            . 'ORDER BY fs.id';
        if (!$isSqlite) {
            $sql .= ' FOR UPDATE';
        }

        $statement = $this->connection->prepare($sql);
        $statement->execute([':studentId' => $studentId->value()]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        if (count($rows) > 1) {
            throw new RuntimeException('Student has more than one active persisted Family membership for update.');
        }
        if ($rows === []) {
            return null;
        }

        $row = $rows[0];
        $membershipId = new FamilyStudentId($this->persistedPositiveInt(
            $row['id'] ?? null,
            'Locked FamilyStudent id',
        ));
        $familyId = new FamilyId($this->persistedPositiveInt(
            $row['family_id'] ?? null,
            'Locked FamilyStudent family_id',
        ));
        $lockedStudentId = new StudentId($this->persistedPositiveInt(
            $row['student_id'] ?? null,
            'Locked FamilyStudent student_id',
        ));
        if (!$lockedStudentId->equals($studentId)) {
            throw new RuntimeException('Active FamilyStudent row lock returned an incoherent Student identity.');
        }

        $family = $this->findById($familyId);
        if ($family === null) {
            throw new RuntimeException('Locked active FamilyStudent membership references a missing Family.');
        }

        $matches = array_values(array_filter(
            $family->activeStudents(),
            static fn (FamilyStudent $membership): bool => $membership->studentId()->equals($studentId),
        ));
        if (count($matches) !== 1
            || $matches[0]->id()?->equals($membershipId) !== true
        ) {
            throw new RuntimeException(
                'Reconstructed Family is incoherent with the locked active FamilyStudent membership.'
            );
        }

        return $family;
    }

    public function save(Family $family): Family
    {
        $ownsTransaction = !$this->connection->inTransaction();
        if ($ownsTransaction && !$this->connection->beginTransaction()) {
            throw new RuntimeException('Family persistence could not start its transaction.');
        }

        try {
            $statusId = $this->resolveStatusId($family->status());
            $persisted = $family->id() === null
                ? $this->insertFamily($family, $statusId)
                : $this->updateFamily($family, $statusId);

            if ($ownsTransaction && !$this->connection->commit()) {
                throw new RuntimeException('Family persistence could not commit its transaction.');
            }

            return $persisted;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->connection->inTransaction()) {
                $this->connection->rollBack();
            }

            throw $exception;
        }
    }

    /** @param list<int|string> $ids @return list<Family> */
    private function findFamiliesByIds(array $ids): array
    {
        $families = [];
        foreach ($ids as $id) {
            $family = $this->findById(new FamilyId((int) $id));
            if ($family === null) {
                throw new RuntimeException('Active membership references a missing Family.');
            }

            $families[] = $family;
        }

        return $families;
    }

    private function insertFamily(Family $family, int $statusId): Family
    {
        $statement = $this->connection->prepare(
            'INSERT INTO families (display_name, status_id) VALUES (:displayName, :statusId)'
        );
        $statement->execute([
            ':displayName' => $family->displayName()->value(),
            ':statusId' => $statusId,
        ]);
        $this->requireSingleInsertedRow($statement->rowCount(), 'Family');

        $familyId = new FamilyId($this->generatedId('Family'));
        foreach ($family->representatives() as $membership) {
            if ($membership->id() !== null) {
                throw new RuntimeException('A new Family cannot contain a persisted representative membership.');
            }

            $this->insertRepresentativeMembership($familyId, $membership);
        }

        foreach ($family->students() as $membership) {
            if ($membership->id() !== null) {
                throw new RuntimeException('A new Family cannot contain a persisted student membership.');
            }

            $this->insertStudentMembership($familyId, $membership);
        }

        $this->insertNewResources($familyId, $family);
        $this->insertNewAssignments($familyId, $family);

        return $this->requirePersistedFamily($familyId, 'Inserted Family could not be reconstructed.');
    }

    private function updateFamily(Family $family, int $statusId): Family
    {
        $familyId = $family->id();
        if ($familyId === null) {
            throw new RuntimeException('A Family without persisted identity cannot be updated.');
        }

        $representatives = $family->representatives();
        $students = $family->students();
        $addresses = $family->addresses();
        $representativeAddressAssignments = $family->representativeAddressAssignments();
        $studentAddressAssignments = $family->studentAddressAssignments();
        $emergencyContacts = $family->emergencyContacts();
        $emergencyContactAssignments = $family->emergencyContactAssignments();
        $authorizedPickups = $family->authorizedPickups();
        $authorizedPickupAssignments = $family->authorizedPickupAssignments();
        $persistedRepresentatives = $this->representativeRows($familyId);
        $persistedStudents = $this->studentRows($familyId);

        $this->validateRepresentativeSynchronization(
            $familyId,
            $representatives,
            $persistedRepresentatives,
        );
        $this->validateStudentSynchronization($familyId, $students, $persistedStudents);
        $this->validateResourceSynchronization(
            $familyId,
            $addresses,
            $this->addressRows($familyId),
            'FamilyAddress',
            fn (int $id): ?array => $this->findAddressRow(new FamilyAddressId($id)),
        );
        $this->validateResourceSynchronization(
            $familyId,
            $emergencyContacts,
            $this->emergencyContactRows($familyId),
            'FamilyEmergencyContact',
            fn (int $id): ?array => $this->findEmergencyContactRow(new FamilyEmergencyContactId($id)),
        );
        $this->validateResourceSynchronization(
            $familyId,
            $authorizedPickups,
            $this->authorizedPickupRows($familyId),
            'FamilyAuthorizedPickup',
            fn (int $id): ?array => $this->findAuthorizedPickupRow(new FamilyAuthorizedPickupId($id)),
        );
        $this->validateAssignmentSynchronization(
            $familyId,
            $representativeAddressAssignments,
            $this->representativeAddressAssignmentRows($familyId),
            'RepresentativeAddressAssignment',
            fn (int $id): ?array => $this->findRepresentativeAddressAssignmentRow(
                new RepresentativeAddressAssignmentId($id)
            ),
            fn (array $row, object $assignment) => $this->assertRepresentativeAddressTransition(
                $row,
                $assignment,
            ),
        );
        $this->validateAssignmentSynchronization(
            $familyId,
            $studentAddressAssignments,
            $this->studentAddressAssignmentRows($familyId),
            'StudentAddressAssignment',
            fn (int $id): ?array => $this->findStudentAddressAssignmentRow(new StudentAddressAssignmentId($id)),
            fn (array $row, object $assignment) => $this->assertStudentAddressTransition($row, $assignment),
        );
        $this->validateAssignmentSynchronization(
            $familyId,
            $emergencyContactAssignments,
            $this->emergencyContactAssignmentRows($familyId),
            'EmergencyContactAssignment',
            fn (int $id): ?array => $this->findEmergencyContactAssignmentRow(
                new EmergencyContactAssignmentId($id)
            ),
            fn (array $row, object $assignment) => $this->assertEmergencyContactTransition(
                $row,
                $assignment,
            ),
        );
        $this->validateAssignmentSynchronization(
            $familyId,
            $authorizedPickupAssignments,
            $this->authorizedPickupAssignmentRows($familyId),
            'AuthorizedPickupAssignment',
            fn (int $id): ?array => $this->findAuthorizedPickupAssignmentRow(
                new AuthorizedPickupAssignmentId($id)
            ),
            fn (array $row, object $assignment) => $this->assertAuthorizedPickupTransition(
                $row,
                $assignment,
            ),
        );

        $statement = $this->connection->prepare(
            'UPDATE families SET display_name = :displayName, status_id = :statusId WHERE id = :id'
        );
        $statement->execute([
            ':displayName' => $family->displayName()->value(),
            ':statusId' => $statusId,
            ':id' => $familyId->value(),
        ]);
        $this->requireZeroOrOneUpdatedRow($statement->rowCount(), 'Family');

        $persistedFamilyRow = $this->findFamilyRow($familyId);
        if ($persistedFamilyRow === null) {
            throw new RuntimeException('Family update failed because the persisted row disappeared.');
        }

        if (!$this->sameFamilyRow($persistedFamilyRow, $family)) {
            throw new RuntimeException('Family update did not persist the requested state.');
        }

        foreach ($representatives as $membership) {
            if ($membership->id() === null) {
                $this->insertRepresentativeMembership($familyId, $membership);
                continue;
            }

            $this->updateRepresentativeMembership($familyId, $membership);
        }

        foreach ($students as $membership) {
            if ($membership->id() === null) {
                $this->insertStudentMembership($familyId, $membership);
                continue;
            }

            $this->updateStudentMembership($familyId, $membership);
        }

        $this->synchronizeResources($familyId, $addresses, $emergencyContacts, $authorizedPickups);
        $this->synchronizeAssignments(
            $familyId,
            $representativeAddressAssignments,
            $studentAddressAssignments,
            $emergencyContactAssignments,
            $authorizedPickupAssignments,
        );

        return $this->requirePersistedFamily($familyId, 'Updated Family could not be reconstructed.');
    }

    private function insertRepresentativeMembership(
        FamilyId $familyId,
        FamilyRepresentative $membership,
    ): void {
        $statement = $this->connection->prepare(
            'INSERT INTO family_representatives ('
            . 'family_id, representative_id, relationship_type_id, is_primary, started_at, ended_at'
            . ') VALUES ('
            . ':familyId, :representativeId, :relationshipTypeId, :isPrimary, :startedAt, :endedAt'
            . ')'
        );
        $statement->execute([
            ':familyId' => $familyId->value(),
            ':representativeId' => $membership->representativeId()->value(),
            ':relationshipTypeId' => $membership->relationshipTypeId()->value(),
            ':isPrimary' => $membership->isPrimary() ? 1 : 0,
            ':startedAt' => $this->formatTimestamp($membership->startedAt()),
            ':endedAt' => $this->formatNullableTimestamp($membership->endedAt()),
        ]);
        $this->requireSingleInsertedRow($statement->rowCount(), 'FamilyRepresentative');
        $this->generatedId('FamilyRepresentative');
    }

    private function insertStudentMembership(FamilyId $familyId, FamilyStudent $membership): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO family_students (family_id, student_id, started_at, ended_at) '
            . 'VALUES (:familyId, :studentId, :startedAt, :endedAt)'
        );
        $statement->execute([
            ':familyId' => $familyId->value(),
            ':studentId' => $membership->studentId()->value(),
            ':startedAt' => $this->formatTimestamp($membership->startedAt()),
            ':endedAt' => $this->formatNullableTimestamp($membership->endedAt()),
        ]);
        $this->requireSingleInsertedRow($statement->rowCount(), 'FamilyStudent');
        $this->generatedId('FamilyStudent');
    }

    private function updateRepresentativeMembership(
        FamilyId $familyId,
        FamilyRepresentative $membership,
    ): void {
        $id = $membership->id();
        if ($id === null) {
            throw new RuntimeException('A representative membership without identity cannot be updated.');
        }

        $statement = $this->connection->prepare(
            'UPDATE family_representatives SET ended_at = :endedAt '
            . 'WHERE id = :id AND family_id = :familyId'
        );
        $statement->execute([
            ':endedAt' => $this->formatNullableTimestamp($membership->endedAt()),
            ':id' => $id->value(),
            ':familyId' => $familyId->value(),
        ]);
        $this->requireZeroOrOneUpdatedRow($statement->rowCount(), 'FamilyRepresentative');

        $row = $this->findRepresentativeRow($id);
        if ($row === null) {
            throw new RuntimeException('FamilyRepresentative update failed because the row disappeared.');
        }

        if ((int) $row['family_id'] !== $familyId->value()) {
            throw new RuntimeException('FamilyRepresentative belongs to another Family.');
        }

        if (!$this->sameRepresentativeRow($row, $membership)) {
            throw new RuntimeException('FamilyRepresentative update did not persist the requested state.');
        }
    }

    private function updateStudentMembership(FamilyId $familyId, FamilyStudent $membership): void
    {
        $id = $membership->id();
        if ($id === null) {
            throw new RuntimeException('A student membership without identity cannot be updated.');
        }

        $statement = $this->connection->prepare(
            'UPDATE family_students SET ended_at = :endedAt WHERE id = :id AND family_id = :familyId'
        );
        $statement->execute([
            ':endedAt' => $this->formatNullableTimestamp($membership->endedAt()),
            ':id' => $id->value(),
            ':familyId' => $familyId->value(),
        ]);
        $this->requireZeroOrOneUpdatedRow($statement->rowCount(), 'FamilyStudent');

        $row = $this->findStudentRow($id);
        if ($row === null) {
            throw new RuntimeException('FamilyStudent update failed because the row disappeared.');
        }

        if ((int) $row['family_id'] !== $familyId->value()) {
            throw new RuntimeException('FamilyStudent belongs to another Family.');
        }

        if (!$this->sameStudentRow($row, $membership)) {
            throw new RuntimeException('FamilyStudent update did not persist the requested state.');
        }
    }

    private function insertNewResources(FamilyId $familyId, Family $family): void
    {
        foreach ($family->addresses() as $resource) {
            $this->requireNewIdentity($resource->id(), 'FamilyAddress');
            $this->insertAddress($familyId, $resource);
        }
        foreach ($family->emergencyContacts() as $resource) {
            $this->requireNewIdentity($resource->id(), 'FamilyEmergencyContact');
            $this->insertEmergencyContact($familyId, $resource);
        }
        foreach ($family->authorizedPickups() as $resource) {
            $this->requireNewIdentity($resource->id(), 'FamilyAuthorizedPickup');
            $this->insertAuthorizedPickup($familyId, $resource);
        }
    }

    private function insertNewAssignments(FamilyId $familyId, Family $family): void
    {
        foreach ($family->representativeAddressAssignments() as $assignment) {
            $this->requireNewIdentity($assignment->id(), 'RepresentativeAddressAssignment');
            $this->insertRepresentativeAddressAssignment($familyId, $assignment);
        }
        foreach ($family->studentAddressAssignments() as $assignment) {
            $this->requireNewIdentity($assignment->id(), 'StudentAddressAssignment');
            $this->insertStudentAddressAssignment($familyId, $assignment);
        }
        foreach ($family->emergencyContactAssignments() as $assignment) {
            $this->requireNewIdentity($assignment->id(), 'EmergencyContactAssignment');
            $this->insertEmergencyContactAssignment($familyId, $assignment);
        }
        foreach ($family->authorizedPickupAssignments() as $assignment) {
            $this->requireNewIdentity($assignment->id(), 'AuthorizedPickupAssignment');
            $this->insertAuthorizedPickupAssignment($familyId, $assignment);
        }
    }

    /** @param list<FamilyAddress> $addresses @param list<FamilyEmergencyContact> $contacts
     * @param list<FamilyAuthorizedPickup> $pickups
     */
    private function synchronizeResources(
        FamilyId $familyId,
        array $addresses,
        array $contacts,
        array $pickups,
    ): void {
        foreach ($addresses as $resource) {
            $resource->id() === null
                ? $this->insertAddress($familyId, $resource)
                : $this->updateAddress($familyId, $resource);
        }
        foreach ($contacts as $resource) {
            $resource->id() === null
                ? $this->insertEmergencyContact($familyId, $resource)
                : $this->updateEmergencyContact($familyId, $resource);
        }
        foreach ($pickups as $resource) {
            $resource->id() === null
                ? $this->insertAuthorizedPickup($familyId, $resource)
                : $this->updateAuthorizedPickup($familyId, $resource);
        }
    }

    /** @param list<RepresentativeAddressAssignment> $representativeAddresses
     * @param list<StudentAddressAssignment> $studentAddresses
     * @param list<EmergencyContactAssignment> $emergencyContacts
     * @param list<AuthorizedPickupAssignment> $authorizedPickups
     */
    private function synchronizeAssignments(
        FamilyId $familyId,
        array $representativeAddresses,
        array $studentAddresses,
        array $emergencyContacts,
        array $authorizedPickups,
    ): void {
        foreach ($representativeAddresses as $assignment) {
            $assignment->id() === null
                ? $this->insertRepresentativeAddressAssignment($familyId, $assignment)
                : $this->updateRepresentativeAddressAssignment($familyId, $assignment);
        }
        foreach ($studentAddresses as $assignment) {
            $assignment->id() === null
                ? $this->insertStudentAddressAssignment($familyId, $assignment)
                : $this->updateStudentAddressAssignment($familyId, $assignment);
        }
        foreach ($emergencyContacts as $assignment) {
            $assignment->id() === null
                ? $this->insertEmergencyContactAssignment($familyId, $assignment)
                : $this->updateEmergencyContactAssignment($familyId, $assignment);
        }
        foreach ($authorizedPickups as $assignment) {
            $assignment->id() === null
                ? $this->insertAuthorizedPickupAssignment($familyId, $assignment)
                : $this->updateAuthorizedPickupAssignment($familyId, $assignment);
        }
    }

    private function insertAddress(FamilyId $familyId, FamilyAddress $resource): void
    {
        $geolocation = $resource->address()->geolocation();
        $statement = $this->connection->prepare(
            'INSERT INTO family_addresses (family_id, label, main_street, street_number, '
            . 'secondary_street, sector, reference, latitude, longitude, status_id) VALUES '
            . '(:familyId, :label, :mainStreet, :streetNumber, :secondaryStreet, :sector, '
            . ':reference, :latitude, :longitude, :statusId)'
        );
        $statement->execute([
            ':familyId' => $familyId->value(),
            ':label' => $resource->label()->value(),
            ':mainStreet' => $resource->address()->mainStreet(),
            ':streetNumber' => $resource->address()->streetNumber(),
            ':secondaryStreet' => $resource->address()->secondaryStreet(),
            ':sector' => $resource->address()->sector(),
            ':reference' => $resource->address()->reference(),
            ':latitude' => $geolocation?->latitude(),
            ':longitude' => $geolocation?->longitude(),
            ':statusId' => $this->resolveResourceStatusId($resource->status()),
        ]);
        $this->requireSingleInsertedRow($statement->rowCount(), 'FamilyAddress');
        $this->generatedId('FamilyAddress');
    }

    private function updateAddress(FamilyId $familyId, FamilyAddress $resource): void
    {
        $id = $this->requiredPersistedId($resource->id(), 'FamilyAddress');
        $geolocation = $resource->address()->geolocation();
        $statement = $this->connection->prepare(
            'UPDATE family_addresses SET label = :label, main_street = :mainStreet, '
            . 'street_number = :streetNumber, secondary_street = :secondaryStreet, sector = :sector, '
            . 'reference = :reference, latitude = :latitude, longitude = :longitude, status_id = :statusId '
            . 'WHERE id = :id AND family_id = :familyId'
        );
        $statement->execute([
            ':label' => $resource->label()->value(), ':mainStreet' => $resource->address()->mainStreet(),
            ':streetNumber' => $resource->address()->streetNumber(),
            ':secondaryStreet' => $resource->address()->secondaryStreet(), ':sector' => $resource->address()->sector(),
            ':reference' => $resource->address()->reference(), ':latitude' => $geolocation?->latitude(),
            ':longitude' => $geolocation?->longitude(), ':statusId' => $this->resolveResourceStatusId($resource->status()),
            ':id' => $id, ':familyId' => $familyId->value(),
        ]);
        $this->requireZeroOrOneUpdatedRow($statement->rowCount(), 'FamilyAddress');
        $row = $this->findAddressRow(new FamilyAddressId($id));
        if ($row === null || !$this->sameAddressRow($row, $familyId, $resource)) {
            throw new RuntimeException('FamilyAddress update did not persist the requested state.');
        }
    }

    private function insertEmergencyContact(FamilyId $familyId, FamilyEmergencyContact $resource): void
    {
        $contact = $resource->contactInformation();
        $statement = $this->connection->prepare(
            'INSERT INTO family_emergency_contacts (family_id, names, relationship_type_id, mobile_phone, '
            . 'phone, email, observations, status_id) VALUES (:familyId, :names, :relationshipTypeId, '
            . ':mobilePhone, :phone, :email, :observations, :statusId)'
        );
        $statement->execute([
            ':familyId' => $familyId->value(), ':names' => $resource->names()->value(),
            ':relationshipTypeId' => $resource->relationshipTypeId()->value(),
            ':mobilePhone' => $contact->mobilePhone(), ':phone' => $contact->phone(),
            ':email' => $contact->email(), ':observations' => $contact->observations(),
            ':statusId' => $this->resolveResourceStatusId($resource->status()),
        ]);
        $this->requireSingleInsertedRow($statement->rowCount(), 'FamilyEmergencyContact');
        $this->generatedId('FamilyEmergencyContact');
    }

    private function updateEmergencyContact(FamilyId $familyId, FamilyEmergencyContact $resource): void
    {
        $id = $this->requiredPersistedId($resource->id(), 'FamilyEmergencyContact');
        $contact = $resource->contactInformation();
        $statement = $this->connection->prepare(
            'UPDATE family_emergency_contacts SET names = :names, relationship_type_id = :relationshipTypeId, '
            . 'mobile_phone = :mobilePhone, phone = :phone, email = :email, observations = :observations, '
            . 'status_id = :statusId WHERE id = :id AND family_id = :familyId'
        );
        $statement->execute([
            ':names' => $resource->names()->value(), ':relationshipTypeId' => $resource->relationshipTypeId()->value(),
            ':mobilePhone' => $contact->mobilePhone(), ':phone' => $contact->phone(), ':email' => $contact->email(),
            ':observations' => $contact->observations(), ':statusId' => $this->resolveResourceStatusId($resource->status()),
            ':id' => $id, ':familyId' => $familyId->value(),
        ]);
        $this->requireZeroOrOneUpdatedRow($statement->rowCount(), 'FamilyEmergencyContact');
        $row = $this->findEmergencyContactRow(new FamilyEmergencyContactId($id));
        if ($row === null || !$this->sameEmergencyContactRow($row, $familyId, $resource)) {
            throw new RuntimeException('FamilyEmergencyContact update did not persist the requested state.');
        }
    }

    private function insertAuthorizedPickup(FamilyId $familyId, FamilyAuthorizedPickup $resource): void
    {
        $contact = $resource->contactInformation();
        $identification = $resource->identification();
        $statement = $this->connection->prepare(
            'INSERT INTO family_authorized_pickups (family_id, names, relationship_type_id, mobile_phone, '
            . 'phone, document_type_id, document_number, observations, status_id) VALUES (:familyId, :names, '
            . ':relationshipTypeId, :mobilePhone, :phone, :documentTypeId, :documentNumber, :observations, :statusId)'
        );
        $statement->execute([
            ':familyId' => $familyId->value(), ':names' => $resource->names()->value(),
            ':relationshipTypeId' => $resource->relationshipTypeId()->value(), ':mobilePhone' => $contact->mobilePhone(),
            ':phone' => $contact->phone(), ':documentTypeId' => $identification?->documentTypeId()->value(),
            ':documentNumber' => $identification?->documentNumber(), ':observations' => $contact->observations(),
            ':statusId' => $this->resolveResourceStatusId($resource->status()),
        ]);
        $this->requireSingleInsertedRow($statement->rowCount(), 'FamilyAuthorizedPickup');
        $this->generatedId('FamilyAuthorizedPickup');
    }

    private function updateAuthorizedPickup(FamilyId $familyId, FamilyAuthorizedPickup $resource): void
    {
        $id = $this->requiredPersistedId($resource->id(), 'FamilyAuthorizedPickup');
        $contact = $resource->contactInformation();
        $identification = $resource->identification();
        $statement = $this->connection->prepare(
            'UPDATE family_authorized_pickups SET names = :names, relationship_type_id = :relationshipTypeId, '
            . 'mobile_phone = :mobilePhone, phone = :phone, document_type_id = :documentTypeId, '
            . 'document_number = :documentNumber, observations = :observations, status_id = :statusId '
            . 'WHERE id = :id AND family_id = :familyId'
        );
        $statement->execute([
            ':names' => $resource->names()->value(), ':relationshipTypeId' => $resource->relationshipTypeId()->value(),
            ':mobilePhone' => $contact->mobilePhone(), ':phone' => $contact->phone(),
            ':documentTypeId' => $identification?->documentTypeId()->value(),
            ':documentNumber' => $identification?->documentNumber(), ':observations' => $contact->observations(),
            ':statusId' => $this->resolveResourceStatusId($resource->status()), ':id' => $id,
            ':familyId' => $familyId->value(),
        ]);
        $this->requireZeroOrOneUpdatedRow($statement->rowCount(), 'FamilyAuthorizedPickup');
        $row = $this->findAuthorizedPickupRow(new FamilyAuthorizedPickupId($id));
        if ($row === null || !$this->sameAuthorizedPickupRow($row, $familyId, $resource)) {
            throw new RuntimeException('FamilyAuthorizedPickup update did not persist the requested state.');
        }
    }

    private function insertRepresentativeAddressAssignment(
        FamilyId $familyId,
        RepresentativeAddressAssignment $assignment,
    ): void {
        $this->insertAssignment(
            'INSERT INTO representative_address_assignments '
            . '(family_id, family_address_id, representative_id, started_at, ended_at) '
            . 'VALUES (:familyId, :resourceId, :memberId, :startedAt, :endedAt)',
            $familyId,
            $assignment->familyAddressId()->value(),
            $assignment->representativeId()->value(),
            $assignment->startedAt(),
            $assignment->endedAt(),
            'RepresentativeAddressAssignment',
        );
    }

    private function insertStudentAddressAssignment(FamilyId $familyId, StudentAddressAssignment $assignment): void
    {
        $this->insertAssignment(
            'INSERT INTO student_address_assignments '
            . '(family_id, family_address_id, student_id, started_at, ended_at) '
            . 'VALUES (:familyId, :resourceId, :memberId, :startedAt, :endedAt)',
            $familyId,
            $assignment->familyAddressId()->value(),
            $assignment->studentId()->value(),
            $assignment->startedAt(),
            $assignment->endedAt(),
            'StudentAddressAssignment',
        );
    }

    private function insertEmergencyContactAssignment(
        FamilyId $familyId,
        EmergencyContactAssignment $assignment,
    ): void {
        $statement = $this->connection->prepare(
            'INSERT INTO emergency_contact_assignments (family_id, family_emergency_contact_id, '
            . 'student_id, priority, started_at, ended_at) VALUES (:familyId, :resourceId, :studentId, '
            . ':priority, :startedAt, :endedAt)'
        );
        $statement->execute([
            ':familyId' => $familyId->value(), ':resourceId' => $assignment->familyEmergencyContactId()->value(),
            ':studentId' => $assignment->studentId()->value(), ':priority' => $assignment->priority()?->value(),
            ':startedAt' => $this->formatTimestamp($assignment->startedAt()),
            ':endedAt' => $this->formatNullableTimestamp($assignment->endedAt()),
        ]);
        $this->requireSingleInsertedRow($statement->rowCount(), 'EmergencyContactAssignment');
        $this->generatedId('EmergencyContactAssignment');
    }

    private function insertAuthorizedPickupAssignment(
        FamilyId $familyId,
        AuthorizedPickupAssignment $assignment,
    ): void {
        $this->insertAssignment(
            'INSERT INTO authorized_pickup_assignments '
            . '(family_id, family_authorized_pickup_id, student_id, started_at, ended_at) '
            . 'VALUES (:familyId, :resourceId, :memberId, :startedAt, :endedAt)',
            $familyId,
            $assignment->familyAuthorizedPickupId()->value(),
            $assignment->studentId()->value(),
            $assignment->startedAt(),
            $assignment->endedAt(),
            'AuthorizedPickupAssignment',
        );
    }

    private function insertAssignment(
        string $sql,
        FamilyId $familyId,
        int $resourceId,
        int $memberId,
        DateTimeImmutable $startedAt,
        ?DateTimeImmutable $endedAt,
        string $entity,
    ): void {
        $statement = $this->connection->prepare($sql);
        $statement->execute([
            ':familyId' => $familyId->value(), ':resourceId' => $resourceId, ':memberId' => $memberId,
            ':startedAt' => $this->formatTimestamp($startedAt),
            ':endedAt' => $this->formatNullableTimestamp($endedAt),
        ]);
        $this->requireSingleInsertedRow($statement->rowCount(), $entity);
        $this->generatedId($entity);
    }

    private function updateRepresentativeAddressAssignment(
        FamilyId $familyId,
        RepresentativeAddressAssignment $assignment,
    ): void {
        $id = $this->requiredPersistedId($assignment->id(), 'RepresentativeAddressAssignment');
        $this->updateAssignmentEnd(
            'UPDATE representative_address_assignments SET ended_at = :endedAt '
            . 'WHERE id = :id AND family_id = :familyId',
            $id,
            $familyId,
            $assignment->endedAt(),
            'RepresentativeAddressAssignment',
        );
        $row = $this->findRepresentativeAddressAssignmentRow(new RepresentativeAddressAssignmentId($id));
        if ($row === null || !$this->sameRepresentativeAddressAssignmentRow($row, $familyId, $assignment)) {
            throw new RuntimeException('RepresentativeAddressAssignment update did not persist the requested state.');
        }
    }

    private function updateStudentAddressAssignment(FamilyId $familyId, StudentAddressAssignment $assignment): void
    {
        $id = $this->requiredPersistedId($assignment->id(), 'StudentAddressAssignment');
        $this->updateAssignmentEnd(
            'UPDATE student_address_assignments SET ended_at = :endedAt WHERE id = :id AND family_id = :familyId',
            $id,
            $familyId,
            $assignment->endedAt(),
            'StudentAddressAssignment',
        );
        $row = $this->findStudentAddressAssignmentRow(new StudentAddressAssignmentId($id));
        if ($row === null || !$this->sameStudentAddressAssignmentRow($row, $familyId, $assignment)) {
            throw new RuntimeException('StudentAddressAssignment update did not persist the requested state.');
        }
    }

    private function updateEmergencyContactAssignment(
        FamilyId $familyId,
        EmergencyContactAssignment $assignment,
    ): void {
        $id = $this->requiredPersistedId($assignment->id(), 'EmergencyContactAssignment');
        $this->updateAssignmentEnd(
            'UPDATE emergency_contact_assignments SET ended_at = :endedAt WHERE id = :id AND family_id = :familyId',
            $id,
            $familyId,
            $assignment->endedAt(),
            'EmergencyContactAssignment',
        );
        $row = $this->findEmergencyContactAssignmentRow(new EmergencyContactAssignmentId($id));
        if ($row === null || !$this->sameEmergencyContactAssignmentRow($row, $familyId, $assignment)) {
            throw new RuntimeException('EmergencyContactAssignment update did not persist the requested state.');
        }
    }

    private function updateAuthorizedPickupAssignment(
        FamilyId $familyId,
        AuthorizedPickupAssignment $assignment,
    ): void {
        $id = $this->requiredPersistedId($assignment->id(), 'AuthorizedPickupAssignment');
        $this->updateAssignmentEnd(
            'UPDATE authorized_pickup_assignments SET ended_at = :endedAt WHERE id = :id AND family_id = :familyId',
            $id,
            $familyId,
            $assignment->endedAt(),
            'AuthorizedPickupAssignment',
        );
        $row = $this->findAuthorizedPickupAssignmentRow(new AuthorizedPickupAssignmentId($id));
        if ($row === null || !$this->sameAuthorizedPickupAssignmentRow($row, $familyId, $assignment)) {
            throw new RuntimeException('AuthorizedPickupAssignment update did not persist the requested state.');
        }
    }

    private function updateAssignmentEnd(
        string $sql,
        int $id,
        FamilyId $familyId,
        ?DateTimeImmutable $endedAt,
        string $entity,
    ): void {
        $statement = $this->connection->prepare($sql);
        $statement->execute([
            ':endedAt' => $this->formatNullableTimestamp($endedAt),
            ':id' => $id,
            ':familyId' => $familyId->value(),
        ]);
        $this->requireZeroOrOneUpdatedRow($statement->rowCount(), $entity);
    }

    /**
     * @param list<object> $resources
     * @param list<array<string, mixed>> $persistedRows
     * @param callable(int): (?array<string, mixed>) $findRow
     */
    private function validateResourceSynchronization(
        FamilyId $familyId,
        array $resources,
        array $persistedRows,
        string $entity,
        callable $findRow,
    ): void {
        $persistedById = $this->rowsById($persistedRows, $entity);
        $aggregateIds = [];
        foreach ($resources as $resource) {
            $id = $resource->id();
            if ($id === null) {
                continue;
            }
            $value = $id->value();
            if (isset($aggregateIds[$value])) {
                throw new RuntimeException('Family contains a duplicate persisted ' . $entity . ' identity.');
            }
            $aggregateIds[$value] = true;
            $row = $persistedById[$value] ?? $findRow($value);
            if ($row === null) {
                throw new RuntimeException('Family contains an unknown ' . $entity . ' identity.');
            }
            if ((int) $row['family_id'] !== $familyId->value()) {
                throw new RuntimeException($entity . ' belongs to another Family.');
            }
        }
        $this->assertNoPersistedMembershipOmitted(array_keys($persistedById), $aggregateIds, $entity);
    }

    /**
     * @param list<object> $assignments
     * @param list<array<string, mixed>> $persistedRows
     * @param callable(int): (?array<string, mixed>) $findRow
     * @param callable(array<string, mixed>, object): void $assertTransition
     */
    private function validateAssignmentSynchronization(
        FamilyId $familyId,
        array $assignments,
        array $persistedRows,
        string $entity,
        callable $findRow,
        callable $assertTransition,
    ): void {
        $persistedById = $this->rowsById($persistedRows, $entity);
        $aggregateIds = [];
        foreach ($assignments as $assignment) {
            $id = $assignment->id();
            if ($id === null) {
                continue;
            }
            $value = $id->value();
            if (isset($aggregateIds[$value])) {
                throw new RuntimeException('Family contains a duplicate persisted ' . $entity . ' identity.');
            }
            $aggregateIds[$value] = true;
            $row = $persistedById[$value] ?? $findRow($value);
            if ($row === null) {
                throw new RuntimeException('Family contains an unknown ' . $entity . ' identity.');
            }
            if ((int) $row['family_id'] !== $familyId->value()) {
                throw new RuntimeException($entity . ' belongs to another Family.');
            }
            $assertTransition($row, $assignment);
        }
        $this->assertNoPersistedMembershipOmitted(array_keys($persistedById), $aggregateIds, $entity);
    }

    /** @param array<string, mixed> $row */
    private function assertRepresentativeAddressTransition(
        array $row,
        RepresentativeAddressAssignment $assignment,
    ): void {
        if ((int) $row['family_address_id'] !== $assignment->familyAddressId()->value()
            || (int) $row['representative_id'] !== $assignment->representativeId()->value()
            || (string) $row['started_at'] !== $this->formatTimestamp($assignment->startedAt())
        ) {
            throw new RuntimeException('RepresentativeAddressAssignment immutable fields cannot be changed.');
        }
        $this->assertEndTransition($row['ended_at'], $assignment->endedAt(), 'RepresentativeAddressAssignment');
    }

    /** @param array<string, mixed> $row */
    private function assertStudentAddressTransition(array $row, StudentAddressAssignment $assignment): void
    {
        if ((int) $row['family_address_id'] !== $assignment->familyAddressId()->value()
            || (int) $row['student_id'] !== $assignment->studentId()->value()
            || (string) $row['started_at'] !== $this->formatTimestamp($assignment->startedAt())
        ) {
            throw new RuntimeException('StudentAddressAssignment immutable fields cannot be changed.');
        }
        $this->assertEndTransition($row['ended_at'], $assignment->endedAt(), 'StudentAddressAssignment');
    }

    /** @param array<string, mixed> $row */
    private function assertEmergencyContactTransition(array $row, EmergencyContactAssignment $assignment): void
    {
        if ((int) $row['family_emergency_contact_id'] !== $assignment->familyEmergencyContactId()->value()
            || (int) $row['student_id'] !== $assignment->studentId()->value()
            || ($row['priority'] === null ? null : (int) $row['priority']) !== $assignment->priority()?->value()
            || (string) $row['started_at'] !== $this->formatTimestamp($assignment->startedAt())
        ) {
            throw new RuntimeException('EmergencyContactAssignment immutable fields cannot be changed.');
        }
        $this->assertEndTransition($row['ended_at'], $assignment->endedAt(), 'EmergencyContactAssignment');
    }

    /** @param array<string, mixed> $row */
    private function assertAuthorizedPickupTransition(array $row, AuthorizedPickupAssignment $assignment): void
    {
        if ((int) $row['family_authorized_pickup_id'] !== $assignment->familyAuthorizedPickupId()->value()
            || (int) $row['student_id'] !== $assignment->studentId()->value()
            || (string) $row['started_at'] !== $this->formatTimestamp($assignment->startedAt())
        ) {
            throw new RuntimeException('AuthorizedPickupAssignment immutable fields cannot be changed.');
        }
        $this->assertEndTransition($row['ended_at'], $assignment->endedAt(), 'AuthorizedPickupAssignment');
    }

    /**
     * @param list<FamilyRepresentative> $memberships
     * @param list<array<string, mixed>> $persistedRows
     */
    private function validateRepresentativeSynchronization(
        FamilyId $familyId,
        array $memberships,
        array $persistedRows,
    ): void {
        $persistedById = $this->rowsById($persistedRows, 'FamilyRepresentative');
        $aggregateIds = [];

        foreach ($memberships as $membership) {
            $id = $membership->id();
            if ($id === null) {
                continue;
            }

            $value = $id->value();
            if (isset($aggregateIds[$value])) {
                throw new RuntimeException('Family contains a duplicate persisted FamilyRepresentative identity.');
            }
            $aggregateIds[$value] = true;

            $row = $persistedById[$value] ?? $this->findRepresentativeRow($id);
            if ($row === null) {
                throw new RuntimeException('Family contains an unknown FamilyRepresentative identity.');
            }
            if ((int) $row['family_id'] !== $familyId->value()) {
                throw new RuntimeException('FamilyRepresentative belongs to another Family.');
            }

            $this->assertRepresentativeTransition($row, $membership);
        }

        $this->assertNoPersistedMembershipOmitted(
            array_keys($persistedById),
            $aggregateIds,
            'FamilyRepresentative',
        );
    }

    /**
     * @param list<FamilyStudent> $memberships
     * @param list<array<string, mixed>> $persistedRows
     */
    private function validateStudentSynchronization(
        FamilyId $familyId,
        array $memberships,
        array $persistedRows,
    ): void {
        $persistedById = $this->rowsById($persistedRows, 'FamilyStudent');
        $aggregateIds = [];

        foreach ($memberships as $membership) {
            $id = $membership->id();
            if ($id === null) {
                continue;
            }

            $value = $id->value();
            if (isset($aggregateIds[$value])) {
                throw new RuntimeException('Family contains a duplicate persisted FamilyStudent identity.');
            }
            $aggregateIds[$value] = true;

            $row = $persistedById[$value] ?? $this->findStudentRow($id);
            if ($row === null) {
                throw new RuntimeException('Family contains an unknown FamilyStudent identity.');
            }
            if ((int) $row['family_id'] !== $familyId->value()) {
                throw new RuntimeException('FamilyStudent belongs to another Family.');
            }

            $this->assertStudentTransition($row, $membership);
        }

        $this->assertNoPersistedMembershipOmitted(
            array_keys($persistedById),
            $aggregateIds,
            'FamilyStudent',
        );
    }

    /** @param array<string, mixed> $row */
    private function assertRepresentativeTransition(array $row, FamilyRepresentative $membership): void
    {
        if ((int) $row['representative_id'] !== $membership->representativeId()->value()
            || (int) $row['relationship_type_id'] !== $membership->relationshipTypeId()->value()
            || (bool) $row['is_primary'] !== $membership->isPrimary()
            || (string) $row['started_at'] !== $this->formatTimestamp($membership->startedAt())
        ) {
            throw new RuntimeException('FamilyRepresentative immutable fields cannot be changed.');
        }

        $this->assertEndTransition($row['ended_at'], $membership->endedAt(), 'FamilyRepresentative');
    }

    /** @param array<string, mixed> $row */
    private function assertStudentTransition(array $row, FamilyStudent $membership): void
    {
        if ((int) $row['student_id'] !== $membership->studentId()->value()
            || (string) $row['started_at'] !== $this->formatTimestamp($membership->startedAt())
        ) {
            throw new RuntimeException('FamilyStudent immutable fields cannot be changed.');
        }

        $this->assertEndTransition($row['ended_at'], $membership->endedAt(), 'FamilyStudent');
    }

    private function assertEndTransition(mixed $persisted, ?DateTimeImmutable $requested, string $entity): void
    {
        $persistedValue = $persisted === null ? null : (string) $persisted;
        $requestedValue = $this->formatNullableTimestamp($requested);

        if ($persistedValue !== null && $persistedValue !== $requestedValue) {
            throw new RuntimeException($entity . ' ended_at cannot be changed or reactivated.');
        }
    }

    /**
     * @param list<int> $persistedIds
     * @param array<int, true> $aggregateIds
     */
    private function assertNoPersistedMembershipOmitted(
        array $persistedIds,
        array $aggregateIds,
        string $entity,
    ): void {
        foreach ($persistedIds as $id) {
            if (!isset($aggregateIds[$id])) {
                throw new RuntimeException($entity . ' persisted history cannot be omitted from Family.');
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function rowsById(array $rows, string $entity): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            if (isset($indexed[$id])) {
                throw new RuntimeException($entity . ' persistence returned a duplicate identity.');
            }
            $indexed[$id] = $row;
        }

        return $indexed;
    }

    private function resolveStatusId(FamilyStatus $status): int
    {
        $statement = $this->connection->prepare(
            'SELECT s.id FROM statuses s '
            . 'INNER JOIN status_types st ON st.id = s.status_type_id '
            . 'WHERE st.code = :statusType AND s.code = :statusCode'
        );
        $statement->execute([
            ':statusType' => self::STATUS_TYPE,
            ':statusCode' => $status->value,
        ]);
        $rows = $statement->fetchAll(PDO::FETCH_COLUMN);

        if (count($rows) !== 1) {
            throw new RuntimeException('Family status must resolve to exactly one GENERAL_STATUS row.');
        }

        return (int) $rows[0];
    }

    private function resolveResourceStatusId(FamilyResourceStatus $status): int
    {
        $statement = $this->connection->prepare(
            'SELECT s.id FROM statuses s INNER JOIN status_types st ON st.id = s.status_type_id '
            . 'WHERE st.code = :statusType AND s.code = :statusCode'
        );
        $statement->execute([':statusType' => self::STATUS_TYPE, ':statusCode' => $status->value]);
        $rows = $statement->fetchAll(PDO::FETCH_COLUMN);
        if (count($rows) !== 1) {
            throw new RuntimeException('Family resource status must resolve to exactly one GENERAL_STATUS row.');
        }

        return (int) $rows[0];
    }

    /** @return array<string, mixed>|null */
    private function findFamilyRow(FamilyId $id): ?array
    {
        $statement = $this->connection->prepare(
            $this->familySelectSql() . ' WHERE f.id = :id LIMIT 1'
        );
        $statement->execute([':id' => $id->value()]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** @return list<array<string, mixed>> */
    private function representativeRows(FamilyId $familyId): array
    {
        $statement = $this->connection->prepare(
            'SELECT fr.id, fr.family_id, fr.representative_id, fr.relationship_type_id, '
            . 'fr.is_primary, fr.started_at, fr.ended_at FROM family_representatives fr '
            . 'WHERE fr.family_id = :familyId ORDER BY fr.started_at, fr.id'
        );
        $statement->execute([':familyId' => $familyId->value()]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string, mixed>> */
    private function studentRows(FamilyId $familyId): array
    {
        $statement = $this->connection->prepare(
            'SELECT fs.id, fs.family_id, fs.student_id, fs.started_at, fs.ended_at '
            . 'FROM family_students fs WHERE fs.family_id = :familyId '
            . 'ORDER BY fs.started_at, fs.id'
        );
        $statement->execute([':familyId' => $familyId->value()]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string, mixed>> */
    private function addressRows(FamilyId $familyId): array
    {
        return $this->fetchFamilyRows(
            'SELECT a.id, a.family_id, a.label, a.main_street, a.street_number, a.secondary_street, '
            . 'a.sector, a.reference, a.latitude, a.longitude, a.status_id, s.code AS status_code, '
            . 'st.code AS status_type_code FROM family_addresses a '
            . 'INNER JOIN statuses s ON s.id = a.status_id '
            . 'INNER JOIN status_types st ON st.id = s.status_type_id '
            . 'WHERE a.family_id = :familyId ORDER BY a.id',
            $familyId,
        );
    }

    /** @return list<array<string, mixed>> */
    private function representativeAddressAssignmentRows(FamilyId $familyId): array
    {
        return $this->fetchFamilyRows(
            'SELECT id, family_id, family_address_id, representative_id, started_at, ended_at '
            . 'FROM representative_address_assignments WHERE family_id = :familyId ORDER BY started_at, id',
            $familyId,
        );
    }

    /** @return list<array<string, mixed>> */
    private function studentAddressAssignmentRows(FamilyId $familyId): array
    {
        return $this->fetchFamilyRows(
            'SELECT id, family_id, family_address_id, student_id, started_at, ended_at '
            . 'FROM student_address_assignments WHERE family_id = :familyId ORDER BY started_at, id',
            $familyId,
        );
    }

    /** @return list<array<string, mixed>> */
    private function emergencyContactRows(FamilyId $familyId): array
    {
        return $this->fetchFamilyRows(
            'SELECT c.id, c.family_id, c.names, c.relationship_type_id, c.mobile_phone, c.phone, '
            . 'c.email, c.observations, c.status_id, s.code AS status_code, st.code AS status_type_code '
            . 'FROM family_emergency_contacts c INNER JOIN statuses s ON s.id = c.status_id '
            . 'INNER JOIN status_types st ON st.id = s.status_type_id '
            . 'WHERE c.family_id = :familyId ORDER BY c.id',
            $familyId,
        );
    }

    /** @return list<array<string, mixed>> */
    private function emergencyContactAssignmentRows(FamilyId $familyId): array
    {
        return $this->fetchFamilyRows(
            'SELECT id, family_id, family_emergency_contact_id, student_id, priority, started_at, ended_at '
            . 'FROM emergency_contact_assignments WHERE family_id = :familyId ORDER BY started_at, id',
            $familyId,
        );
    }

    /** @return list<array<string, mixed>> */
    private function authorizedPickupRows(FamilyId $familyId): array
    {
        return $this->fetchFamilyRows(
            'SELECT p.id, p.family_id, p.names, p.relationship_type_id, p.mobile_phone, p.phone, '
            . 'p.document_type_id, p.document_number, p.observations, p.status_id, '
            . 's.code AS status_code, st.code AS status_type_code FROM family_authorized_pickups p '
            . 'INNER JOIN statuses s ON s.id = p.status_id INNER JOIN status_types st ON st.id = s.status_type_id '
            . 'WHERE p.family_id = :familyId ORDER BY p.id',
            $familyId,
        );
    }

    /** @return list<array<string, mixed>> */
    private function authorizedPickupAssignmentRows(FamilyId $familyId): array
    {
        return $this->fetchFamilyRows(
            'SELECT id, family_id, family_authorized_pickup_id, student_id, started_at, ended_at '
            . 'FROM authorized_pickup_assignments WHERE family_id = :familyId ORDER BY started_at, id',
            $familyId,
        );
    }

    /** @return list<array<string, mixed>> */
    private function fetchFamilyRows(string $sql, FamilyId $familyId): array
    {
        $statement = $this->connection->prepare($sql);
        $statement->execute([':familyId' => $familyId->value()]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed>|null */
    private function findRepresentativeRow(FamilyRepresentativeId $id): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT fr.id, fr.family_id, fr.representative_id, fr.relationship_type_id, '
            . 'fr.is_primary, fr.started_at, fr.ended_at FROM family_representatives fr '
            . 'WHERE fr.id = :id LIMIT 1'
        );
        $statement->execute([':id' => $id->value()]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    private function findStudentRow(FamilyStudentId $id): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT fs.id, fs.family_id, fs.student_id, fs.started_at, fs.ended_at '
            . 'FROM family_students fs WHERE fs.id = :id LIMIT 1'
        );
        $statement->execute([':id' => $id->value()]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    private function findAddressRow(FamilyAddressId $id): ?array
    {
        return $this->fetchRowById(
            'SELECT a.id, a.family_id, a.label, a.main_street, a.street_number, a.secondary_street, '
            . 'a.sector, a.reference, a.latitude, a.longitude, a.status_id, s.code AS status_code, '
            . 'st.code AS status_type_code FROM family_addresses a '
            . 'INNER JOIN statuses s ON s.id = a.status_id INNER JOIN status_types st ON st.id = s.status_type_id '
            . 'WHERE a.id = :id LIMIT 1',
            $id->value(),
        );
    }

    /** @return array<string, mixed>|null */
    private function findEmergencyContactRow(FamilyEmergencyContactId $id): ?array
    {
        return $this->fetchRowById(
            'SELECT c.id, c.family_id, c.names, c.relationship_type_id, c.mobile_phone, c.phone, '
            . 'c.email, c.observations, c.status_id, s.code AS status_code, st.code AS status_type_code '
            . 'FROM family_emergency_contacts c INNER JOIN statuses s ON s.id = c.status_id '
            . 'INNER JOIN status_types st ON st.id = s.status_type_id WHERE c.id = :id LIMIT 1',
            $id->value(),
        );
    }

    /** @return array<string, mixed>|null */
    private function findAuthorizedPickupRow(FamilyAuthorizedPickupId $id): ?array
    {
        return $this->fetchRowById(
            'SELECT p.id, p.family_id, p.names, p.relationship_type_id, p.mobile_phone, p.phone, '
            . 'p.document_type_id, p.document_number, p.observations, p.status_id, s.code AS status_code, '
            . 'st.code AS status_type_code FROM family_authorized_pickups p '
            . 'INNER JOIN statuses s ON s.id = p.status_id INNER JOIN status_types st ON st.id = s.status_type_id '
            . 'WHERE p.id = :id LIMIT 1',
            $id->value(),
        );
    }

    /** @return array<string, mixed>|null */
    private function findRepresentativeAddressAssignmentRow(RepresentativeAddressAssignmentId $id): ?array
    {
        return $this->fetchRowById(
            'SELECT id, family_id, family_address_id, representative_id, started_at, ended_at '
            . 'FROM representative_address_assignments WHERE id = :id LIMIT 1',
            $id->value(),
        );
    }

    /** @return array<string, mixed>|null */
    private function findStudentAddressAssignmentRow(StudentAddressAssignmentId $id): ?array
    {
        return $this->fetchRowById(
            'SELECT id, family_id, family_address_id, student_id, started_at, ended_at '
            . 'FROM student_address_assignments WHERE id = :id LIMIT 1',
            $id->value(),
        );
    }

    /** @return array<string, mixed>|null */
    private function findEmergencyContactAssignmentRow(EmergencyContactAssignmentId $id): ?array
    {
        return $this->fetchRowById(
            'SELECT id, family_id, family_emergency_contact_id, student_id, priority, started_at, ended_at '
            . 'FROM emergency_contact_assignments WHERE id = :id LIMIT 1',
            $id->value(),
        );
    }

    /** @return array<string, mixed>|null */
    private function findAuthorizedPickupAssignmentRow(AuthorizedPickupAssignmentId $id): ?array
    {
        return $this->fetchRowById(
            'SELECT id, family_id, family_authorized_pickup_id, student_id, started_at, ended_at '
            . 'FROM authorized_pickup_assignments WHERE id = :id LIMIT 1',
            $id->value(),
        );
    }

    /** @return array<string, mixed>|null */
    private function fetchRowById(string $sql, int $id): ?array
    {
        $statement = $this->connection->prepare($sql);
        $statement->execute([':id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** @param array<string, mixed> $row */
    private function mapFamily(array $row): Family
    {
        try {
            if ((string) $row['status_type_code'] !== self::STATUS_TYPE) {
                throw new RuntimeException('Family status does not belong to GENERAL_STATUS.');
            }

            $status = FamilyStatus::tryFrom((string) $row['status_code']);
            if ($status === null) {
                throw new RuntimeException('Family has an unsupported GENERAL_STATUS value.');
            }

            $familyId = new FamilyId((int) $row['id']);
            $representatives = array_map(
                fn (array $membership): FamilyRepresentative => $this->mapRepresentative($membership),
                $this->representativeRows($familyId),
            );
            $students = array_map(
                fn (array $membership): FamilyStudent => $this->mapStudent($membership),
                $this->studentRows($familyId),
            );
            $addresses = array_map(
                fn (array $resource): FamilyAddress => $this->mapAddress($resource),
                $this->addressRows($familyId),
            );
            $representativeAddressAssignments = array_map(
                fn (array $assignment): RepresentativeAddressAssignment =>
                    $this->mapRepresentativeAddressAssignment($assignment),
                $this->representativeAddressAssignmentRows($familyId),
            );
            $studentAddressAssignments = array_map(
                fn (array $assignment): StudentAddressAssignment => $this->mapStudentAddressAssignment($assignment),
                $this->studentAddressAssignmentRows($familyId),
            );
            $emergencyContacts = array_map(
                fn (array $resource): FamilyEmergencyContact => $this->mapEmergencyContact($resource),
                $this->emergencyContactRows($familyId),
            );
            $emergencyContactAssignments = array_map(
                fn (array $assignment): EmergencyContactAssignment =>
                    $this->mapEmergencyContactAssignment($assignment),
                $this->emergencyContactAssignmentRows($familyId),
            );
            $authorizedPickups = array_map(
                fn (array $resource): FamilyAuthorizedPickup => $this->mapAuthorizedPickup($resource),
                $this->authorizedPickupRows($familyId),
            );
            $authorizedPickupAssignments = array_map(
                fn (array $assignment): AuthorizedPickupAssignment =>
                    $this->mapAuthorizedPickupAssignment($assignment),
                $this->authorizedPickupAssignmentRows($familyId),
            );

            return Family::reconstitute(
                $familyId,
                new DisplayName((string) $row['display_name']),
                $status,
                $representatives,
                $students,
                $addresses,
                $representativeAddressAssignments,
                $studentAddressAssignments,
                $emergencyContacts,
                $emergencyContactAssignments,
                $authorizedPickups,
                $authorizedPickupAssignments,
            );
        } catch (InvalidFamilyState $exception) {
            throw new RuntimeException('Persisted Family violates Domain invariants.', previous: $exception);
        }
    }

    /** @param array<string, mixed> $row */
    private function mapRepresentative(array $row): FamilyRepresentative
    {
        return new FamilyRepresentative(
            new FamilyRepresentativeId((int) $row['id']),
            new RepresentativeId((int) $row['representative_id']),
            new RelationshipTypeId((int) $row['relationship_type_id']),
            (bool) $row['is_primary'],
            $this->parseTimestamp((string) $row['started_at'], 'FamilyRepresentative started_at'),
            $row['ended_at'] === null
                ? null
                : $this->parseTimestamp((string) $row['ended_at'], 'FamilyRepresentative ended_at'),
        );
    }

    /** @param array<string, mixed> $row */
    private function mapStudent(array $row): FamilyStudent
    {
        return new FamilyStudent(
            new FamilyStudentId((int) $row['id']),
            new StudentId((int) $row['student_id']),
            $this->parseTimestamp((string) $row['started_at'], 'FamilyStudent started_at'),
            $row['ended_at'] === null
                ? null
                : $this->parseTimestamp((string) $row['ended_at'], 'FamilyStudent ended_at'),
        );
    }

    /** @param array<string, mixed> $row */
    private function mapAddress(array $row): FamilyAddress
    {
        $geolocation = $row['latitude'] === null && $row['longitude'] === null
            ? null
            : new Geolocation((string) $row['latitude'], (string) $row['longitude']);

        return new FamilyAddress(
            new FamilyAddressId((int) $row['id']),
            new AddressLabel((string) $row['label']),
            new Address(
                (string) $row['main_street'],
                $row['street_number'] === null ? null : (string) $row['street_number'],
                $row['secondary_street'] === null ? null : (string) $row['secondary_street'],
                $row['sector'] === null ? null : (string) $row['sector'],
                $row['reference'] === null ? null : (string) $row['reference'],
                $geolocation,
            ),
            $this->mapResourceStatus($row, 'FamilyAddress'),
        );
    }

    /** @param array<string, mixed> $row */
    private function mapRepresentativeAddressAssignment(array $row): RepresentativeAddressAssignment
    {
        return new RepresentativeAddressAssignment(
            new RepresentativeAddressAssignmentId((int) $row['id']),
            new FamilyAddressId((int) $row['family_address_id']),
            new RepresentativeId((int) $row['representative_id']),
            $this->parseTimestamp((string) $row['started_at'], 'RepresentativeAddressAssignment started_at'),
            $row['ended_at'] === null ? null
                : $this->parseTimestamp((string) $row['ended_at'], 'RepresentativeAddressAssignment ended_at'),
        );
    }

    /** @param array<string, mixed> $row */
    private function mapStudentAddressAssignment(array $row): StudentAddressAssignment
    {
        return new StudentAddressAssignment(
            new StudentAddressAssignmentId((int) $row['id']),
            new FamilyAddressId((int) $row['family_address_id']),
            new StudentId((int) $row['student_id']),
            $this->parseTimestamp((string) $row['started_at'], 'StudentAddressAssignment started_at'),
            $row['ended_at'] === null ? null
                : $this->parseTimestamp((string) $row['ended_at'], 'StudentAddressAssignment ended_at'),
        );
    }

    /** @param array<string, mixed> $row */
    private function mapEmergencyContact(array $row): FamilyEmergencyContact
    {
        return new FamilyEmergencyContact(
            new FamilyEmergencyContactId((int) $row['id']),
            new FamilyResourceName((string) $row['names']),
            new RelationshipTypeId((int) $row['relationship_type_id']),
            new EmergencyContactInformation(
                (string) $row['mobile_phone'],
                $row['phone'] === null ? null : (string) $row['phone'],
                $row['email'] === null ? null : (string) $row['email'],
                $row['observations'] === null ? null : (string) $row['observations'],
            ),
            $this->mapResourceStatus($row, 'FamilyEmergencyContact'),
        );
    }

    /** @param array<string, mixed> $row */
    private function mapEmergencyContactAssignment(array $row): EmergencyContactAssignment
    {
        return new EmergencyContactAssignment(
            new EmergencyContactAssignmentId((int) $row['id']),
            new FamilyEmergencyContactId((int) $row['family_emergency_contact_id']),
            new StudentId((int) $row['student_id']),
            $row['priority'] === null ? null : new EmergencyContactPriority((int) $row['priority']),
            $this->parseTimestamp((string) $row['started_at'], 'EmergencyContactAssignment started_at'),
            $row['ended_at'] === null ? null
                : $this->parseTimestamp((string) $row['ended_at'], 'EmergencyContactAssignment ended_at'),
        );
    }

    /** @param array<string, mixed> $row */
    private function mapAuthorizedPickup(array $row): FamilyAuthorizedPickup
    {
        $documentTypeId = $row['document_type_id'] === null
            ? null
            : new DocumentTypeId((int) $row['document_type_id']);

        return new FamilyAuthorizedPickup(
            new FamilyAuthorizedPickupId((int) $row['id']),
            new FamilyResourceName((string) $row['names']),
            new RelationshipTypeId((int) $row['relationship_type_id']),
            new AuthorizedPickupInformation(
                (string) $row['mobile_phone'],
                $row['phone'] === null ? null : (string) $row['phone'],
                $row['observations'] === null ? null : (string) $row['observations'],
            ),
            PickupIdentification::fromPair(
                $documentTypeId,
                $row['document_number'] === null ? null : (string) $row['document_number'],
            ),
            $this->mapResourceStatus($row, 'FamilyAuthorizedPickup'),
        );
    }

    /** @param array<string, mixed> $row */
    private function mapAuthorizedPickupAssignment(array $row): AuthorizedPickupAssignment
    {
        return new AuthorizedPickupAssignment(
            new AuthorizedPickupAssignmentId((int) $row['id']),
            new FamilyAuthorizedPickupId((int) $row['family_authorized_pickup_id']),
            new StudentId((int) $row['student_id']),
            $this->parseTimestamp((string) $row['started_at'], 'AuthorizedPickupAssignment started_at'),
            $row['ended_at'] === null ? null
                : $this->parseTimestamp((string) $row['ended_at'], 'AuthorizedPickupAssignment ended_at'),
        );
    }

    /** @param array<string, mixed> $row */
    private function mapResourceStatus(array $row, string $entity): FamilyResourceStatus
    {
        if ((string) $row['status_type_code'] !== self::STATUS_TYPE) {
            throw new RuntimeException($entity . ' status does not belong to GENERAL_STATUS.');
        }
        $status = FamilyResourceStatus::tryFrom((string) $row['status_code']);
        if ($status === null) {
            throw new RuntimeException($entity . ' has an unsupported GENERAL_STATUS value.');
        }

        return $status;
    }

    private function parseTimestamp(string $value, string $field): DateTimeImmutable
    {
        $timezone = new DateTimeZone('UTC');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $timezone);
        $errors = DateTimeImmutable::getLastErrors();

        if ($date === false
            || $date->format('Y-m-d H:i:s') !== $value
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        ) {
            throw new RuntimeException($field . ' has an invalid persisted UTC timestamp.');
        }

        return $date;
    }

    /** @param array<string, mixed> $row */
    private function sameFamilyRow(array $row, Family $family): bool
    {
        return $family->id() !== null
            && (int) $row['id'] === $family->id()?->value()
            && (string) $row['display_name'] === $family->displayName()->value()
            && (string) $row['status_type_code'] === self::STATUS_TYPE
            && (string) $row['status_code'] === $family->status()->value;
    }

    /** @param array<string, mixed> $row */
    private function sameRepresentativeRow(array $row, FamilyRepresentative $membership): bool
    {
        return $membership->id() !== null
            && (int) $row['id'] === $membership->id()?->value()
            && (int) $row['representative_id'] === $membership->representativeId()->value()
            && (int) $row['relationship_type_id'] === $membership->relationshipTypeId()->value()
            && (bool) $row['is_primary'] === $membership->isPrimary()
            && (string) $row['started_at'] === $this->formatTimestamp($membership->startedAt())
            && ($row['ended_at'] === null ? null : (string) $row['ended_at'])
                === $this->formatNullableTimestamp($membership->endedAt());
    }

    /** @param array<string, mixed> $row */
    private function sameStudentRow(array $row, FamilyStudent $membership): bool
    {
        return $membership->id() !== null
            && (int) $row['id'] === $membership->id()?->value()
            && (int) $row['student_id'] === $membership->studentId()->value()
            && (string) $row['started_at'] === $this->formatTimestamp($membership->startedAt())
            && ($row['ended_at'] === null ? null : (string) $row['ended_at'])
                === $this->formatNullableTimestamp($membership->endedAt());
    }

    /** @param array<string, mixed> $row */
    private function sameAddressRow(array $row, FamilyId $familyId, FamilyAddress $resource): bool
    {
        $geolocation = $resource->address()->geolocation();

        return (int) $row['family_id'] === $familyId->value()
            && (string) $row['label'] === $resource->label()->value()
            && (string) $row['main_street'] === $resource->address()->mainStreet()
            && $this->nullableString($row['street_number']) === $resource->address()->streetNumber()
            && $this->nullableString($row['secondary_street']) === $resource->address()->secondaryStreet()
            && $this->nullableString($row['sector']) === $resource->address()->sector()
            && $this->nullableString($row['reference']) === $resource->address()->reference()
            && $this->sameGeolocation($row['latitude'], $row['longitude'], $geolocation)
            && (string) $row['status_type_code'] === self::STATUS_TYPE
            && (string) $row['status_code'] === $resource->status()->value;
    }

    /** @param array<string, mixed> $row */
    private function sameEmergencyContactRow(
        array $row,
        FamilyId $familyId,
        FamilyEmergencyContact $resource,
    ): bool {
        $contact = $resource->contactInformation();

        return (int) $row['family_id'] === $familyId->value()
            && (string) $row['names'] === $resource->names()->value()
            && (int) $row['relationship_type_id'] === $resource->relationshipTypeId()->value()
            && (string) $row['mobile_phone'] === $contact->mobilePhone()
            && $this->nullableString($row['phone']) === $contact->phone()
            && $this->nullableString($row['email']) === $contact->email()
            && $this->nullableString($row['observations']) === $contact->observations()
            && (string) $row['status_type_code'] === self::STATUS_TYPE
            && (string) $row['status_code'] === $resource->status()->value;
    }

    /** @param array<string, mixed> $row */
    private function sameAuthorizedPickupRow(
        array $row,
        FamilyId $familyId,
        FamilyAuthorizedPickup $resource,
    ): bool {
        $contact = $resource->contactInformation();
        $identification = $resource->identification();

        return (int) $row['family_id'] === $familyId->value()
            && (string) $row['names'] === $resource->names()->value()
            && (int) $row['relationship_type_id'] === $resource->relationshipTypeId()->value()
            && (string) $row['mobile_phone'] === $contact->mobilePhone()
            && $this->nullableString($row['phone']) === $contact->phone()
            && ($row['document_type_id'] === null ? null : (int) $row['document_type_id'])
                === $identification?->documentTypeId()->value()
            && $this->nullableString($row['document_number']) === $identification?->documentNumber()
            && $this->nullableString($row['observations']) === $contact->observations()
            && (string) $row['status_type_code'] === self::STATUS_TYPE
            && (string) $row['status_code'] === $resource->status()->value;
    }

    /** @param array<string, mixed> $row */
    private function sameRepresentativeAddressAssignmentRow(
        array $row,
        FamilyId $familyId,
        RepresentativeAddressAssignment $assignment,
    ): bool {
        return (int) $row['family_id'] === $familyId->value()
            && (int) $row['family_address_id'] === $assignment->familyAddressId()->value()
            && (int) $row['representative_id'] === $assignment->representativeId()->value()
            && (string) $row['started_at'] === $this->formatTimestamp($assignment->startedAt())
            && $this->nullableString($row['ended_at']) === $this->formatNullableTimestamp($assignment->endedAt());
    }

    /** @param array<string, mixed> $row */
    private function sameStudentAddressAssignmentRow(
        array $row,
        FamilyId $familyId,
        StudentAddressAssignment $assignment,
    ): bool {
        return (int) $row['family_id'] === $familyId->value()
            && (int) $row['family_address_id'] === $assignment->familyAddressId()->value()
            && (int) $row['student_id'] === $assignment->studentId()->value()
            && (string) $row['started_at'] === $this->formatTimestamp($assignment->startedAt())
            && $this->nullableString($row['ended_at']) === $this->formatNullableTimestamp($assignment->endedAt());
    }

    /** @param array<string, mixed> $row */
    private function sameEmergencyContactAssignmentRow(
        array $row,
        FamilyId $familyId,
        EmergencyContactAssignment $assignment,
    ): bool {
        return (int) $row['family_id'] === $familyId->value()
            && (int) $row['family_emergency_contact_id'] === $assignment->familyEmergencyContactId()->value()
            && (int) $row['student_id'] === $assignment->studentId()->value()
            && ($row['priority'] === null ? null : (int) $row['priority']) === $assignment->priority()?->value()
            && (string) $row['started_at'] === $this->formatTimestamp($assignment->startedAt())
            && $this->nullableString($row['ended_at']) === $this->formatNullableTimestamp($assignment->endedAt());
    }

    /** @param array<string, mixed> $row */
    private function sameAuthorizedPickupAssignmentRow(
        array $row,
        FamilyId $familyId,
        AuthorizedPickupAssignment $assignment,
    ): bool {
        return (int) $row['family_id'] === $familyId->value()
            && (int) $row['family_authorized_pickup_id'] === $assignment->familyAuthorizedPickupId()->value()
            && (int) $row['student_id'] === $assignment->studentId()->value()
            && (string) $row['started_at'] === $this->formatTimestamp($assignment->startedAt())
            && $this->nullableString($row['ended_at']) === $this->formatNullableTimestamp($assignment->endedAt());
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private function sameGeolocation(mixed $latitude, mixed $longitude, ?Geolocation $expected): bool
    {
        if ($latitude === null || $longitude === null) {
            return $latitude === null && $longitude === null && $expected === null;
        }
        if ($expected === null) {
            return false;
        }

        return (new Geolocation((string) $latitude, (string) $longitude))->equals($expected);
    }

    private function formatTimestamp(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function formatNullableTimestamp(?DateTimeImmutable $value): ?string
    {
        return $value === null ? null : $this->formatTimestamp($value);
    }

    private function familySelectSql(): string
    {
        return 'SELECT f.id, f.display_name, f.status_id, s.code AS status_code, '
            . 'st.code AS status_type_code FROM families f '
            . 'INNER JOIN statuses s ON s.id = f.status_id '
            . 'INNER JOIN status_types st ON st.id = s.status_type_id';
    }

    private function persistedPositiveInt(mixed $value, string $label): int
    {
        if (is_int($value)) {
            $integer = $value;
        } elseif (is_string($value) && preg_match('/^[0-9]+$/D', $value) === 1) {
            $integer = (int) $value;
        } else {
            throw new RuntimeException($label . ' must be a persisted positive integer.');
        }
        if ($integer <= 0) {
            throw new RuntimeException($label . ' must be a persisted positive integer.');
        }

        return $integer;
    }

    private function generatedId(string $entity): int
    {
        $id = (int) $this->connection->lastInsertId();
        if ($id <= 0) {
            throw new RuntimeException($entity . ' insert did not produce a positive database identity.');
        }

        return $id;
    }

    private function requireNewIdentity(?object $id, string $entity): void
    {
        if ($id !== null) {
            throw new RuntimeException('A new Family cannot contain a persisted ' . $entity . '.');
        }
    }

    private function requiredPersistedId(?object $id, string $entity): int
    {
        if ($id === null || !method_exists($id, 'value')) {
            throw new RuntimeException($entity . ' without identity cannot be updated.');
        }

        return $id->value();
    }

    private function requireSingleInsertedRow(int $affectedRows, string $entity): void
    {
        if ($affectedRows !== 1) {
            throw new RuntimeException($entity . ' insert did not affect exactly one row.');
        }
    }

    private function requireZeroOrOneUpdatedRow(int $affectedRows, string $entity): void
    {
        if ($affectedRows !== 0 && $affectedRows !== 1) {
            throw new RuntimeException($entity . ' update did not affect zero or one row.');
        }
    }

    private function requirePersistedFamily(FamilyId $id, string $message): Family
    {
        $family = $this->findById($id);
        if ($family === null) {
            throw new RuntimeException($message);
        }

        return $family;
    }
}
