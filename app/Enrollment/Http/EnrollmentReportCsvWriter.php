<?php

declare(strict_types=1);

namespace App\Enrollment\Http;

use App\Enrollment\Application\Reporting\Dto\EnrollmentSummaryReport;
use App\Enrollment\Application\Reporting\Dto\StudentBillingReportRow;
use App\Enrollment\Application\Reporting\Dto\StudentEnrollmentReportRow;
use App\Enrollment\Application\Reporting\Dto\StudentMedicalReportRow;
use App\Enrollment\Application\Reporting\Dto\StudentRepresentativeDirectoryRow;
use RuntimeException;

final class EnrollmentReportCsvWriter
{
    private const UTF8_BOM = "\xEF\xBB\xBF";

    public function summary(EnrollmentSummaryReport $report): string
    {
        $period = trim($report->academicPeriod->code . ' ' . $report->academicPeriod->name);

        return $this->write(
            ['AcademicPeriod', 'Grade', 'Section', 'EnrollmentStatus', 'Count'],
            array_map(
                fn ($row): array => [
                    $this->text($period),
                    $this->text($row->gradeName),
                    $this->text($row->sectionName),
                    $this->text($row->status->value),
                    $row->count,
                ],
                $report->rows,
            ),
        );
    }

    /** @param list<StudentEnrollmentReportRow> $rows */
    public function students(array $rows): string
    {
        return $this->write(
            ['Grade', 'Section', 'Student', 'Status'],
            array_map(
                fn (StudentEnrollmentReportRow $row): array => [
                    $this->text($row->gradeName),
                    $this->text($row->sectionName),
                    $this->text($this->studentName($row->studentSurnames, $row->studentNames)),
                    $this->text($row->status->value),
                ],
                $rows,
            ),
        );
    }

    /** @param list<StudentRepresentativeDirectoryRow> $rows */
    public function directory(array $rows): string
    {
        return $this->write(
            [
                'Grade', 'Section', 'Student', 'StudentIdentificationType',
                'StudentIdentificationNumber', 'PrimaryRepresentative',
                'RepresentativeIdentificationType', 'RepresentativeIdentificationNumber',
                'MobilePhone', 'LandlinePhone', 'PersonalEmail', 'WorkPhone', 'WorkEmail',
                'Address', 'Status',
            ],
            array_map(
                fn (StudentRepresentativeDirectoryRow $row): array => [
                    $this->text($row->gradeName),
                    $this->text($row->sectionName),
                    $this->text($this->studentName($row->studentSurnames, $row->studentNames)),
                    $this->text($row->studentIdentificationType),
                    $this->text($row->studentIdentificationNumber),
                    $this->text($this->studentName($row->representativeSurnames, $row->representativeNames)),
                    $this->text($row->representativeIdentificationType),
                    $this->text($row->representativeIdentificationNumber),
                    $this->text($row->representativeMobilePhone),
                    $this->text($row->representativeLandlinePhone),
                    $this->text($row->representativePersonalEmail),
                    $this->text($row->representativeWorkPhone),
                    $this->text($row->representativeWorkEmail),
                    $this->text($row->studentAddress),
                    $this->text($row->status->value),
                ],
                $rows,
            ),
        );
    }

    /** @param list<StudentBillingReportRow> $rows */
    public function billing(array $rows): string
    {
        return $this->write(
            [
                'Grade', 'Section', 'Student', 'Status', 'IdentificationType',
                'IdentificationNumber', 'LegalName', 'BillingAddress', 'BillingEmail', 'Phone',
            ],
            array_map(
                fn (StudentBillingReportRow $row): array => [
                    $this->text($row->gradeName),
                    $this->text($row->sectionName),
                    $this->text($this->studentName($row->studentSurnames, $row->studentNames)),
                    $this->text($row->status->value),
                    $this->text($row->identificationType),
                    $this->text($row->identificationNumber),
                    $this->text($row->legalName),
                    $this->text($row->billingAddress),
                    $this->text($row->billingEmail),
                    $this->text($row->phone),
                ],
                $rows,
            ),
        );
    }

    /** @param list<StudentMedicalReportRow> $rows */
    public function medical(array $rows): string
    {
        return $this->write(
            [
                'Grade', 'Section', 'Student', 'Status', 'HasMedicalCondition',
                'MedicalConditionDetail', 'HasAllergies', 'AllergyDetail',
                'TakesPermanentMedication', 'MedicationName', 'RequiresSpecialCare',
                'SpecialCareDetail', 'HasMedicalInsurance', 'InsuranceProvider',
                'PediatricianName', 'PediatricianPhone', 'Observations',
            ],
            array_map(
                fn (StudentMedicalReportRow $row): array => [
                    $this->text($row->gradeName),
                    $this->text($row->sectionName),
                    $this->text($this->studentName($row->studentSurnames, $row->studentNames)),
                    $this->text($row->status->value),
                    $this->boolean($row->hasMedicalCondition),
                    $this->text($row->medicalConditionDetail),
                    $this->boolean($row->hasAllergies),
                    $this->text($row->allergyDetail),
                    $this->boolean($row->takesPermanentMedication),
                    $this->text($row->medicationName),
                    $this->boolean($row->requiresSpecialCare),
                    $this->text($row->specialCareDetail),
                    $this->boolean($row->hasMedicalInsurance),
                    $this->text($row->insuranceProvider),
                    $this->text($row->pediatricianName),
                    $this->text($row->pediatricianPhone),
                    $this->text($row->observations),
                ],
                $rows,
            ),
        );
    }

    /** @param list<string> $header @param list<list<int|string>> $rows */
    private function write(array $header, array $rows): string
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new RuntimeException('CSV output is unavailable.');
        }

        try {
            if (fwrite($stream, self::UTF8_BOM) !== strlen(self::UTF8_BOM)) {
                throw new RuntimeException('CSV output is unavailable.');
            }
            if (fputcsv($stream, $header, ',', '"', '', "\r\n") === false) {
                throw new RuntimeException('CSV output is unavailable.');
            }
            foreach ($rows as $row) {
                if (fputcsv($stream, $row, ',', '"', '', "\r\n") === false) {
                    throw new RuntimeException('CSV output is unavailable.');
                }
            }
            rewind($stream);
            $content = stream_get_contents($stream);
            if ($content === false) {
                throw new RuntimeException('CSV output is unavailable.');
            }

            return $content;
        } finally {
            fclose($stream);
        }
    }

    private function text(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $significant = ltrim($value, ' ');
        if ($significant !== '' && in_array($significant[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }

        return $value;
    }

    private function boolean(?bool $value): string
    {
        return $value === null ? '' : ($value ? 'YES' : 'NO');
    }

    private function studentName(?string $surnames, ?string $names): string
    {
        return trim((string) $surnames . ' ' . (string) $names);
    }
}
