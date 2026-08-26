<?php

declare(strict_types=1);

namespace App\Enrollment\Infrastructure\Reporting;

use App\Enrollment\Application\Reporting\Dto\StudentRepresentativeDirectoryRow;
use App\Enrollment\Application\Reporting\StudentRepresentativeDirectoryQuery;
use App\Family\Domain\ValueObject\Address;
use RuntimeException;

final class PdoStudentRepresentativeDirectoryQuery extends PdoEnrollmentReportingQuery implements
    StudentRepresentativeDirectoryQuery
{
    public function fetch(int $academicPeriodId): array
    {
        $this->positiveInt($academicPeriodId, 'AcademicPeriod identity');
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
            . 'fr.id AS family_representative_id, fr.representative_id AS representative_id, '
            . 'r.person_id AS representative_person_id, representative_status_type.code AS representative_status_type, '
            . 'representative_status.code AS representative_status_code, '
            . 'rp.first_name AS representative_first_name, rp.middle_name AS representative_middle_name, '
            . 'rp.first_surname AS representative_first_surname, rp.second_surname AS representative_second_surname, '
            . 'rp.document_type_id AS representative_document_type_id, '
            . 'representative_document_type.name AS representative_document_type_name, '
            . 'rp.document_number AS representative_document_number, rp.mobile_phone AS representative_mobile_phone, '
            . 'rp.landline_phone AS representative_landline_phone, rp.email AS representative_personal_email, '
            . 'r.work_phone AS representative_work_phone, r.work_email AS representative_work_email, '
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
            . 'LEFT JOIN family_representatives fr ON fr.family_id = fs.family_id '
            . 'AND fr.ended_at IS NULL AND fr.is_primary = 1 '
            . 'LEFT JOIN representatives r ON r.id = fr.representative_id '
            . 'LEFT JOIN statuses representative_status ON representative_status.id = r.status_id '
            . 'LEFT JOIN status_types representative_status_type '
            . 'ON representative_status_type.id = representative_status.status_type_id '
            . 'LEFT JOIN persons rp ON rp.id = r.person_id '
            . 'LEFT JOIN document_types representative_document_type '
            . 'ON representative_document_type.id = rp.document_type_id '
            . 'LEFT JOIN student_address_assignments saa ON saa.student_id = s.id AND saa.ended_at IS NULL '
            . 'LEFT JOIN family_addresses fa ON fa.id = saa.family_address_id '
            . 'LEFT JOIN statuses address_status ON address_status.id = fa.status_id '
            . 'LEFT JOIN status_types address_status_type ON address_status_type.id = address_status.status_type_id '
            . 'ORDER BY CASE WHEN g.id IS NULL THEN 1 ELSE 0 END, g.sort_order, g.id, '
            . 'CASE WHEN sec.id IS NULL THEN 1 ELSE 0 END, sec.name, sec.id, '
            . 'sp.first_surname, sp.second_surname, sp.first_name, sp.middle_name, s.id',
            [':academicPeriodId' => $academicPeriodId],
        );

        $seen = [];
        $result = [];
        foreach ($rows as $row) {
            $studentId = $this->positiveInt($row['student_id'] ?? null, 'Student identity');
            if (isset($seen[$studentId])) {
                throw new RuntimeException(
                    'Student directory returned duplicate current Family, primary Representative or Address rows.'
                );
            }
            $seen[$studentId] = true;
            $active = $this->isActiveStudent($row);
            $status = $this->reportingStatus($row);
            [$gradeId, $gradeName, $sectionId, $sectionName] = $this->placement($row);
            [$studentSurnames, $studentNames] = $this->studentName($row);
            [$studentIdentificationType, $studentIdentificationNumber] = $this->identification(
                $row['student_document_type_id'] ?? null,
                $row['student_document_type_name'] ?? null,
                $row['student_document_number'] ?? null,
                'Student',
            );

            $currentFamilyId = $this->nullablePositiveInt(
                $row['current_family_id'] ?? null,
                'current Family identity',
            );
            $familyStudentId = $this->nullablePositiveInt(
                $row['family_student_id'] ?? null,
                'FamilyStudent identity',
            );
            if (($currentFamilyId === null) !== ($familyStudentId === null)) {
                throw new RuntimeException('Student directory returned an incomplete current Family membership.');
            }

            [$representativeSurnames, $representativeNames, $representativeIdentificationType,
                $representativeIdentificationNumber, $mobilePhone, $landlinePhone, $personalEmail,
                $workPhone, $workEmail] = $this->representative($row, $currentFamilyId);
            $address = $this->address($row, $currentFamilyId);

            if (!$active) {
                continue;
            }

            $result[] = new StudentRepresentativeDirectoryRow(
                $studentId,
                $gradeId,
                $gradeName,
                $sectionId,
                $sectionName,
                $studentSurnames,
                $studentNames,
                $studentIdentificationType,
                $studentIdentificationNumber,
                $representativeSurnames,
                $representativeNames,
                $representativeIdentificationType,
                $representativeIdentificationNumber,
                $mobilePhone,
                $landlinePhone,
                $personalEmail,
                $workPhone,
                $workEmail,
                $address,
                $status,
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

    /** @param array<string, mixed> $row
     *  @return array{?string, ?string, ?string, ?string, ?string, ?string, ?string, ?string, ?string}
     */
    private function representative(array $row, ?int $currentFamilyId): array
    {
        $membershipId = $this->nullablePositiveInt(
            $row['family_representative_id'] ?? null,
            'FamilyRepresentative identity',
        );
        if ($membershipId === null) {
            foreach (['representative_id', 'representative_person_id', 'representative_first_name',
                'representative_first_surname', 'representative_document_type_id',
                'representative_document_number', 'representative_mobile_phone',
                'representative_landline_phone', 'representative_personal_email',
                'representative_work_phone', 'representative_work_email'] as $field) {
                if (($row[$field] ?? null) !== null) {
                    throw new RuntimeException('Student directory returned Representative data without membership.');
                }
            }

            return [null, null, null, null, null, null, null, null, null];
        }
        if ($currentFamilyId === null) {
            throw new RuntimeException('Student directory returned a primary Representative without current Family.');
        }
        $this->positiveInt($row['representative_id'] ?? null, 'Representative identity');
        $this->positiveInt($row['representative_person_id'] ?? null, 'Representative Person identity');
        if (($row['representative_status_type'] ?? null) !== self::STUDENT_STATUS_TYPE
            || !in_array($row['representative_status_code'] ?? null, ['ACTIVE', 'INACTIVE'], true)
        ) {
            throw new RuntimeException('Student directory returned an invalid Representative GENERAL_STATUS.');
        }

        $names = $this->joinName([
            $this->requiredString($row['representative_first_name'] ?? null, 'Representative first name'),
            $this->nullableString($row['representative_middle_name'] ?? null, 'Representative middle name'),
        ]);
        $surnames = $this->joinName([
            $this->requiredString($row['representative_first_surname'] ?? null, 'Representative first surname'),
            $this->nullableString($row['representative_second_surname'] ?? null, 'Representative second surname'),
        ]);
        [$identificationType, $identificationNumber] = $this->identification(
            $row['representative_document_type_id'] ?? null,
            $row['representative_document_type_name'] ?? null,
            $row['representative_document_number'] ?? null,
            'Representative',
        );

        return [
            $surnames,
            $names,
            $identificationType,
            $identificationNumber,
            $this->nullableString($row['representative_mobile_phone'] ?? null, 'Representative mobile phone'),
            $this->nullableString($row['representative_landline_phone'] ?? null, 'Representative landline phone'),
            $this->nullableString($row['representative_personal_email'] ?? null, 'Representative personal email'),
            $this->nullableString($row['representative_work_phone'] ?? null, 'Representative work phone'),
            $this->nullableString($row['representative_work_email'] ?? null, 'Representative work email'),
        ];
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
