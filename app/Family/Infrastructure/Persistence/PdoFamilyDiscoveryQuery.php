<?php

declare(strict_types=1);

namespace App\Family\Infrastructure\Persistence;

use App\Family\Application\Discovery\Dto\FamilyDirectoryRepresentative;
use App\Family\Application\Discovery\Dto\FamilyDirectoryStudent;
use App\Family\Application\Discovery\Dto\FamilyDiscoveryRow;
use App\Family\Application\Discovery\Dto\FamilyMatchedMember;
use App\Family\Application\Discovery\FamilyDirectoryCriteria;
use App\Family\Application\Discovery\FamilyDiscoveryCriteria;
use App\Family\Application\Discovery\FamilyDiscoveryField;
use App\Family\Application\Discovery\FamilyDiscoveryQuery;
use App\Person\Application\Discovery\PersonDiscoveryCriteria;
use App\Person\Application\Discovery\PersonDiscoveryField;
use Core\Database\ConnectionManager;
use PDO;
use RuntimeException;

final readonly class PdoFamilyDiscoveryQuery implements FamilyDiscoveryQuery
{
    private PDO $connection;

    public function __construct(ConnectionManager $connectionManager)
    {
        $this->connection = $connectionManager->connection();
    }

    public function search(FamilyDirectoryCriteria $directory, int $academicPeriodId): array
    {
        if ($academicPeriodId <= 0) {
            throw new RuntimeException('Family directory requires a valid AcademicPeriod identity.');
        }
        [$where, $parameters] = $directory->search === null
            ? ['1 = 1', []]
            : $this->familyPredicate($directory->search);
        $statusCode = $directory->status->persistedCode();
        if ($statusCode !== null) {
            $where = '(' . $where . ') AND s.code = :familyStatus';
            $parameters[':familyStatus'] = $statusCode;
        }
        $statement = $this->connection->prepare(
            'SELECT f.id, f.family_code, f.display_name, s.code AS status_code, '
            . 'st.code AS status_type FROM families f '
            . 'INNER JOIN statuses s ON s.id = f.status_id '
            . 'INNER JOIN status_types st ON st.id = s.status_type_id '
            . 'WHERE ' . $where . ' ORDER BY f.display_name, f.family_code, f.id LIMIT 31'
        );
        $statement->execute($parameters);
        $familyRows = $statement->fetchAll(PDO::FETCH_ASSOC);

        $visibleFamilyIds = [];
        foreach (array_slice($familyRows, 0, 30) as $row) {
            $visibleFamilyIds[] = $this->positiveInt($row['id'] ?? null, 'Family identity');
        }
        $membersByFamily = $directory->search?->isPersonSearch() === true
            ? $this->matchedMembers($visibleFamilyIds, $directory->search)
            : [];
        $representativesByFamily = $this->primaryRepresentatives($visibleFamilyIds);
        $studentsByFamily = $this->students($visibleFamilyIds, $academicPeriodId);

        $result = [];
        foreach ($familyRows as $row) {
            $familyId = $this->positiveInt($row['id'] ?? null, 'Family identity');
            if (($row['status_type'] ?? null) !== 'GENERAL_STATUS') {
                throw new RuntimeException('Family discovery returned an invalid status type.');
            }
            $result[] = new FamilyDiscoveryRow(
                $familyId,
                $this->requiredString($row['family_code'] ?? null, 'FamilyCode'),
                $this->requiredString($row['display_name'] ?? null, 'Family display name'),
                $this->requiredString($row['status_code'] ?? null, 'Family status'),
                $membersByFamily[$familyId] ?? [],
                $representativesByFamily[$familyId] ?? null,
                $studentsByFamily[$familyId] ?? [],
            );
        }

        return $result;
    }

    /** @param list<int> $familyIds
     *  @return array<int, FamilyDirectoryRepresentative>
     */
    private function primaryRepresentatives(array $familyIds): array
    {
        if ($familyIds === []) {
            return [];
        }
        [$placeholders, $parameters] = $this->familyParameters($familyIds, 'primaryFamily');
        $statement = $this->connection->prepare(
            'SELECT fr.family_id, r.id AS representative_id, p.first_name, p.middle_name, '
            . 'p.first_surname, p.second_surname, p.mobile_phone, p.email '
            . 'FROM family_representatives fr '
            . 'INNER JOIN representatives r ON r.id = fr.representative_id '
            . 'INNER JOIN persons p ON p.id = r.person_id '
            . 'WHERE fr.ended_at IS NULL AND fr.is_primary = 1 '
            . 'AND fr.family_id IN (' . implode(', ', $placeholders) . ') '
            . 'ORDER BY fr.family_id, r.id'
        );
        $statement->execute($parameters);

        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $familyId = $this->positiveInt($row['family_id'] ?? null, 'Family identity');
            $this->positiveInt($row['representative_id'] ?? null, 'Representative identity');
            if (isset($result[$familyId])) {
                throw new RuntimeException('Family directory returned multiple active primary Representatives.');
            }
            $result[$familyId] = new FamilyDirectoryRepresentative(
                $this->fullName($row),
                $this->optionalString($row['mobile_phone'] ?? null),
                $this->optionalString($row['email'] ?? null),
            );
        }

        return $result;
    }

    /** @param list<int> $familyIds
     *  @return array<int, list<FamilyDirectoryStudent>>
     */
    private function students(array $familyIds, int $academicPeriodId): array
    {
        if ($familyIds === []) {
            return [];
        }
        [$placeholders, $parameters] = $this->familyParameters($familyIds, 'studentFamily');
        $parameters[':academicPeriodId'] = $academicPeriodId;
        $statement = $this->connection->prepare(
            'SELECT fs.family_id, stu.id AS student_id, p.first_name, p.middle_name, '
            . 'p.first_surname, p.second_surname, e.id AS enrollment_id, '
            . 'est.code AS enrollment_status, estt.code AS enrollment_status_type, '
            . 'g.id AS grade_id, g.name AS grade_name, sec.id AS section_id, sec.name AS section_name '
            . 'FROM family_students fs '
            . 'INNER JOIN students stu ON stu.id = fs.student_id '
            . 'INNER JOIN persons p ON p.id = stu.person_id '
            . 'LEFT JOIN enrollments e ON e.student_id = stu.id '
            . 'AND e.academic_period_id = :academicPeriodId '
            . 'LEFT JOIN statuses est ON est.id = e.status_id '
            . 'LEFT JOIN status_types estt ON estt.id = est.status_type_id '
            . 'LEFT JOIN grades g ON g.id = e.grade_id '
            . 'LEFT JOIN sections sec ON sec.id = e.section_id '
            . 'WHERE fs.ended_at IS NULL AND fs.family_id IN (' . implode(', ', $placeholders) . ') '
            . 'ORDER BY fs.family_id, p.first_surname, p.second_surname, '
            . 'p.first_name, p.middle_name, stu.id'
        );
        $statement->execute($parameters);

        $result = [];
        $seen = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $familyId = $this->positiveInt($row['family_id'] ?? null, 'Family identity');
            $studentId = $this->positiveInt($row['student_id'] ?? null, 'Student identity');
            $key = $familyId . ':' . $studentId;
            if (isset($seen[$key])) {
                throw new RuntimeException('Family directory returned a duplicate current Student membership.');
            }
            $seen[$key] = true;

            $enrollmentId = $row['enrollment_id'] ?? null;
            if ($enrollmentId !== null) {
                $this->positiveInt($enrollmentId, 'Enrollment identity');
                if (($row['enrollment_status_type'] ?? null) !== 'ENROLLMENT_STATUS') {
                    throw new RuntimeException('Family directory returned an invalid Enrollment status type.');
                }
                $this->requiredString($row['enrollment_status'] ?? null, 'Enrollment status');
            }
            $gradeId = $row['grade_id'] ?? null;
            $sectionId = $row['section_id'] ?? null;
            $grade = null;
            $section = null;
            if ($gradeId !== null) {
                $this->positiveInt($gradeId, 'Grade identity');
                $grade = $this->requiredString($row['grade_name'] ?? null, 'Grade name');
            }
            if ($sectionId !== null) {
                if ($grade === null) {
                    throw new RuntimeException('Family directory returned Section without Grade.');
                }
                $this->positiveInt($sectionId, 'Section identity');
                $section = $this->requiredString($row['section_name'] ?? null, 'Section name');
            }

            $result[$familyId][] = new FamilyDirectoryStudent(
                $studentId,
                $this->fullName($row),
                $grade,
                $section,
            );
        }

        return $result;
    }

    /** @param list<int> $familyIds
     *  @return array{list<string>, array<string, int>}
     */
    private function familyParameters(array $familyIds, string $prefix): array
    {
        $placeholders = [];
        $parameters = [];
        foreach ($familyIds as $index => $familyId) {
            $placeholder = ':' . $prefix . $index;
            $placeholders[] = $placeholder;
            $parameters[$placeholder] = $familyId;
        }

        return [$placeholders, $parameters];
    }

    /** @return array{string, array<string, string>} */
    private function familyPredicate(FamilyDiscoveryCriteria $criteria): array
    {
        if ($criteria->field === FamilyDiscoveryField::FamilyCode) {
            return ['f.family_code = :familyCode', [':familyCode' => $criteria->value]];
        }
        if ($criteria->field === FamilyDiscoveryField::DisplayName) {
            $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $criteria->value);

            return [
                "(f.display_name LIKE :displayStart ESCAPE '!' OR f.display_name LIKE :displayWord ESCAPE '!')",
                [':displayStart' => $escaped . '%', ':displayWord' => '% ' . $escaped . '%'],
            ];
        }

        [$representativePredicate, $representativeParameters] = self::personPredicate(
            $criteria->personCriteria,
            'rp',
            'familyRepresentative',
        );
        [$studentPredicate, $studentParameters] = self::personPredicate(
            $criteria->personCriteria,
            'sp',
            'familyStudent',
        );

        return [
            '(EXISTS (SELECT 1 FROM family_representatives fr '
            . 'INNER JOIN representatives r ON r.id = fr.representative_id '
            . 'INNER JOIN persons rp ON rp.id = r.person_id '
            . 'WHERE fr.family_id = f.id AND fr.ended_at IS NULL AND ' . $representativePredicate . ') '
            . 'OR EXISTS (SELECT 1 FROM family_students fs '
            . 'INNER JOIN students stu ON stu.id = fs.student_id '
            . 'INNER JOIN persons sp ON sp.id = stu.person_id '
            . 'WHERE fs.family_id = f.id AND fs.ended_at IS NULL AND ' . $studentPredicate . '))',
            array_merge($representativeParameters, $studentParameters),
        ];
    }

    /** @param list<int> $familyIds
     *  @return array<int, list<FamilyMatchedMember>>
     */
    private function matchedMembers(array $familyIds, FamilyDiscoveryCriteria $criteria): array
    {
        if ($familyIds === []) {
            return [];
        }

        $familyParameters = [];
        $representativeFamilyPlaceholders = [];
        $studentFamilyPlaceholders = [];
        foreach ($familyIds as $index => $familyId) {
            $representativePlaceholder = ':representativeFamilyId' . $index;
            $studentPlaceholder = ':studentFamilyId' . $index;
            $representativeFamilyPlaceholders[] = $representativePlaceholder;
            $studentFamilyPlaceholders[] = $studentPlaceholder;
            $familyParameters[$representativePlaceholder] = $familyId;
            $familyParameters[$studentPlaceholder] = $familyId;
        }
        $representativeFamilyList = implode(', ', $representativeFamilyPlaceholders);
        $studentFamilyList = implode(', ', $studentFamilyPlaceholders);
        [$representativePredicate, $representativeParameters] = self::personPredicate(
            $criteria->personCriteria,
            'p',
            'memberRepresentative',
        );
        [$studentPredicate, $studentParameters] = self::personPredicate(
            $criteria->personCriteria,
            'p',
            'memberStudent',
        );

        $sql = 'SELECT fr.family_id, p.id AS person_id, p.first_name, p.middle_name, '
            . 'p.first_surname, p.second_surname, 1 AS role_order, rt.name AS role '
            . 'FROM family_representatives fr '
            . 'INNER JOIN representatives r ON r.id = fr.representative_id '
            . 'INNER JOIN persons p ON p.id = r.person_id '
            . 'INNER JOIN relationship_types rt ON rt.id = fr.relationship_type_id '
            . 'WHERE fr.ended_at IS NULL AND fr.family_id IN (' . $representativeFamilyList . ') AND '
            . $representativePredicate . ' UNION ALL '
            . "SELECT fs.family_id, p.id AS person_id, p.first_name, p.middle_name, "
            . "p.first_surname, p.second_surname, 2 AS role_order, 'Estudiante' AS role "
            . 'FROM family_students fs '
            . 'INNER JOIN students stu ON stu.id = fs.student_id '
            . 'INNER JOIN persons p ON p.id = stu.person_id '
            . 'WHERE fs.ended_at IS NULL AND fs.family_id IN (' . $studentFamilyList . ') AND '
            . $studentPredicate . ' ORDER BY family_id, first_surname, second_surname, '
            . 'first_name, middle_name, role_order, role, person_id';
        $statement = $this->connection->prepare($sql);
        $statement->execute(array_merge(
            $familyParameters,
            $representativeParameters,
            $studentParameters,
        ));

        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $familyId = $this->positiveInt($row['family_id'] ?? null, 'Family identity');
            $this->positiveInt($row['person_id'] ?? null, 'Person identity');
            $result[$familyId][] = new FamilyMatchedMember(
                $this->fullName($row),
                $this->requiredString($row['role'] ?? null, 'member role'),
            );
        }

        return $result;
    }

    /** @return array{string, array<string, string>} */
    private static function personPredicate(
        PersonDiscoveryCriteria $criteria,
        string $alias,
        string $parameterPrefix,
    ): array {
        $column = match ($criteria->field) {
            PersonDiscoveryField::FirstName => 'first_name',
            PersonDiscoveryField::MiddleName => 'middle_name',
            PersonDiscoveryField::FirstSurname => 'first_surname',
            PersonDiscoveryField::SecondSurname => 'second_surname',
            PersonDiscoveryField::IdentificationNumber => 'document_number',
            PersonDiscoveryField::PersonalEmail => 'email',
        };
        $parameter = ':' . $parameterPrefix . 'Exact';
        if ($criteria->field->isName()) {
            $escaped = self::escapeLike($criteria->value);

            return [
                "({$alias}.{$column} LIKE :{$parameterPrefix}Start ESCAPE '!' "
                . "OR {$alias}.{$column} LIKE :{$parameterPrefix}Word ESCAPE '!')",
                [
                    ':' . $parameterPrefix . 'Start' => $escaped . '%',
                    ':' . $parameterPrefix . 'Word' => '% ' . $escaped . '%',
                ],
            ];
        }
        if ($criteria->field === PersonDiscoveryField::IdentificationNumber) {
            return ["UPPER(TRIM({$alias}.{$column})) = {$parameter}", [$parameter => mb_strtoupper($criteria->value, 'UTF-8')]];
        }

        return ["TRIM({$alias}.{$column}) = {$parameter}", [$parameter => $criteria->value]];
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    private function positiveInt(mixed $value, string $field): int
    {
        if (is_string($value) && preg_match('/^[0-9]+$/D', $value) === 1) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value <= 0) {
            throw new RuntimeException("Family discovery returned invalid {$field}.");
        }

        return $value;
    }

    private function requiredString(mixed $value, string $field): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException("Family discovery returned invalid {$field}.");
        }

        return $value;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new RuntimeException('Family discovery returned invalid optional member data.');
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private function fullName(array $row): string
    {
        return implode(' ', array_values(array_filter([
            $this->requiredString($row['first_name'] ?? null, 'first name'),
            $this->nullableString($row['middle_name'] ?? null),
            $this->requiredString($row['first_surname'] ?? null, 'first surname'),
            $this->nullableString($row['second_surname'] ?? null),
        ], static fn (?string $part): bool => $part !== null && $part !== '')));
    }

    private function optionalString(mixed $value): ?string
    {
        $value = $this->nullableString($value);
        if ($value === null || trim($value) === '') {
            return null;
        }

        return $value;
    }
}
