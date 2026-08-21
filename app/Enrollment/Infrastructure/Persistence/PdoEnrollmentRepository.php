<?php

declare(strict_types=1);

namespace App\Enrollment\Infrastructure\Persistence;

use App\Enrollment\Domain\Enrollment;
use App\Enrollment\Domain\EnrollmentRepository;
use App\Enrollment\Domain\EnrollmentStatus;
use App\Enrollment\Domain\EnrollmentSubmissionSnapshot;
use App\Enrollment\Domain\SubmittedAddressSnapshot;
use App\Enrollment\Domain\SubmittedAuthorizedPickupSnapshot;
use App\Enrollment\Domain\SubmittedEmergencyContactSnapshot;
use App\Enrollment\Domain\ValueObject\AcademicPeriodId;
use App\Enrollment\Domain\ValueObject\AcademicPlacement;
use App\Enrollment\Domain\ValueObject\BillingInformation;
use App\Enrollment\Domain\ValueObject\EnrollmentId;
use App\Enrollment\Domain\ValueObject\EnrollmentSubmissionSnapshotId;
use App\Enrollment\Domain\ValueObject\FamilyId;
use App\Enrollment\Domain\ValueObject\Geolocation;
use App\Enrollment\Domain\ValueObject\GradeId;
use App\Enrollment\Domain\ValueObject\IdentificationTypeId;
use App\Enrollment\Domain\ValueObject\MedicalInformation;
use App\Enrollment\Domain\ValueObject\RepresentativeId;
use App\Enrollment\Domain\ValueObject\SectionId;
use App\Enrollment\Domain\ValueObject\StudentId;
use App\Enrollment\Domain\ValueObject\SubmittedAddressSnapshotId;
use App\Enrollment\Domain\ValueObject\SubmittedAuthorizedPickupSnapshotId;
use App\Enrollment\Domain\ValueObject\SubmittedEmergencyContactSnapshotId;
use App\Enrollment\Domain\ValueObject\TransportInformation;
use Core\Database\ConnectionManager;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

final class PdoEnrollmentRepository implements EnrollmentRepository
{
    private const STATUS_TYPE = 'ENROLLMENT_STATUS';

    private PDO $connection;

    public function __construct(ConnectionManager $connectionManager)
    {
        $this->connection = $connectionManager->connection();
    }

    public function findById(EnrollmentId $id): ?Enrollment
    {
        $statement = $this->connection->prepare($this->selectSql() . ' WHERE e.id = :id');
        $statement->execute([':id' => $id->value()]);

        return $this->mapUniqueEnrollment($statement, 'Enrollment identity resolved more than one row.');
    }

    public function findByIdForUpdate(EnrollmentId $id): ?Enrollment
    {
        if (!$this->connection->inTransaction()) {
            throw new RuntimeException('Enrollment row lock requires an active transaction.');
        }

        $sql = 'SELECT id FROM enrollments WHERE id = :id';
        if ($this->connection->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            $sql .= ' FOR UPDATE';
        }
        $statement = $this->connection->prepare($sql);
        $statement->execute([':id' => $id->value()]);
        $rows = $statement->fetchAll(PDO::FETCH_COLUMN);
        if (count($rows) > 1) {
            throw new RuntimeException('Enrollment identity resolved more than one root row for update.');
        }
        if ($rows === []) {
            return null;
        }
        if ($this->positiveInt($rows[0], 'Locked Enrollment id') !== $id->value()) {
            throw new RuntimeException('Enrollment row lock returned an incoherent identity.');
        }

        return $this->findById($id);
    }

    public function findByStudentAndAcademicPeriod(
        StudentId $studentId,
        AcademicPeriodId $academicPeriodId,
    ): ?Enrollment {
        $statement = $this->connection->prepare(
            $this->selectSql()
            . ' WHERE e.student_id = :studentId AND e.academic_period_id = :academicPeriodId'
        );
        $statement->execute([
            ':studentId' => $studentId->value(),
            ':academicPeriodId' => $academicPeriodId->value(),
        ]);

        return $this->mapUniqueEnrollment(
            $statement,
            'Student and AcademicPeriod resolved more than one Enrollment row.',
        );
    }

    public function save(Enrollment $enrollment): Enrollment
    {
        $ownsTransaction = !$this->connection->inTransaction();
        if ($ownsTransaction && !$this->connection->beginTransaction()) {
            throw new RuntimeException('Enrollment persistence could not start its transaction.');
        }

        try {
            $statusId = $this->resolveStatusId($enrollment->status());
            $persisted = $enrollment->id() === null
                ? $this->insertEnrollment($enrollment, $statusId)
                : $this->updateEnrollment($enrollment, $statusId);

            if (!$this->sameEnrollmentState($persisted, $enrollment)) {
                throw new RuntimeException('Enrollment persistence did not reconstruct the requested state exactly.');
            }

            if ($ownsTransaction && !$this->connection->commit()) {
                throw new RuntimeException('Enrollment persistence could not commit its transaction.');
            }

            return $persisted;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->connection->inTransaction()) {
                $this->connection->rollBack();
            }

            throw $exception;
        }
    }

    private function insertEnrollment(Enrollment $enrollment, int $statusId): Enrollment
    {
        $snapshot = $enrollment->submissionSnapshot();
        if ($snapshot !== null && $snapshot->id() !== null) {
            throw new RuntimeException('A new Enrollment cannot contain a persisted submission snapshot.');
        }

        $statement = $this->connection->prepare(
            'INSERT INTO enrollments ('
            . 'student_id, family_id, academic_period_id, status_id, grade_id, section_id, '
            . 'billing_identification_type_id, billing_identification_number, billing_legal_name, '
            . 'billing_address, billing_email, billing_phone, has_medical_condition, '
            . 'medical_condition_detail, has_allergies, allergy_detail, takes_permanent_medication, '
            . 'medication_name, requires_special_care, special_care_detail, has_medical_insurance, '
            . 'insurance_provider, pediatrician_name, pediatrician_phone, medical_observations, '
            . 'requires_institutional_transport, is_authorized_to_leave_alone, started_at, '
            . 'submitted_at, completed_at, cancelled_at'
            . ') VALUES ('
            . ':studentId, :familyId, :academicPeriodId, :statusId, :gradeId, :sectionId, '
            . ':billingIdentificationTypeId, :billingIdentificationNumber, :billingLegalName, '
            . ':billingAddress, :billingEmail, :billingPhone, :hasMedicalCondition, '
            . ':medicalConditionDetail, :hasAllergies, :allergyDetail, :takesPermanentMedication, '
            . ':medicationName, :requiresSpecialCare, :specialCareDetail, :hasMedicalInsurance, '
            . ':insuranceProvider, :pediatricianName, :pediatricianPhone, :medicalObservations, '
            . ':requiresInstitutionalTransport, :isAuthorizedToLeaveAlone, :startedAt, '
            . ':submittedAt, :completedAt, :cancelledAt'
            . ')'
        );
        $values = $this->mutableValues($enrollment, $statusId);
        $values[':studentId'] = $enrollment->studentId()->value();
        $values[':familyId'] = $enrollment->familyId()->value();
        $values[':academicPeriodId'] = $enrollment->academicPeriodId()->value();
        $values[':startedAt'] = $this->formatTimestamp($enrollment->startedAt());
        $statement->execute($values);
        $this->requireSingleRow($statement, 'Enrollment insert');

        $enrollmentId = new EnrollmentId($this->generatedId('Enrollment'));
        if ($snapshot !== null) {
            $this->insertSnapshot($enrollmentId, $snapshot);
        }

        return $this->requireEnrollment($enrollmentId, 'Inserted Enrollment could not be reconstructed.');
    }

    private function updateEnrollment(Enrollment $enrollment, int $statusId): Enrollment
    {
        $enrollmentId = $enrollment->id();
        if ($enrollmentId === null) {
            throw new RuntimeException('An Enrollment without persisted identity cannot be updated.');
        }

        $row = $this->findRootRow($enrollmentId);
        if ($row === null) {
            throw new RuntimeException('Enrollment update failed because the persisted row disappeared.');
        }
        $this->assertImmutableRootState($row, $enrollment);

        $persistedSnapshot = $this->findSnapshot($enrollmentId);
        $requestedSnapshot = $enrollment->submissionSnapshot();
        if ($requestedSnapshot === null && $persistedSnapshot !== null) {
            throw new RuntimeException('A persisted Enrollment snapshot cannot be removed through save.');
        }
        if ($requestedSnapshot !== null && $requestedSnapshot->id() !== null) {
            if ($persistedSnapshot === null
                || !$this->sameSnapshotState($persistedSnapshot, $requestedSnapshot, true)
            ) {
                throw new RuntimeException('A persisted Enrollment snapshot is immutable or belongs elsewhere.');
            }
        }

        $statement = $this->connection->prepare(
            'UPDATE enrollments SET status_id = :statusId, grade_id = :gradeId, section_id = :sectionId, '
            . 'billing_identification_type_id = :billingIdentificationTypeId, '
            . 'billing_identification_number = :billingIdentificationNumber, '
            . 'billing_legal_name = :billingLegalName, billing_address = :billingAddress, '
            . 'billing_email = :billingEmail, billing_phone = :billingPhone, '
            . 'has_medical_condition = :hasMedicalCondition, medical_condition_detail = :medicalConditionDetail, '
            . 'has_allergies = :hasAllergies, allergy_detail = :allergyDetail, '
            . 'takes_permanent_medication = :takesPermanentMedication, medication_name = :medicationName, '
            . 'requires_special_care = :requiresSpecialCare, special_care_detail = :specialCareDetail, '
            . 'has_medical_insurance = :hasMedicalInsurance, insurance_provider = :insuranceProvider, '
            . 'pediatrician_name = :pediatricianName, pediatrician_phone = :pediatricianPhone, '
            . 'medical_observations = :medicalObservations, '
            . 'requires_institutional_transport = :requiresInstitutionalTransport, '
            . 'is_authorized_to_leave_alone = :isAuthorizedToLeaveAlone, '
            . 'submitted_at = :submittedAt, completed_at = :completedAt, cancelled_at = :cancelledAt '
            . 'WHERE id = :id'
        );
        $values = $this->mutableValues($enrollment, $statusId);
        $values[':id'] = $enrollmentId->value();
        $statement->execute($values);
        if (!in_array($statement->rowCount(), [0, 1], true)) {
            throw new RuntimeException('Enrollment update did not affect zero or one row.');
        }

        if ($requestedSnapshot !== null && $requestedSnapshot->id() === null) {
            if ($persistedSnapshot !== null) {
                $this->deleteSnapshot($persistedSnapshot);
            }
            $this->insertSnapshot($enrollmentId, $requestedSnapshot);
        }

        return $this->requireEnrollment($enrollmentId, 'Updated Enrollment could not be reconstructed.');
    }

    private function insertSnapshot(
        EnrollmentId $enrollmentId,
        EnrollmentSubmissionSnapshot $snapshot,
    ): void {
        if ($snapshot->id() !== null
            || $snapshot->address()->id() !== null
            || array_filter($snapshot->emergencyContacts(), static fn ($child): bool => $child->id() !== null) !== []
            || array_filter($snapshot->authorizedPickups(), static fn ($child): bool => $child->id() !== null) !== []
        ) {
            throw new RuntimeException('A new submission snapshot and every child must be unpersisted.');
        }

        $statement = $this->connection->prepare(
            'INSERT INTO enrollment_submission_snapshots '
            . '(enrollment_id, created_by_representative_id, created_at) '
            . 'VALUES (:enrollmentId, :representativeId, :createdAt)'
        );
        $statement->execute([
            ':enrollmentId' => $enrollmentId->value(),
            ':representativeId' => $snapshot->createdByRepresentativeId()->value(),
            ':createdAt' => $this->formatTimestamp($snapshot->createdAt()),
        ]);
        $this->requireSingleRow($statement, 'Enrollment submission snapshot insert');
        $snapshotId = new EnrollmentSubmissionSnapshotId($this->generatedId('Enrollment submission snapshot'));

        $this->insertAddress($snapshotId, $snapshot->address());
        foreach ($snapshot->emergencyContacts() as $contact) {
            $this->insertEmergencyContact($snapshotId, $contact);
        }
        foreach ($snapshot->authorizedPickups() as $pickup) {
            $this->insertAuthorizedPickup($snapshotId, $pickup);
        }
    }

    private function insertAddress(
        EnrollmentSubmissionSnapshotId $snapshotId,
        SubmittedAddressSnapshot $address,
    ): void {
        $geolocation = $address->geolocation();
        $statement = $this->connection->prepare(
            'INSERT INTO snapshot_addresses ('
            . 'enrollment_submission_snapshot_id, label, main_street, street_number, secondary_street, '
            . 'sector, reference, latitude, longitude'
            . ') VALUES ('
            . ':snapshotId, :label, :mainStreet, :streetNumber, :secondaryStreet, '
            . ':sector, :reference, :latitude, :longitude'
            . ')'
        );
        $statement->execute([
            ':snapshotId' => $snapshotId->value(),
            ':label' => $address->label(),
            ':mainStreet' => $address->mainStreet(),
            ':streetNumber' => $address->streetNumber(),
            ':secondaryStreet' => $address->secondaryStreet(),
            ':sector' => $address->sector(),
            ':reference' => $address->reference(),
            ':latitude' => $geolocation?->latitude(),
            ':longitude' => $geolocation?->longitude(),
        ]);
        $this->requireSingleRow($statement, 'Submitted address snapshot insert');
        $this->generatedId('Submitted address snapshot');
    }

    private function insertEmergencyContact(
        EnrollmentSubmissionSnapshotId $snapshotId,
        SubmittedEmergencyContactSnapshot $contact,
    ): void {
        $statement = $this->connection->prepare(
            'INSERT INTO snapshot_emergency_contacts ('
            . 'enrollment_submission_snapshot_id, names, relationship_type_code, relationship_type_name, '
            . 'mobile_phone, phone, email, observations, priority, sort_order'
            . ') VALUES ('
            . ':snapshotId, :names, :relationshipTypeCode, :relationshipTypeName, '
            . ':mobilePhone, :phone, :email, :observations, :priority, :sortOrder'
            . ')'
        );
        $statement->execute([
            ':snapshotId' => $snapshotId->value(),
            ':names' => $contact->names(),
            ':relationshipTypeCode' => $contact->relationshipTypeCode(),
            ':relationshipTypeName' => $contact->relationshipTypeName(),
            ':mobilePhone' => $contact->mobilePhone(),
            ':phone' => $contact->phone(),
            ':email' => $contact->email(),
            ':observations' => $contact->observations(),
            ':priority' => $contact->priority(),
            ':sortOrder' => $contact->sortOrder(),
        ]);
        $this->requireSingleRow($statement, 'Submitted emergency contact snapshot insert');
        $this->generatedId('Submitted emergency contact snapshot');
    }

    private function insertAuthorizedPickup(
        EnrollmentSubmissionSnapshotId $snapshotId,
        SubmittedAuthorizedPickupSnapshot $pickup,
    ): void {
        $statement = $this->connection->prepare(
            'INSERT INTO snapshot_authorized_pickups ('
            . 'enrollment_submission_snapshot_id, names, relationship_type_code, relationship_type_name, '
            . 'mobile_phone, phone, document_type_code, document_type_name, document_number, observations'
            . ') VALUES ('
            . ':snapshotId, :names, :relationshipTypeCode, :relationshipTypeName, '
            . ':mobilePhone, :phone, :documentTypeCode, :documentTypeName, :documentNumber, :observations'
            . ')'
        );
        $statement->execute([
            ':snapshotId' => $snapshotId->value(),
            ':names' => $pickup->names(),
            ':relationshipTypeCode' => $pickup->relationshipTypeCode(),
            ':relationshipTypeName' => $pickup->relationshipTypeName(),
            ':mobilePhone' => $pickup->mobilePhone(),
            ':phone' => $pickup->phone(),
            ':documentTypeCode' => $pickup->documentTypeCode(),
            ':documentTypeName' => $pickup->documentTypeName(),
            ':documentNumber' => $pickup->documentNumber(),
            ':observations' => $pickup->observations(),
        ]);
        $this->requireSingleRow($statement, 'Submitted authorized pickup snapshot insert');
        $this->generatedId('Submitted authorized pickup snapshot');
    }

    private function deleteSnapshot(EnrollmentSubmissionSnapshot $snapshot): void
    {
        $snapshotId = $snapshot->id();
        if ($snapshotId === null) {
            throw new RuntimeException('Only a persisted submission snapshot can be replaced.');
        }

        $statement = $this->connection->prepare(
            'DELETE FROM enrollment_submission_snapshots WHERE id = :id'
        );
        $statement->execute([':id' => $snapshotId->value()]);
        $this->requireSingleRow($statement, 'Enrollment submission snapshot replacement delete');
    }

    private function mapUniqueEnrollment(PDOStatement $statement, string $multipleMessage): ?Enrollment
    {
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > 1) {
            throw new RuntimeException($multipleMessage);
        }

        return $rows === [] ? null : $this->mapEnrollment($rows[0]);
    }

    /** @param array<string, mixed> $row */
    private function mapEnrollment(array $row): Enrollment
    {
        if ($this->string($row['status_type_code'], 'Enrollment status type') !== self::STATUS_TYPE) {
            throw new RuntimeException('Enrollment status does not belong to ENROLLMENT_STATUS.');
        }
        $status = EnrollmentStatus::tryFrom($this->string($row['status_code'], 'Enrollment status code'));
        if ($status === null) {
            throw new RuntimeException('Enrollment has an unsupported ENROLLMENT_STATUS value.');
        }

        $id = new EnrollmentId($this->positiveInt($row['id'], 'Enrollment id'));

        return Enrollment::reconstitute(
            $id,
            new StudentId($this->positiveInt($row['student_id'], 'Enrollment student id')),
            new FamilyId($this->positiveInt($row['family_id'], 'Enrollment family id')),
            new AcademicPeriodId($this->positiveInt($row['academic_period_id'], 'Enrollment AcademicPeriod id')),
            $status,
            $this->mapAcademicPlacement($row),
            $this->mapBillingInformation($row),
            $this->mapMedicalInformation($row),
            $row['requires_institutional_transport'] === null
                ? null
                : new TransportInformation($this->boolean($row['requires_institutional_transport'], 'Transport flag')),
            $this->boolean($row['is_authorized_to_leave_alone'], 'Leave-alone flag'),
            $this->findSnapshot($id),
            $this->parseTimestamp($row['started_at'], 'Enrollment started_at'),
            $this->nullableTimestamp($row['submitted_at'], 'Enrollment submitted_at'),
            $this->nullableTimestamp($row['completed_at'], 'Enrollment completed_at'),
            $this->nullableTimestamp($row['cancelled_at'], 'Enrollment cancelled_at'),
        );
    }

    /** @param array<string, mixed> $row */
    private function mapAcademicPlacement(array $row): ?AcademicPlacement
    {
        if ($row['grade_id'] === null) {
            if ($row['section_id'] !== null) {
                throw new RuntimeException('Enrollment has a Section without a Grade.');
            }

            return null;
        }

        return new AcademicPlacement(
            new GradeId($this->positiveInt($row['grade_id'], 'Enrollment Grade id')),
            $row['section_id'] === null
                ? null
                : new SectionId($this->positiveInt($row['section_id'], 'Enrollment Section id')),
        );
    }

    /** @param array<string, mixed> $row */
    private function mapBillingInformation(array $row): ?BillingInformation
    {
        $columns = [
            'billing_identification_type_id', 'billing_identification_number', 'billing_legal_name',
            'billing_address', 'billing_email', 'billing_phone',
        ];
        $nulls = count(array_filter($columns, static fn (string $column): bool => $row[$column] === null));
        if ($nulls === count($columns)) {
            return null;
        }
        if ($nulls !== 0) {
            throw new RuntimeException('Enrollment has a partial persisted BillingInformation block.');
        }

        return new BillingInformation(
            new IdentificationTypeId($this->positiveInt(
                $row['billing_identification_type_id'],
                'Billing identification type id',
            )),
            $this->string($row['billing_identification_number'], 'Billing identification number'),
            $this->string($row['billing_legal_name'], 'Billing legal name'),
            $this->string($row['billing_address'], 'Billing address'),
            $this->string($row['billing_email'], 'Billing email'),
            $this->string($row['billing_phone'], 'Billing phone'),
        );
    }

    /** @param array<string, mixed> $row */
    private function mapMedicalInformation(array $row): ?MedicalInformation
    {
        $controlColumns = [
            'has_medical_condition', 'has_allergies', 'takes_permanent_medication',
            'requires_special_care', 'has_medical_insurance',
        ];
        $detailColumns = [
            'medical_condition_detail', 'allergy_detail', 'medication_name', 'special_care_detail',
            'insurance_provider', 'pediatrician_name', 'pediatrician_phone', 'medical_observations',
        ];
        $nullControls = count(array_filter(
            $controlColumns,
            static fn (string $column): bool => $row[$column] === null,
        ));

        if ($nullControls === count($controlColumns)) {
            if (array_filter($detailColumns, static fn (string $column): bool => $row[$column] !== null) !== []) {
                throw new RuntimeException('Enrollment has medical details without a persisted MedicalInformation block.');
            }

            return null;
        }
        if ($nullControls !== 0) {
            throw new RuntimeException('Enrollment has a partial persisted MedicalInformation control block.');
        }

        return new MedicalInformation(
            $this->boolean($row['has_medical_condition'], 'Medical condition flag'),
            $this->nullableString($row['medical_condition_detail'], 'Medical condition detail'),
            $this->boolean($row['has_allergies'], 'Allergies flag'),
            $this->nullableString($row['allergy_detail'], 'Allergy detail'),
            $this->boolean($row['takes_permanent_medication'], 'Permanent medication flag'),
            $this->nullableString($row['medication_name'], 'Medication name'),
            $this->boolean($row['requires_special_care'], 'Special care flag'),
            $this->nullableString($row['special_care_detail'], 'Special care detail'),
            $this->boolean($row['has_medical_insurance'], 'Medical insurance flag'),
            $this->nullableString($row['insurance_provider'], 'Insurance provider'),
            $this->nullableString($row['pediatrician_name'], 'Pediatrician name'),
            $this->nullableString($row['pediatrician_phone'], 'Pediatrician phone'),
            $this->nullableString($row['medical_observations'], 'Medical observations'),
        );
    }

    private function findSnapshot(EnrollmentId $enrollmentId): ?EnrollmentSubmissionSnapshot
    {
        $statement = $this->connection->prepare(
            'SELECT id, enrollment_id, created_by_representative_id, created_at '
            . 'FROM enrollment_submission_snapshots WHERE enrollment_id = :enrollmentId'
        );
        $statement->execute([':enrollmentId' => $enrollmentId->value()]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > 1) {
            throw new RuntimeException('Enrollment has more than one persisted submission snapshot.');
        }
        if ($rows === []) {
            return null;
        }

        $row = $rows[0];
        if ($this->positiveInt($row['enrollment_id'], 'Snapshot Enrollment id') !== $enrollmentId->value()) {
            throw new RuntimeException('Submission snapshot does not belong to the requested Enrollment.');
        }
        $snapshotId = new EnrollmentSubmissionSnapshotId(
            $this->positiveInt($row['id'], 'Enrollment submission snapshot id'),
        );

        return EnrollmentSubmissionSnapshot::reconstitute(
            $snapshotId,
            new RepresentativeId($this->positiveInt(
                $row['created_by_representative_id'],
                'Snapshot Representative id',
            )),
            $this->parseTimestamp($row['created_at'], 'Enrollment submission snapshot created_at'),
            $this->findSnapshotAddress($snapshotId),
            $this->findEmergencyContacts($snapshotId),
            $this->findAuthorizedPickups($snapshotId),
        );
    }

    private function findSnapshotAddress(
        EnrollmentSubmissionSnapshotId $snapshotId,
    ): SubmittedAddressSnapshot {
        $statement = $this->connection->prepare(
            'SELECT id, enrollment_submission_snapshot_id, label, main_street, street_number, '
            . 'secondary_street, sector, reference, latitude, longitude '
            . 'FROM snapshot_addresses WHERE enrollment_submission_snapshot_id = :snapshotId'
        );
        $statement->execute([':snapshotId' => $snapshotId->value()]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 1) {
            throw new RuntimeException('A persisted submission snapshot requires exactly one address.');
        }

        $row = $rows[0];
        $this->assertSnapshotOwnership($row, $snapshotId, 'Submitted address');
        $latitude = $row['latitude'];
        $longitude = $row['longitude'];
        if (($latitude === null) !== ($longitude === null)) {
            throw new RuntimeException('Submitted address has a partial persisted geolocation.');
        }

        return SubmittedAddressSnapshot::reconstitute(
            new SubmittedAddressSnapshotId($this->positiveInt($row['id'], 'Submitted address id')),
            $this->string($row['label'], 'Submitted address label'),
            $this->string($row['main_street'], 'Submitted address main street'),
            $this->nullableString($row['street_number'], 'Submitted address street number'),
            $this->nullableString($row['secondary_street'], 'Submitted address secondary street'),
            $this->nullableString($row['sector'], 'Submitted address sector'),
            $this->nullableString($row['reference'], 'Submitted address reference'),
            $latitude === null
                ? null
                : new Geolocation(
                    $this->decimalString($latitude, 'Submitted address latitude'),
                    $this->decimalString($longitude, 'Submitted address longitude'),
                ),
        );
    }

    /** @return list<SubmittedEmergencyContactSnapshot> */
    private function findEmergencyContacts(EnrollmentSubmissionSnapshotId $snapshotId): array
    {
        $statement = $this->connection->prepare(
            'SELECT id, enrollment_submission_snapshot_id, names, relationship_type_code, '
            . 'relationship_type_name, mobile_phone, phone, email, observations, priority, sort_order '
            . 'FROM snapshot_emergency_contacts '
            . 'WHERE enrollment_submission_snapshot_id = :snapshotId ORDER BY sort_order ASC, id ASC'
        );
        $statement->execute([':snapshotId' => $snapshotId->value()]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            throw new RuntimeException('A persisted submission snapshot requires at least one emergency contact.');
        }

        return array_map(function (array $row) use ($snapshotId): SubmittedEmergencyContactSnapshot {
            $this->assertSnapshotOwnership($row, $snapshotId, 'Submitted emergency contact');

            return SubmittedEmergencyContactSnapshot::reconstitute(
                new SubmittedEmergencyContactSnapshotId($this->positiveInt(
                    $row['id'],
                    'Submitted emergency contact id',
                )),
                $this->string($row['names'], 'Submitted emergency contact names'),
                $this->string($row['relationship_type_code'], 'Submitted emergency relationship code'),
                $this->string($row['relationship_type_name'], 'Submitted emergency relationship name'),
                $this->string($row['mobile_phone'], 'Submitted emergency mobile phone'),
                $this->nullableString($row['phone'], 'Submitted emergency phone'),
                $this->nullableString($row['email'], 'Submitted emergency email'),
                $this->nullableString($row['observations'], 'Submitted emergency observations'),
                $row['priority'] === null
                    ? null
                    : $this->positiveInt($row['priority'], 'Submitted emergency priority'),
                $this->positiveInt($row['sort_order'], 'Submitted emergency sort order'),
            );
        }, $rows);
    }

    /** @return list<SubmittedAuthorizedPickupSnapshot> */
    private function findAuthorizedPickups(EnrollmentSubmissionSnapshotId $snapshotId): array
    {
        $statement = $this->connection->prepare(
            'SELECT id, enrollment_submission_snapshot_id, names, relationship_type_code, '
            . 'relationship_type_name, mobile_phone, phone, document_type_code, document_type_name, '
            . 'document_number, observations FROM snapshot_authorized_pickups '
            . 'WHERE enrollment_submission_snapshot_id = :snapshotId ORDER BY id ASC'
        );
        $statement->execute([':snapshotId' => $snapshotId->value()]);

        return array_map(function (array $row) use ($snapshotId): SubmittedAuthorizedPickupSnapshot {
            $this->assertSnapshotOwnership($row, $snapshotId, 'Submitted authorized pickup');

            return SubmittedAuthorizedPickupSnapshot::reconstitute(
                new SubmittedAuthorizedPickupSnapshotId($this->positiveInt(
                    $row['id'],
                    'Submitted authorized pickup id',
                )),
                $this->string($row['names'], 'Submitted authorized pickup names'),
                $this->string($row['relationship_type_code'], 'Submitted pickup relationship code'),
                $this->string($row['relationship_type_name'], 'Submitted pickup relationship name'),
                $this->string($row['mobile_phone'], 'Submitted pickup mobile phone'),
                $this->nullableString($row['phone'], 'Submitted pickup phone'),
                $this->string($row['document_type_code'], 'Submitted pickup document type code'),
                $this->string($row['document_type_name'], 'Submitted pickup document type name'),
                $this->string($row['document_number'], 'Submitted pickup document number'),
                $this->nullableString($row['observations'], 'Submitted pickup observations'),
            );
        }, $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param array<string, mixed> $row */
    private function assertSnapshotOwnership(
        array $row,
        EnrollmentSubmissionSnapshotId $snapshotId,
        string $entity,
    ): void {
        if ($this->positiveInt(
            $row['enrollment_submission_snapshot_id'],
            $entity . ' snapshot id',
        ) !== $snapshotId->value()) {
            throw new RuntimeException($entity . ' belongs to another submission snapshot.');
        }
    }

    private function selectSql(): string
    {
        return 'SELECT e.id, e.student_id, e.family_id, e.academic_period_id, e.status_id, '
            . 'e.grade_id, e.section_id, e.billing_identification_type_id, '
            . 'e.billing_identification_number, e.billing_legal_name, e.billing_address, '
            . 'e.billing_email, e.billing_phone, e.has_medical_condition, '
            . 'e.medical_condition_detail, e.has_allergies, e.allergy_detail, '
            . 'e.takes_permanent_medication, e.medication_name, e.requires_special_care, '
            . 'e.special_care_detail, e.has_medical_insurance, e.insurance_provider, '
            . 'e.pediatrician_name, e.pediatrician_phone, e.medical_observations, '
            . 'e.requires_institutional_transport, e.is_authorized_to_leave_alone, '
            . 'e.started_at, e.submitted_at, e.completed_at, e.cancelled_at, '
            . 'status_row.code AS status_code, status_type.code AS status_type_code '
            . 'FROM enrollments e '
            . 'INNER JOIN statuses status_row ON status_row.id = e.status_id '
            . 'INNER JOIN status_types status_type ON status_type.id = status_row.status_type_id';
    }

    /** @return array<string, int|string|null> */
    private function mutableValues(Enrollment $enrollment, int $statusId): array
    {
        $placement = $enrollment->academicPlacement();
        $billing = $enrollment->billingInformation();
        $medical = $enrollment->medicalInformation();
        $transport = $enrollment->transportInformation();

        return [
            ':statusId' => $statusId,
            ':gradeId' => $placement?->gradeId()->value(),
            ':sectionId' => $placement?->sectionId()?->value(),
            ':billingIdentificationTypeId' => $billing?->identificationTypeId()->value(),
            ':billingIdentificationNumber' => $billing?->identificationNumber(),
            ':billingLegalName' => $billing?->legalName(),
            ':billingAddress' => $billing?->billingAddress(),
            ':billingEmail' => $billing?->billingEmail(),
            ':billingPhone' => $billing?->phone(),
            ':hasMedicalCondition' => $medical === null ? null : (int) $medical->hasMedicalCondition(),
            ':medicalConditionDetail' => $medical?->medicalConditionDetail(),
            ':hasAllergies' => $medical === null ? null : (int) $medical->hasAllergies(),
            ':allergyDetail' => $medical?->allergyDetail(),
            ':takesPermanentMedication' => $medical === null ? null : (int) $medical->takesPermanentMedication(),
            ':medicationName' => $medical?->medicationName(),
            ':requiresSpecialCare' => $medical === null ? null : (int) $medical->requiresSpecialCare(),
            ':specialCareDetail' => $medical?->specialCareDetail(),
            ':hasMedicalInsurance' => $medical === null ? null : (int) $medical->hasMedicalInsurance(),
            ':insuranceProvider' => $medical?->insuranceProvider(),
            ':pediatricianName' => $medical?->pediatricianName(),
            ':pediatricianPhone' => $medical?->pediatricianPhone(),
            ':medicalObservations' => $medical?->observations(),
            ':requiresInstitutionalTransport' => $transport === null
                ? null
                : (int) $transport->requiresInstitutionalTransport(),
            ':isAuthorizedToLeaveAlone' => (int) $enrollment->isAuthorizedToLeaveAlone(),
            ':submittedAt' => $this->formatNullableTimestamp($enrollment->submittedAt()),
            ':completedAt' => $this->formatNullableTimestamp($enrollment->completedAt()),
            ':cancelledAt' => $this->formatNullableTimestamp($enrollment->cancelledAt()),
        ];
    }

    private function resolveStatusId(EnrollmentStatus $status): int
    {
        $statement = $this->connection->prepare(
            'SELECT status_row.id FROM statuses status_row '
            . 'INNER JOIN status_types status_type ON status_type.id = status_row.status_type_id '
            . 'WHERE status_type.code = :statusType AND status_row.code = :statusCode'
        );
        $statement->execute([
            ':statusType' => self::STATUS_TYPE,
            ':statusCode' => $status->value,
        ]);
        $rows = $statement->fetchAll(PDO::FETCH_COLUMN);
        if (count($rows) !== 1) {
            throw new RuntimeException('Enrollment status must resolve to exactly one ENROLLMENT_STATUS row.');
        }

        return $this->positiveInt($rows[0], 'Enrollment status id');
    }

    private function findRootRow(EnrollmentId $id): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, student_id, family_id, academic_period_id, started_at '
            . 'FROM enrollments WHERE id = :id'
        );
        $statement->execute([':id' => $id->value()]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > 1) {
            throw new RuntimeException('Enrollment identity resolved more than one root row.');
        }

        return $rows === [] ? null : $rows[0];
    }

    /** @param array<string, mixed> $row */
    private function assertImmutableRootState(array $row, Enrollment $enrollment): void
    {
        if ($this->positiveInt($row['student_id'], 'Enrollment student id') !== $enrollment->studentId()->value()
            || $this->positiveInt($row['family_id'], 'Enrollment family id') !== $enrollment->familyId()->value()
            || $this->positiveInt($row['academic_period_id'], 'Enrollment AcademicPeriod id')
                !== $enrollment->academicPeriodId()->value()
        ) {
            throw new RuntimeException('Enrollment persisted ownership cannot be changed.');
        }
        if ($this->formatTimestamp($this->parseTimestamp($row['started_at'], 'Enrollment started_at'))
            !== $this->formatTimestamp($enrollment->startedAt())
        ) {
            throw new RuntimeException('Enrollment persisted started_at cannot be changed.');
        }
    }

    private function requireEnrollment(EnrollmentId $id, string $message): Enrollment
    {
        $enrollment = $this->findById($id);
        if ($enrollment === null) {
            throw new RuntimeException($message);
        }

        return $enrollment;
    }

    private function sameEnrollmentState(Enrollment $persisted, Enrollment $requested): bool
    {
        $persistedId = $persisted->id();
        $requestedId = $requested->id();
        if ($persistedId === null || ($requestedId !== null && !$persistedId->equals($requestedId))) {
            return false;
        }

        return $persisted->studentId()->equals($requested->studentId())
            && $persisted->familyId()->equals($requested->familyId())
            && $persisted->academicPeriodId()->equals($requested->academicPeriodId())
            && $persisted->status() === $requested->status()
            && $this->sameOptionalPlacement($persisted->academicPlacement(), $requested->academicPlacement())
            && $this->sameOptionalBilling($persisted->billingInformation(), $requested->billingInformation())
            && $this->sameOptionalMedical($persisted->medicalInformation(), $requested->medicalInformation())
            && $this->sameOptionalTransport($persisted->transportInformation(), $requested->transportInformation())
            && $persisted->isAuthorizedToLeaveAlone() === $requested->isAuthorizedToLeaveAlone()
            && $this->sameTimestamp($persisted->startedAt(), $requested->startedAt())
            && $this->sameNullableTimestamp($persisted->submittedAt(), $requested->submittedAt())
            && $this->sameNullableTimestamp($persisted->completedAt(), $requested->completedAt())
            && $this->sameNullableTimestamp($persisted->cancelledAt(), $requested->cancelledAt())
            && $this->sameOptionalSnapshot($persisted->submissionSnapshot(), $requested->submissionSnapshot());
    }

    private function sameOptionalPlacement(?AcademicPlacement $left, ?AcademicPlacement $right): bool
    {
        return ($left === null && $right === null)
            || ($left !== null && $right !== null && $left->equals($right));
    }

    private function sameOptionalBilling(?BillingInformation $left, ?BillingInformation $right): bool
    {
        return ($left === null && $right === null)
            || ($left !== null && $right !== null && $left->equals($right));
    }

    private function sameOptionalMedical(?MedicalInformation $left, ?MedicalInformation $right): bool
    {
        return ($left === null && $right === null)
            || ($left !== null && $right !== null && $this->medicalState($left) === $this->medicalState($right));
    }

    /** @return list<bool|string|null> */
    private function medicalState(MedicalInformation $medical): array
    {
        return [
            $medical->hasMedicalCondition(), $medical->medicalConditionDetail(),
            $medical->hasAllergies(), $medical->allergyDetail(),
            $medical->takesPermanentMedication(), $medical->medicationName(),
            $medical->requiresSpecialCare(), $medical->specialCareDetail(),
            $medical->hasMedicalInsurance(), $medical->insuranceProvider(),
            $medical->pediatricianName(), $medical->pediatricianPhone(), $medical->observations(),
        ];
    }

    private function sameOptionalTransport(?TransportInformation $left, ?TransportInformation $right): bool
    {
        return ($left === null && $right === null)
            || ($left !== null
                && $right !== null
                && $left->requiresInstitutionalTransport() === $right->requiresInstitutionalTransport());
    }

    private function sameOptionalSnapshot(
        ?EnrollmentSubmissionSnapshot $persisted,
        ?EnrollmentSubmissionSnapshot $requested,
    ): bool {
        if ($persisted === null || $requested === null) {
            return $persisted === null && $requested === null;
        }

        return $this->sameSnapshotState($persisted, $requested, $requested->id() !== null);
    }

    private function sameSnapshotState(
        EnrollmentSubmissionSnapshot $left,
        EnrollmentSubmissionSnapshot $right,
        bool $compareIdentities,
    ): bool {
        if ($compareIdentities && !$this->sameRequiredIdentity($left->id()?->value(), $right->id()?->value())) {
            return false;
        }
        if (!$left->createdByRepresentativeId()->equals($right->createdByRepresentativeId())
            || !$this->sameTimestamp($left->createdAt(), $right->createdAt())
            || !$this->sameAddress($left->address(), $right->address(), $compareIdentities)
        ) {
            return false;
        }

        $leftEmergency = $left->emergencyContacts();
        $rightEmergency = $right->emergencyContacts();
        if (count($leftEmergency) !== count($rightEmergency)) {
            return false;
        }
        foreach ($leftEmergency as $index => $contact) {
            if (!$this->sameEmergencyContact($contact, $rightEmergency[$index], $compareIdentities)) {
                return false;
            }
        }

        return $this->pickupStates($left->authorizedPickups(), $compareIdentities)
            === $this->pickupStates($right->authorizedPickups(), $compareIdentities);
    }

    private function sameAddress(
        SubmittedAddressSnapshot $left,
        SubmittedAddressSnapshot $right,
        bool $compareIdentities,
    ): bool {
        if ($compareIdentities && !$this->sameRequiredIdentity($left->id()?->value(), $right->id()?->value())) {
            return false;
        }

        $leftGeo = $left->geolocation();
        $rightGeo = $right->geolocation();

        return $left->label() === $right->label()
            && $left->mainStreet() === $right->mainStreet()
            && $left->streetNumber() === $right->streetNumber()
            && $left->secondaryStreet() === $right->secondaryStreet()
            && $left->sector() === $right->sector()
            && $left->reference() === $right->reference()
            && (($leftGeo === null && $rightGeo === null)
                || ($leftGeo !== null && $rightGeo !== null && $leftGeo->equals($rightGeo)));
    }

    private function sameEmergencyContact(
        SubmittedEmergencyContactSnapshot $left,
        SubmittedEmergencyContactSnapshot $right,
        bool $compareIdentities,
    ): bool {
        if ($compareIdentities && !$this->sameRequiredIdentity($left->id()?->value(), $right->id()?->value())) {
            return false;
        }

        return $left->names() === $right->names()
            && $left->relationshipTypeCode() === $right->relationshipTypeCode()
            && $left->relationshipTypeName() === $right->relationshipTypeName()
            && $left->mobilePhone() === $right->mobilePhone()
            && $left->phone() === $right->phone()
            && $left->email() === $right->email()
            && $left->observations() === $right->observations()
            && $left->priority() === $right->priority()
            && $left->sortOrder() === $right->sortOrder();
    }

    /**
     * @param list<SubmittedAuthorizedPickupSnapshot> $pickups
     * @return list<string>
     */
    private function pickupStates(array $pickups, bool $includeIdentity): array
    {
        $states = array_map(static function (
            SubmittedAuthorizedPickupSnapshot $pickup,
        ) use ($includeIdentity): string {
            return json_encode([
                $includeIdentity ? $pickup->id()?->value() : null,
                $pickup->names(),
                $pickup->relationshipTypeCode(),
                $pickup->relationshipTypeName(),
                $pickup->mobilePhone(),
                $pickup->phone(),
                $pickup->documentTypeCode(),
                $pickup->documentTypeName(),
                $pickup->documentNumber(),
                $pickup->observations(),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }, $pickups);
        sort($states, SORT_STRING);

        return $states;
    }

    private function sameRequiredIdentity(?int $left, ?int $right): bool
    {
        return $left !== null && $right !== null && $left === $right;
    }

    private function sameTimestamp(DateTimeImmutable $left, DateTimeImmutable $right): bool
    {
        return $this->formatTimestamp($left) === $this->formatTimestamp($right);
    }

    private function sameNullableTimestamp(?DateTimeImmutable $left, ?DateTimeImmutable $right): bool
    {
        return ($left === null && $right === null)
            || ($left !== null && $right !== null && $this->sameTimestamp($left, $right));
    }

    private function formatTimestamp(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function formatNullableTimestamp(?DateTimeImmutable $value): ?string
    {
        return $value === null ? null : $this->formatTimestamp($value);
    }

    private function parseTimestamp(mixed $value, string $label): DateTimeImmutable
    {
        if (!is_string($value)) {
            throw new RuntimeException($label . ' must be a persisted UTC timestamp string.');
        }

        $timezone = new DateTimeZone('UTC');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $timezone);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false
            || $date->format('Y-m-d H:i:s') !== $value
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        ) {
            throw new RuntimeException($label . ' has an invalid persisted UTC timestamp.');
        }

        return $date;
    }

    private function nullableTimestamp(mixed $value, string $label): ?DateTimeImmutable
    {
        return $value === null ? null : $this->parseTimestamp($value, $label);
    }

    private function boolean(mixed $value, string $label): bool
    {
        if ($value === 0 || $value === '0') {
            return false;
        }
        if ($value === 1 || $value === '1') {
            return true;
        }

        throw new RuntimeException($label . ' must be persisted strictly as 0 or 1.');
    }

    private function positiveInt(mixed $value, string $label): int
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

    private function string(mixed $value, string $label): string
    {
        if (!is_string($value)) {
            throw new RuntimeException($label . ' must be a persisted string.');
        }

        return $value;
    }

    private function nullableString(mixed $value, string $label): ?string
    {
        return $value === null ? null : $this->string($value, $label);
    }

    private function decimalString(mixed $value, string $label): string
    {
        if (is_string($value) || is_int($value) || is_float($value)) {
            return (string) $value;
        }

        throw new RuntimeException($label . ' must be a persisted decimal value.');
    }

    private function requireSingleRow(PDOStatement $statement, string $operation): void
    {
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException($operation . ' did not affect exactly one row.');
        }
    }

    private function generatedId(string $entity): int
    {
        $id = (int) $this->connection->lastInsertId();
        if ($id <= 0) {
            throw new RuntimeException($entity . ' insert did not produce a positive database identity.');
        }

        return $id;
    }
}
