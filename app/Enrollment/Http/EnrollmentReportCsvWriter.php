<?php

declare(strict_types=1);

namespace App\Enrollment\Http;

use App\Enrollment\Application\Reporting\Dto\EnrollmentSummaryReport;
use App\Enrollment\Application\Reporting\Dto\PhysicalDepartureReportRow;
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
                'Grade', 'Section', 'EnrollmentStatus',
                'StudentFirstName', 'StudentMiddleName', 'StudentFirstSurname', 'StudentSecondSurname',
                'StudentIdentificationType', 'StudentIdentificationNumber',
                'Representative1FirstName', 'Representative1MiddleName',
                'Representative1FirstSurname', 'Representative1SecondSurname',
                'Representative1Relationship', 'Representative1IdentificationType',
                'Representative1IdentificationNumber', 'Representative1MobilePhone',
                'Representative1LandlinePhone', 'Representative1PersonalEmail',
                'Representative2FirstName', 'Representative2MiddleName',
                'Representative2FirstSurname', 'Representative2SecondSurname',
                'Representative2Relationship', 'Representative2IdentificationType',
                'Representative2IdentificationNumber', 'Representative2MobilePhone',
                'Representative2LandlinePhone', 'Representative2PersonalEmail', 'Address',
            ],
            array_map(
                fn (StudentRepresentativeDirectoryRow $row): array => [
                    $this->text($row->gradeName),
                    $this->text($row->sectionName),
                    $this->text($row->status->value),
                    $this->text($row->studentFirstName),
                    $this->text($row->studentMiddleName),
                    $this->text($row->studentFirstSurname),
                    $this->text($row->studentSecondSurname),
                    $this->text($row->studentIdentificationType),
                    $this->text($row->studentIdentificationNumber),
                    $this->text($row->representative1?->firstName),
                    $this->text($row->representative1?->middleName),
                    $this->text($row->representative1?->firstSurname),
                    $this->text($row->representative1?->secondSurname),
                    $this->text($row->representative1?->relationship),
                    $this->text($row->representative1?->identificationType),
                    $this->text($row->representative1?->identificationNumber),
                    $this->text($row->representative1?->mobilePhone),
                    $this->text($row->representative1?->landlinePhone),
                    $this->text($row->representative1?->personalEmail),
                    $this->text($row->representative2?->firstName),
                    $this->text($row->representative2?->middleName),
                    $this->text($row->representative2?->firstSurname),
                    $this->text($row->representative2?->secondSurname),
                    $this->text($row->representative2?->relationship),
                    $this->text($row->representative2?->identificationType),
                    $this->text($row->representative2?->identificationNumber),
                    $this->text($row->representative2?->mobilePhone),
                    $this->text($row->representative2?->landlinePhone),
                    $this->text($row->representative2?->personalEmail),
                    $this->text($row->studentAddress),
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

    /** @param list<PhysicalDepartureReportRow> $rows */
    public function physicalDeparture(array $rows): string
    {
        $csvRows = [];
        foreach ($rows as $row) {
            $pickups = $row->authorizedPickups === [] ? [null] : $row->authorizedPickups;
            foreach ($pickups as $pickup) {
                $csvRows[] = [
                    $this->text($row->gradeName),
                    $this->text($row->sectionName),
                    $this->text($row->studentFirstName),
                    $this->text($row->studentMiddleName),
                    $this->text($row->studentFirstSurname),
                    $this->text($row->studentSecondSurname),
                    $this->text($row->studentIdentificationType),
                    $this->text($row->studentIdentificationNumber),
                    $this->text($row->departureState->value),
                    $this->text($pickup?->name),
                    $this->text($pickup?->relationship),
                    $this->text($pickup?->identificationType),
                    $this->text($pickup?->identificationNumber),
                    $this->text($pickup?->mobilePhone),
                ];
            }
        }

        return $this->write([
            'Grade', 'Section', 'StudentFirstName', 'StudentMiddleName',
            'StudentFirstSurname', 'StudentSecondSurname', 'StudentIdentificationType',
            'StudentIdentificationNumber', 'DepartureState', 'PickupName', 'PickupRelationship',
            'PickupIdentificationType', 'PickupIdentificationNumber', 'PickupMobilePhone',
        ], $csvRows);
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
