<?php

declare(strict_types=1);

namespace App\Enrollment\Infrastructure\Reporting;

use App\Enrollment\Application\Reporting\Dto\PhysicalDepartureAuthorizedPickup;
use App\Enrollment\Application\Reporting\Dto\PhysicalDepartureReportRow;
use App\Enrollment\Application\Reporting\PhysicalDepartureReportQuery;
use App\Enrollment\Application\Reporting\ReportingGradeSectionFilter;
use RuntimeException;

final class PdoPhysicalDepartureReportQuery extends PdoEnrollmentReportingQuery implements
    PhysicalDepartureReportQuery
{
    public function fetch(
        int $academicPeriodId,
        ?ReportingGradeSectionFilter $gradeSectionFilter = null,
    ): array {
        $this->positiveInt($academicPeriodId, 'AcademicPeriod identity');
        $parameters = [':academicPeriodId' => $academicPeriodId];
        $gradeSectionPredicate = $this->gradeSectionPredicate(
            'e',
            $academicPeriodId,
            $gradeSectionFilter,
            $parameters,
        );
        $rows = $this->rows(
            'SELECT s.id AS student_id, student_status_type.code AS student_status_type, '
            . 'student_status.code AS student_status_code, '
            . 'p.first_name AS student_first_name, p.middle_name AS student_middle_name, '
            . 'p.first_surname AS student_first_surname, p.second_surname AS student_second_surname, '
            . 'p.document_type_id AS student_document_type_id, '
            . 'student_document_type.name AS student_document_type_name, '
            . 'p.document_number AS student_document_number, '
            . 'e.id AS enrollment_id, enrollment_status_type.code AS enrollment_status_type, '
            . 'enrollment_status.code AS enrollment_status_code, e.is_authorized_to_leave_alone, '
            . 'g.id AS grade_id, g.name AS grade_name, g.sort_order AS grade_sort_order, '
            . 'sec.id AS section_id, sec.name AS section_name, '
            . 'fs.id AS family_student_id, fs.family_id AS current_family_id, '
            . 'f.id AS family_id, family_status_type.code AS family_status_type, '
            . 'family_status.code AS family_status_code '
            . 'FROM students s '
            . 'INNER JOIN persons p ON p.id = s.person_id '
            . 'LEFT JOIN document_types student_document_type ON student_document_type.id = p.document_type_id '
            . 'INNER JOIN statuses student_status ON student_status.id = s.status_id '
            . 'INNER JOIN status_types student_status_type ON student_status_type.id = student_status.status_type_id '
            . 'LEFT JOIN enrollments e ON e.student_id = s.id AND e.academic_period_id = :academicPeriodId '
            . 'LEFT JOIN statuses enrollment_status ON enrollment_status.id = e.status_id '
            . 'LEFT JOIN status_types enrollment_status_type '
            . 'ON enrollment_status_type.id = enrollment_status.status_type_id '
            . 'LEFT JOIN grades g ON g.id = e.grade_id '
            . 'LEFT JOIN sections sec ON sec.id = e.section_id '
            . 'LEFT JOIN family_students fs ON fs.student_id = s.id AND fs.ended_at IS NULL '
            . 'LEFT JOIN families f ON f.id = fs.family_id '
            . 'LEFT JOIN statuses family_status ON family_status.id = f.status_id '
            . 'LEFT JOIN status_types family_status_type ON family_status_type.id = family_status.status_type_id '
            . 'WHERE ' . $gradeSectionPredicate . ' '
            . 'ORDER BY CASE WHEN g.id IS NULL THEN 1 ELSE 0 END, g.sort_order, g.id, '
            . 'CASE WHEN sec.id IS NULL THEN 1 ELSE 0 END, sec.name, sec.id, '
            . 'p.first_surname, p.second_surname, p.first_name, p.middle_name, s.id',
            $parameters,
        );

        $students = [];
        $studentIds = [];
        foreach ($rows as $row) {
            $studentId = $this->positiveInt($row['student_id'] ?? null, 'Student identity');
            if (isset($students[$studentId])) {
                throw new RuntimeException('Physical departure report returned duplicate Enrollment or Family rows.');
            }
            $active = $this->isActiveStudent($row);
            $enrollmentId = $this->nullablePositiveInt($row['enrollment_id'] ?? null, 'Enrollment identity');
            if ($enrollmentId === null) {
                if (($row['enrollment_status_type'] ?? null) !== null
                    || ($row['enrollment_status_code'] ?? null) !== null
                    || ($row['is_authorized_to_leave_alone'] ?? null) !== null
                ) {
                    throw new RuntimeException('Physical departure report returned Enrollment data without Enrollment.');
                }
                $leaveAlone = null;
            } else {
                $this->reportingStatus($row);
                $leaveAlone = $this->nullableBoolean(
                    $row['is_authorized_to_leave_alone'] ?? null,
                    'leave-alone authorization',
                );
                if ($leaveAlone === null) {
                    throw new RuntimeException('Physical departure report returned an undefined leave-alone authorization.');
                }
            }
            [, $gradeName, , $sectionName] = $this->placement($row);
            [$identificationType, $identificationNumber] = $this->identification(
                $row['student_document_type_id'] ?? null,
                $row['student_document_type_name'] ?? null,
                $row['student_document_number'] ?? null,
                'Student',
            );
            $familyId = $this->currentFamily($row);
            if (!$active) {
                continue;
            }

            $students[$studentId] = [
                'studentId' => $studentId,
                'gradeName' => $gradeName,
                'sectionName' => $sectionName,
                'firstName' => $this->requiredString($row['student_first_name'] ?? null, 'Student first name'),
                'middleName' => $this->nullableString($row['student_middle_name'] ?? null, 'Student middle name'),
                'firstSurname' => $this->requiredString($row['student_first_surname'] ?? null, 'Student first surname'),
                'secondSurname' => $this->nullableString($row['student_second_surname'] ?? null, 'Student second surname'),
                'identificationType' => $identificationType,
                'identificationNumber' => $identificationNumber,
                'leaveAlone' => $leaveAlone,
                'familyId' => $familyId,
            ];
            $studentIds[] = $studentId;
        }

        $pickups = $this->pickupsByStudent($studentIds, $students);
        $result = [];
        foreach ($students as $student) {
            $result[] = new PhysicalDepartureReportRow(
                $student['studentId'],
                $student['gradeName'],
                $student['sectionName'],
                $student['firstName'],
                $student['middleName'],
                $student['firstSurname'],
                $student['secondSurname'],
                $student['identificationType'],
                $student['identificationNumber'],
                $student['leaveAlone'],
                $pickups[$student['studentId']] ?? [],
            );
        }

        return $result;
    }

    /** @param list<int> $studentIds
     *  @param array<int, array<string, mixed>> $students
     *  @return array<int, list<PhysicalDepartureAuthorizedPickup>>
     */
    private function pickupsByStudent(array $studentIds, array $students): array
    {
        if ($studentIds === []) {
            return [];
        }
        $parameters = [];
        $placeholders = [];
        foreach ($studentIds as $index => $studentId) {
            $placeholder = ':departureStudent' . $index;
            $placeholders[] = $placeholder;
            $parameters[$placeholder] = $studentId;
        }
        $rows = $this->rows(
            'SELECT apa.id AS assignment_id, apa.student_id, apa.family_id AS assignment_family_id, '
            . 'apa.family_authorized_pickup_id AS pickup_id, pickup.family_id AS pickup_family_id, '
            . 'pickup.names, pickup.relationship_type_id, relationship_type.name AS relationship_name, '
            . 'pickup.document_type_id, document_type.name AS document_type_name, pickup.document_number, '
            . 'pickup.mobile_phone, pickup_status_type.code AS pickup_status_type, '
            . 'pickup_status.code AS pickup_status_code '
            . 'FROM authorized_pickup_assignments apa '
            . 'LEFT JOIN family_authorized_pickups pickup ON pickup.id = apa.family_authorized_pickup_id '
            . 'LEFT JOIN relationship_types relationship_type ON relationship_type.id = pickup.relationship_type_id '
            . 'LEFT JOIN document_types document_type ON document_type.id = pickup.document_type_id '
            . 'LEFT JOIN statuses pickup_status ON pickup_status.id = pickup.status_id '
            . 'LEFT JOIN status_types pickup_status_type ON pickup_status_type.id = pickup_status.status_type_id '
            . 'WHERE apa.ended_at IS NULL AND apa.student_id IN (' . implode(', ', $placeholders) . ') '
            . 'ORDER BY apa.student_id, pickup.names, relationship_type.name, pickup.id, apa.id',
            $parameters,
        );

        $result = [];
        $seen = [];
        foreach ($rows as $row) {
            $this->positiveInt($row['assignment_id'] ?? null, 'AuthorizedPickupAssignment identity');
            $studentId = $this->positiveInt($row['student_id'] ?? null, 'pickup Student identity');
            $assignmentFamilyId = $this->positiveInt(
                $row['assignment_family_id'] ?? null,
                'pickup assignment Family identity',
            );
            $pickupId = $this->positiveInt($row['pickup_id'] ?? null, 'FamilyAuthorizedPickup identity');
            $pickupFamilyId = $this->positiveInt($row['pickup_family_id'] ?? null, 'pickup Family identity');
            $student = $students[$studentId] ?? null;
            if ($student === null || $student['familyId'] === null
                || $assignmentFamilyId !== $student['familyId']
                || $pickupFamilyId !== $student['familyId']
            ) {
                throw new RuntimeException('Physical departure report returned a pickup outside the current Family.');
            }
            $key = $studentId . ':' . $pickupId;
            if (isset($seen[$key])) {
                throw new RuntimeException('Physical departure report returned duplicate active pickup assignments.');
            }
            $seen[$key] = true;
            $this->positiveInt($row['relationship_type_id'] ?? null, 'pickup RelationshipType identity');
            $relationship = $this->requiredString($row['relationship_name'] ?? null, 'pickup relationship');
            [$identificationType, $identificationNumber] = $this->identification(
                $row['document_type_id'] ?? null,
                $row['document_type_name'] ?? null,
                $row['document_number'] ?? null,
                'AuthorizedPickup',
            );
            if (($row['pickup_status_type'] ?? null) !== self::STUDENT_STATUS_TYPE) {
                throw new RuntimeException('Physical departure report returned an invalid pickup status type.');
            }
            if (($row['pickup_status_code'] ?? null) === 'INACTIVE') {
                continue;
            }
            if (($row['pickup_status_code'] ?? null) !== 'ACTIVE') {
                throw new RuntimeException('Physical departure report returned an unsupported pickup status.');
            }
            $result[$studentId][] = new PhysicalDepartureAuthorizedPickup(
                $this->requiredString($row['names'] ?? null, 'pickup name'),
                $relationship,
                $identificationType,
                $identificationNumber,
                $this->requiredString($row['mobile_phone'] ?? null, 'pickup mobile phone'),
            );
        }

        return $result;
    }

    /** @return array{?string, ?string} */
    private function identification(mixed $typeId, mixed $typeName, mixed $number, string $owner): array
    {
        if ($typeId === null && $typeName === null && $number === null) {
            return [null, null];
        }
        if ($typeId === null || $typeName === null || $number === null) {
            throw new RuntimeException("Physical departure report returned incomplete {$owner} identification.");
        }
        $this->positiveInt($typeId, "{$owner} DocumentType identity");

        return [
            $this->requiredString($typeName, "{$owner} DocumentType name"),
            $this->requiredString($number, "{$owner} document number"),
        ];
    }

    /** @param array<string, mixed> $row */
    private function currentFamily(array $row): ?int
    {
        $membershipId = $this->nullablePositiveInt($row['family_student_id'] ?? null, 'FamilyStudent identity');
        $familyId = $this->nullablePositiveInt($row['current_family_id'] ?? null, 'current Family identity');
        if ($membershipId === null && $familyId === null) {
            foreach (['family_id', 'family_status_type', 'family_status_code'] as $field) {
                if (($row[$field] ?? null) !== null) {
                    throw new RuntimeException('Physical departure report returned Family data without membership.');
                }
            }

            return null;
        }
        if ($membershipId === null || $familyId === null
            || $this->positiveInt($row['family_id'] ?? null, 'Family identity') !== $familyId
            || ($row['family_status_type'] ?? null) !== self::STUDENT_STATUS_TYPE
            || ($row['family_status_code'] ?? null) !== 'ACTIVE'
        ) {
            throw new RuntimeException('Physical departure report returned an incoherent current Family.');
        }

        return $familyId;
    }
}
