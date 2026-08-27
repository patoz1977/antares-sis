<?php

declare(strict_types=1);

namespace App\Enrollment\Infrastructure\Reporting;

use App\Enrollment\Application\Reporting\EnrollmentReportingStatus;
use App\Enrollment\Domain\ValueObject\BillingInformation;
use App\Enrollment\Domain\ValueObject\IdentificationTypeId;
use App\Enrollment\Domain\ValueObject\MedicalInformation;
use Core\Database\ConnectionManager;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

abstract class PdoEnrollmentReportingQuery
{
    protected const STUDENT_STATUS_TYPE = 'GENERAL_STATUS';

    protected const ENROLLMENT_STATUS_TYPE = 'ENROLLMENT_STATUS';

    protected PDO $connection;

    public function __construct(ConnectionManager $connectionManager)
    {
        $this->connection = $connectionManager->connection();
    }

    /** @param array<string, int|string> $parameters
     *  @return list<array<string, mixed>>
     */
    protected function rows(string $sql, array $parameters = []): array
    {
        $statement = $this->connection->prepare($sql);
        $statement->execute($parameters);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    protected function positiveInt(mixed $value, string $field): int
    {
        if (is_int($value)) {
            $integer = $value;
        } elseif (is_string($value) && preg_match('/^[0-9]+$/D', $value) === 1) {
            $integer = (int) $value;
        } else {
            throw new RuntimeException("Reporting projection returned invalid {$field}.");
        }

        if ($integer <= 0) {
            throw new RuntimeException("Reporting projection returned invalid {$field}.");
        }

        return $integer;
    }

    protected function nullablePositiveInt(mixed $value, string $field): ?int
    {
        return $value === null ? null : $this->positiveInt($value, $field);
    }

    protected function requiredString(mixed $value, string $field): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException("Reporting projection returned invalid {$field}.");
        }

        return $value;
    }

    protected function nullableString(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new RuntimeException("Reporting projection returned invalid {$field}.");
        }

        return $value;
    }

    protected function date(mixed $value, string $field): string
    {
        $dateValue = $this->requiredString($value, $field);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateValue, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false
            || $date->format('Y-m-d') !== $dateValue
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        ) {
            throw new RuntimeException("Reporting projection returned invalid {$field}.");
        }

        return $dateValue;
    }

    /** @param array<string, mixed> $row */
    protected function isActiveStudent(array $row): bool
    {
        if (($row['student_status_type'] ?? null) !== self::STUDENT_STATUS_TYPE) {
            throw new RuntimeException('Reporting Student status does not belong to GENERAL_STATUS.');
        }

        return match ($row['student_status_code'] ?? null) {
            'ACTIVE' => true,
            'INACTIVE' => false,
            default => throw new RuntimeException('Reporting Student has an unsupported GENERAL_STATUS value.'),
        };
    }

    /** @param array<string, mixed> $row */
    protected function reportingStatus(array $row): EnrollmentReportingStatus
    {
        if (($row['enrollment_id'] ?? null) === null) {
            if (($row['enrollment_status_type'] ?? null) !== null
                || ($row['enrollment_status_code'] ?? null) !== null
            ) {
                throw new RuntimeException('Reporting projection returned Enrollment status without Enrollment.');
            }

            return EnrollmentReportingStatus::NotStarted;
        }

        $this->positiveInt($row['enrollment_id'], 'Enrollment identity');
        if (($row['enrollment_status_type'] ?? null) !== self::ENROLLMENT_STATUS_TYPE) {
            throw new RuntimeException('Reporting Enrollment status does not belong to ENROLLMENT_STATUS.');
        }

        $code = $row['enrollment_status_code'] ?? null;
        if (!is_string($code)) {
            throw new RuntimeException('Reporting Enrollment has an invalid status code.');
        }

        return EnrollmentReportingStatus::fromEnrollmentCode($code);
    }

    /** @param array<string, mixed> $row
     *  @return array{?int, ?string, ?int, ?string}
     */
    protected function placement(array $row): array
    {
        $gradeId = $this->nullablePositiveInt($row['grade_id'] ?? null, 'Grade identity');
        $gradeName = $this->nullableString($row['grade_name'] ?? null, 'Grade name');
        $gradeSortOrder = $this->nullablePositiveInt(
            $row['grade_sort_order'] ?? null,
            'Grade sort order',
        );
        $sectionId = $this->nullablePositiveInt($row['section_id'] ?? null, 'Section identity');
        $sectionName = $this->nullableString($row['section_name'] ?? null, 'Section name');

        if ($gradeId === null) {
            if ($gradeName !== null || $gradeSortOrder !== null || $sectionId !== null || $sectionName !== null) {
                throw new RuntimeException('Reporting projection returned Section or Grade data without Grade.');
            }

            return [null, null, null, null];
        }
        if ($gradeName === null || trim($gradeName) === '' || $gradeSortOrder === null) {
            throw new RuntimeException('Reporting projection returned an incomplete Grade.');
        }
        if (($sectionId === null) !== ($sectionName === null)) {
            throw new RuntimeException('Reporting projection returned an incomplete Section.');
        }
        if ($sectionName !== null && trim($sectionName) === '') {
            throw new RuntimeException('Reporting projection returned an invalid Section name.');
        }

        return [$gradeId, $gradeName, $sectionId, $sectionName];
    }

    /** @param array<string, mixed> $row
     *  @return array{string, string}
     */
    protected function studentName(array $row): array
    {
        $names = $this->joinName([
            $this->requiredString($row['student_first_name'] ?? null, 'Student first name'),
            $this->nullableString($row['student_middle_name'] ?? null, 'Student middle name'),
        ]);
        $surnames = $this->joinName([
            $this->requiredString($row['student_first_surname'] ?? null, 'Student first surname'),
            $this->nullableString($row['student_second_surname'] ?? null, 'Student second surname'),
        ]);

        return [$surnames, $names];
    }

    /** @param list<?string> $parts */
    protected function joinName(array $parts): string
    {
        return implode(' ', array_values(array_filter(
            $parts,
            static fn (?string $part): bool => $part !== null && trim($part) !== '',
        )));
    }

    protected function nullableBoolean(mixed $value, string $field): ?bool
    {
        if ($value === null) {
            return null;
        }

        return match ($value) {
            0, '0', false => false,
            1, '1', true => true,
            default => throw new RuntimeException("Reporting projection returned invalid {$field}."),
        };
    }

    /** @param array<string, mixed> $row */
    protected function billingInformation(array $row): ?BillingInformation
    {
        $fields = [
            $row['billing_identification_type_id'] ?? null,
            $row['billing_identification_number'] ?? null,
            $row['billing_legal_name'] ?? null,
            $row['billing_address'] ?? null,
            $row['billing_email'] ?? null,
            $row['billing_phone'] ?? null,
        ];
        $defined = count(array_filter($fields, static fn (mixed $value): bool => $value !== null));
        if ($defined === 0) {
            return null;
        }
        if ($defined !== count($fields)) {
            throw new RuntimeException('Reporting projection returned incomplete BillingInformation.');
        }

        return new BillingInformation(
            new IdentificationTypeId($this->positiveInt($fields[0], 'Billing identification type')),
            $this->requiredString($fields[1], 'Billing identification number'),
            $this->requiredString($fields[2], 'Billing legal name'),
            $this->requiredString($fields[3], 'Billing address'),
            $this->requiredString($fields[4], 'Billing email'),
            $this->requiredString($fields[5], 'Billing phone'),
        );
    }

    /** @param array<string, mixed> $row */
    protected function medicalInformation(array $row): ?MedicalInformation
    {
        $columns = [
            'has_medical_condition',
            'medical_condition_detail',
            'has_allergies',
            'allergy_detail',
            'takes_permanent_medication',
            'medication_name',
            'requires_special_care',
            'special_care_detail',
            'has_medical_insurance',
            'insurance_provider',
            'pediatrician_name',
            'pediatrician_phone',
            'medical_observations',
        ];
        $defined = count(array_filter(
            $columns,
            static fn (string $column): bool => ($row[$column] ?? null) !== null,
        ));
        if ($defined === 0) {
            return null;
        }

        foreach (['has_medical_condition', 'has_allergies', 'takes_permanent_medication',
            'requires_special_care', 'has_medical_insurance'] as $booleanColumn) {
            if (($row[$booleanColumn] ?? null) === null) {
                throw new RuntimeException('Reporting projection returned incomplete MedicalInformation.');
            }
        }

        return new MedicalInformation(
            $this->nullableBoolean($row['has_medical_condition'], 'medical condition flag') ?? false,
            $this->nullableString($row['medical_condition_detail'] ?? null, 'medical condition detail'),
            $this->nullableBoolean($row['has_allergies'], 'allergy flag') ?? false,
            $this->nullableString($row['allergy_detail'] ?? null, 'allergy detail'),
            $this->nullableBoolean($row['takes_permanent_medication'], 'medication flag') ?? false,
            $this->nullableString($row['medication_name'] ?? null, 'medication name'),
            $this->nullableBoolean($row['requires_special_care'], 'special care flag') ?? false,
            $this->nullableString($row['special_care_detail'] ?? null, 'special care detail'),
            $this->nullableBoolean($row['has_medical_insurance'], 'insurance flag') ?? false,
            $this->nullableString($row['insurance_provider'] ?? null, 'insurance provider'),
            $this->nullableString($row['pediatrician_name'] ?? null, 'pediatrician name'),
            $this->nullableString($row['pediatrician_phone'] ?? null, 'pediatrician phone'),
            $this->nullableString($row['medical_observations'] ?? null, 'medical observations'),
        );
    }
}
