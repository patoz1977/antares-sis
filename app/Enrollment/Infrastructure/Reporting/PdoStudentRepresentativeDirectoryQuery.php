<?php

declare(strict_types=1);

namespace App\Enrollment\Infrastructure\Reporting;

use App\Enrollment\Application\Reporting\Dto\DirectoryRepresentative;
use App\Enrollment\Application\Reporting\Dto\StudentRepresentativeDirectoryRow;
use App\Enrollment\Application\Reporting\StudentRepresentativeDirectoryQuery;
use App\Enrollment\Application\Reporting\ReportingGradeSectionFilter;
use App\Family\Domain\ValueObject\Address;
use RuntimeException;

final class PdoStudentRepresentativeDirectoryQuery extends PdoEnrollmentReportingQuery implements
    StudentRepresentativeDirectoryQuery
{
    public function fetch(
        int $academicPeriodId,
        ?ReportingGradeSectionFilter $gradeSectionFilter = null,
    ): array
    {
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
            . 'sp.first_name AS student_first_name, sp.middle_name AS student_middle_name, '
            . 'sp.first_surname AS student_first_surname, sp.second_surname AS student_second_surname, '
            . 'sp.document_type_id AS student_document_type_id, '
            . 'student_document_type.name AS student_document_type_name, '
            . 'sp.document_number AS student_document_number, '
            . 'e.id AS enrollment_id, enrollment_status_type.code AS enrollment_status_type, '
            . 'enrollment_status.code AS enrollment_status_code, '
            . 'g.id AS grade_id, g.name AS grade_name, g.sort_order AS grade_sort_order, '
            . 'sec.id AS section_id, sec.name AS section_name, '
            . 'fs.id AS family_student_id, fs.family_id AS current_family_id, '
            . 'f.id AS family_id, family_status_type.code AS family_status_type, '
            . 'family_status.code AS family_status_code, '
            . 'saa.id AS student_address_assignment_id, saa.family_id AS address_assignment_family_id, '
            . 'saa.family_address_id AS family_address_id, fa.family_id AS address_family_id, '
            . 'fa.main_street, fa.street_number, fa.secondary_street, fa.sector, fa.reference, '
            . 'address_status_type.code AS address_status_type, address_status.code AS address_status_code '
            . 'FROM students s '
            . 'INNER JOIN persons sp ON sp.id = s.person_id '
            . 'LEFT JOIN document_types student_document_type ON student_document_type.id = sp.document_type_id '
            . 'INNER JOIN statuses student_status ON student_status.id = s.status_id '
            . 'INNER JOIN status_types student_status_type '
            . 'ON student_status_type.id = student_status.status_type_id '
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
            . 'LEFT JOIN student_address_assignments saa ON saa.student_id = s.id AND saa.ended_at IS NULL '
            . 'LEFT JOIN family_addresses fa ON fa.id = saa.family_address_id '
            . 'LEFT JOIN statuses address_status ON address_status.id = fa.status_id '
            . 'LEFT JOIN status_types address_status_type ON address_status_type.id = address_status.status_type_id '
            . 'WHERE ' . $gradeSectionPredicate . ' '
            . 'ORDER BY CASE WHEN g.id IS NULL THEN 1 ELSE 0 END, g.sort_order, g.id, '
            . 'CASE WHEN sec.id IS NULL THEN 1 ELSE 0 END, sec.name, sec.id, '
            . 'sp.first_surname, sp.second_surname, sp.first_name, sp.middle_name, s.id',
            $parameters,
        );

        $seen = [];
        $students = [];
        $familyIds = [];
        foreach ($rows as $row) {
            $studentId = $this->positiveInt($row['student_id'] ?? null, 'Student identity');
            if (isset($seen[$studentId])) {
                throw new RuntimeException('Student directory returned duplicate current Family or Address rows.');
            }
            $seen[$studentId] = true;
            $active = $this->isActiveStudent($row);
            $status = $this->reportingStatus($row);
            [$gradeId, $gradeName, $sectionId, $sectionName] = $this->placement($row);
            $studentFirstName = $this->requiredString(
                $row['student_first_name'] ?? null,
                'Student first name',
            );
            $studentMiddleName = $this->nullableString(
                $row['student_middle_name'] ?? null,
                'Student middle name',
            );
            $studentFirstSurname = $this->requiredString(
                $row['student_first_surname'] ?? null,
                'Student first surname',
            );
            $studentSecondSurname = $this->nullableString(
                $row['student_second_surname'] ?? null,
                'Student second surname',
            );
            [$studentIdentificationType, $studentIdentificationNumber] = $this->identification(
                $row['student_document_type_id'] ?? null,
                $row['student_document_type_name'] ?? null,
                $row['student_document_number'] ?? null,
                'Student',
            );
            $currentFamilyId = $this->currentFamily($row);
            $address = $this->address($row, $currentFamilyId);

            if (!$active) {
                continue;
            }
            if ($currentFamilyId !== null) {
                $familyIds[$currentFamilyId] = $currentFamilyId;
            }
            $students[] = [
                'studentId' => $studentId,
                'gradeId' => $gradeId,
                'gradeName' => $gradeName,
                'sectionId' => $sectionId,
                'sectionName' => $sectionName,
                'firstName' => $studentFirstName,
                'middleName' => $studentMiddleName,
                'firstSurname' => $studentFirstSurname,
                'secondSurname' => $studentSecondSurname,
                'identificationType' => $studentIdentificationType,
                'identificationNumber' => $studentIdentificationNumber,
                'familyId' => $currentFamilyId,
                'address' => $address,
                'status' => $status,
            ];
        }

        $representativesByFamily = $this->representativesByFamily(array_values($familyIds));
        $result = [];
        foreach ($students as $student) {
            $memberships = $student['familyId'] === null
                ? []
                : ($representativesByFamily[$student['familyId']] ?? []);
            [$representative1, $representative2] = $this->selectRepresentatives($memberships);
            $result[] = new StudentRepresentativeDirectoryRow(
                $student['studentId'],
                $student['gradeId'],
                $student['gradeName'],
                $student['sectionId'],
                $student['sectionName'],
                $student['firstName'],
                $student['middleName'],
                $student['firstSurname'],
                $student['secondSurname'],
                $student['identificationType'],
                $student['identificationNumber'],
                $representative1,
                $representative2,
                $student['address'],
                $student['status'],
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
            throw new RuntimeException("Student directory returned incomplete {$owner} identification.");
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
        $membershipId = $this->nullablePositiveInt(
            $row['family_student_id'] ?? null,
            'FamilyStudent identity',
        );
        $familyId = $this->nullablePositiveInt($row['current_family_id'] ?? null, 'current Family identity');
        if ($membershipId === null && $familyId === null) {
            foreach (['family_id', 'family_status_type', 'family_status_code'] as $field) {
                if (($row[$field] ?? null) !== null) {
                    throw new RuntimeException('Student directory returned Family data without current membership.');
                }
            }

            return null;
        }
        if ($membershipId === null || $familyId === null
            || $this->positiveInt($row['family_id'] ?? null, 'Family identity') !== $familyId
        ) {
            throw new RuntimeException('Student directory returned an incomplete current Family membership.');
        }
        if (($row['family_status_type'] ?? null) !== self::STUDENT_STATUS_TYPE
            || ($row['family_status_code'] ?? null) !== 'ACTIVE'
        ) {
            throw new RuntimeException('Student directory returned a non-active current Family.');
        }

        return $familyId;
    }

    /** @param list<int> $familyIds
     *  @return array<int, list<array{primary: bool, relationshipCode: string, projection: DirectoryRepresentative}>>
     */
    private function representativesByFamily(array $familyIds): array
    {
        if ($familyIds === []) {
            return [];
        }
        $parameters = [];
        $placeholders = [];
        foreach ($familyIds as $index => $familyId) {
            $placeholder = ':directoryFamily' . $index;
            $placeholders[] = $placeholder;
            $parameters[$placeholder] = $familyId;
        }
        $rows = $this->rows(
            'SELECT fr.id AS membership_id, fr.family_id, fr.representative_id, fr.is_primary, '
            . 'fr.relationship_type_id, relationship_type.code AS relationship_code, '
            . 'relationship_type.name AS relationship_name, r.person_id AS representative_person_id, '
            . 'representative_status_type.code AS representative_status_type, '
            . 'representative_status.code AS representative_status_code, '
            . 'p.first_name, p.middle_name, p.first_surname, p.second_surname, '
            . 'p.document_type_id, document_type.name AS document_type_name, p.document_number, '
            . 'p.mobile_phone, p.landline_phone, p.email AS personal_email '
            . 'FROM family_representatives fr '
            . 'LEFT JOIN relationship_types relationship_type '
            . 'ON relationship_type.id = fr.relationship_type_id '
            . 'LEFT JOIN representatives r ON r.id = fr.representative_id '
            . 'LEFT JOIN statuses representative_status ON representative_status.id = r.status_id '
            . 'LEFT JOIN status_types representative_status_type '
            . 'ON representative_status_type.id = representative_status.status_type_id '
            . 'LEFT JOIN persons p ON p.id = r.person_id '
            . 'LEFT JOIN document_types document_type ON document_type.id = p.document_type_id '
            . 'WHERE fr.ended_at IS NULL AND fr.family_id IN (' . implode(', ', $placeholders) . ') '
            . 'ORDER BY fr.family_id, fr.id',
            $parameters,
        );

        $result = [];
        $seen = [];
        foreach ($rows as $row) {
            $familyId = $this->positiveInt($row['family_id'] ?? null, 'Representative Family identity');
            $membershipId = $this->positiveInt(
                $row['membership_id'] ?? null,
                'FamilyRepresentative identity',
            );
            $representativeId = $this->positiveInt(
                $row['representative_id'] ?? null,
                'Representative identity',
            );
            $key = $familyId . ':' . $representativeId;
            if (isset($seen[$key])) {
                throw new RuntimeException('Student directory returned duplicate active Representative membership.');
            }
            $seen[$key] = $membershipId;
            $this->positiveInt($row['representative_person_id'] ?? null, 'Representative Person identity');
            $this->positiveInt($row['relationship_type_id'] ?? null, 'RelationshipType identity');
            if (($row['representative_status_type'] ?? null) !== self::STUDENT_STATUS_TYPE
                || !in_array($row['representative_status_code'] ?? null, ['ACTIVE', 'INACTIVE'], true)
            ) {
                throw new RuntimeException('Student directory returned an invalid Representative GENERAL_STATUS.');
            }
            $isPrimary = $this->nullableBoolean($row['is_primary'] ?? null, 'Primary Representative flag');
            if ($isPrimary === null) {
                throw new RuntimeException('Student directory returned an undefined Primary Representative flag.');
            }
            $relationshipCode = $this->requiredString(
                $row['relationship_code'] ?? null,
                'RelationshipType code',
            );
            [$identificationType, $identificationNumber] = $this->identification(
                $row['document_type_id'] ?? null,
                $row['document_type_name'] ?? null,
                $row['document_number'] ?? null,
                'Representative',
            );
            $result[$familyId][] = [
                'primary' => $isPrimary,
                'relationshipCode' => $relationshipCode,
                'projection' => new DirectoryRepresentative(
                    $this->requiredString($row['first_name'] ?? null, 'Representative first name'),
                    $this->nullableString($row['middle_name'] ?? null, 'Representative middle name'),
                    $this->requiredString($row['first_surname'] ?? null, 'Representative first surname'),
                    $this->nullableString($row['second_surname'] ?? null, 'Representative second surname'),
                    $this->requiredString($row['relationship_name'] ?? null, 'RelationshipType name'),
                    $identificationType,
                    $identificationNumber,
                    $this->nullableString($row['mobile_phone'] ?? null, 'Representative mobile phone'),
                    $this->nullableString($row['landline_phone'] ?? null, 'Representative landline phone'),
                    $this->nullableString($row['personal_email'] ?? null, 'Representative personal email'),
                ),
            ];
        }

        return $result;
    }

    /** @param list<array{primary: bool, relationshipCode: string, projection: DirectoryRepresentative}> $memberships
     *  @return array{?DirectoryRepresentative, ?DirectoryRepresentative}
     */
    private function selectRepresentatives(array $memberships): array
    {
        $primary = array_values(array_filter(
            $memberships,
            static fn (array $membership): bool => $membership['primary'],
        ));
        if (count($primary) > 1) {
            throw new RuntimeException('Student directory returned multiple active Primary Representatives.');
        }
        if ($primary === []) {
            return [null, null];
        }

        $primaryMembership = $primary[0];
        $secondary = array_values(array_filter(
            $memberships,
            static fn (array $membership): bool => !$membership['primary'],
        ));
        if ($secondary === []) {
            return [$primaryMembership['projection'], null];
        }
        if (count($secondary) === 1) {
            return [$primaryMembership['projection'], $secondary[0]['projection']];
        }

        $targetCodes = match ($primaryMembership['relationshipCode']) {
            'FATHER' => ['MOTHER'],
            'MOTHER' => ['FATHER'],
            default => ['FATHER', 'MOTHER'],
        };
        foreach ($targetCodes as $targetCode) {
            $candidates = array_values(array_filter(
                $secondary,
                static fn (array $membership): bool => $membership['relationshipCode'] === $targetCode,
            ));
            if (count($candidates) > 1) {
                throw new RuntimeException(
                    "Student directory returned multiple active secondary {$targetCode} Representatives."
                );
            }
            if ($candidates !== []) {
                return [$primaryMembership['projection'], $candidates[0]['projection']];
            }
        }

        return [$primaryMembership['projection'], null];
    }

    /** @param array<string, mixed> $row */
    private function address(array $row, ?int $currentFamilyId): ?string
    {
        $assignmentId = $this->nullablePositiveInt(
            $row['student_address_assignment_id'] ?? null,
            'StudentAddressAssignment identity',
        );
        if ($assignmentId === null) {
            $addressFields = [
                'address_assignment_family_id',
                'family_address_id',
                'address_family_id',
                'main_street',
            ];
            foreach ($addressFields as $field) {
                if (($row[$field] ?? null) !== null) {
                    throw new RuntimeException('Student directory returned Address data without assignment.');
                }
            }

            return null;
        }
        $assignmentFamilyId = $this->positiveInt(
            $row['address_assignment_family_id'] ?? null,
            'Address assignment Family identity',
        );
        $addressFamilyId = $this->positiveInt(
            $row['address_family_id'] ?? null,
            'Address Family identity',
        );
        $this->positiveInt($row['family_address_id'] ?? null, 'FamilyAddress identity');
        if ($currentFamilyId === null
            || $assignmentFamilyId !== $currentFamilyId
            || $addressFamilyId !== $currentFamilyId
        ) {
            throw new RuntimeException('Student directory returned Address outside the current Family.');
        }
        if (($row['address_status_type'] ?? null) !== self::STUDENT_STATUS_TYPE
            || ($row['address_status_code'] ?? null) !== 'ACTIVE'
        ) {
            throw new RuntimeException('Student directory returned a non-active current FamilyAddress.');
        }

        $address = new Address(
            $this->requiredString($row['main_street'] ?? null, 'Address main street'),
            $this->nullableString($row['street_number'] ?? null, 'Address street number'),
            $this->nullableString($row['secondary_street'] ?? null, 'Address secondary street'),
            $this->nullableString($row['sector'] ?? null, 'Address sector'),
            $this->nullableString($row['reference'] ?? null, 'Address reference'),
            null,
        );
        $first = $address->mainStreet();
        if ($address->streetNumber() !== null) {
            $first .= ' ' . $address->streetNumber();
        }

        return implode(', ', array_filter([
            $first,
            $address->secondaryStreet(),
            $address->sector(),
            $address->reference(),
        ], static fn (?string $part): bool => $part !== null && $part !== ''));
    }
}
