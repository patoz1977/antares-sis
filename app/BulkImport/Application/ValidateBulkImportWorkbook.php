<?php

declare(strict_types=1);

namespace App\BulkImport\Application;

use App\BulkImport\Application\Dto\BulkImportIssueCategory;
use App\BulkImport\Application\Dto\BulkImportWorkbook;
use App\BulkImport\Application\Dto\FamilyWorkbookRow;
use App\BulkImport\Application\Dto\RawWorkbook;
use App\BulkImport\Application\Dto\RawWorkbookCell;
use App\BulkImport\Application\Dto\RawWorkbookCellType;
use App\BulkImport\Application\Dto\RawWorkbookRow;
use App\BulkImport\Application\Dto\RawWorkbookSheet;
use App\BulkImport\Application\Dto\RepresentativeWorkbookRow;
use App\BulkImport\Application\Dto\SensitivePlaintextPassword;
use App\BulkImport\Application\Dto\StudentWorkbookRow;
use App\BulkImport\Application\Dto\WorkbookValidationResult;
use App\Family\Domain\ValueObject\DisplayName;
use App\Family\Domain\ValueObject\FamilyCode;
use App\IdentityAccess\Application\Security\RepresentativePasswordPolicy;
use App\Person\Domain\ValueObject\ContactInformation;
use App\Person\Domain\ValueObject\PersonalName;
use App\Student\Domain\ValueObject\AdmissionDate;
use App\Student\Domain\ValueObject\InstitutionalCode;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final readonly class ValidateBulkImportWorkbook
{
    private DateTimeZone $utc;

    public function __construct(
        private RepresentativePasswordPolicy $passwordPolicy,
        private DateTimeImmutable $today,
    ) {
        $this->utc = new DateTimeZone('UTC');
    }

    public function validate(RawWorkbook $rawWorkbook): WorkbookValidationResult
    {
        $issues = new ValidationIssueCollector();
        $familiesSheet = $rawWorkbook->sheets['Familias'] ?? null;
        $representativesSheet = $rawWorkbook->sheets['Representantes'] ?? null;
        $studentsSheet = $rawWorkbook->sheets['Estudiantes'] ?? null;

        if (!$this->validateHeaders($familiesSheet, 'Familias', $issues)
            || !$this->validateHeaders($representativesSheet, 'Representantes', $issues)
            || !$this->validateHeaders($studentsSheet, 'Estudiantes', $issues)) {
            return new WorkbookValidationResult(null, $issues->all());
        }

        $families = $this->mapFamilies($familiesSheet, $issues);
        $representatives = $this->mapRepresentatives($representativesSheet, $issues);
        $students = $this->mapStudents($studentsSheet, $issues);

        $this->validateCrossSheetReferences($families, $representatives, $students, $issues);
        $this->validateDuplicates($families, $representatives, $students, $issues);

        if ($issues->count() > 0) {
            return new WorkbookValidationResult(null, $issues->all());
        }

        return new WorkbookValidationResult(
            new BulkImportWorkbook($families, $representatives, $students),
            [],
        );
    }

    private function validateHeaders(
        ?RawWorkbookSheet $sheet,
        string $sheetName,
        ValidationIssueCollector $issues,
    ): bool {
        if ($sheet === null || $sheet->rows === []) {
            $issues->add(
                BulkImportIssueCategory::StructureInvalid,
                $sheetName,
                1,
                null,
                'La hoja no contiene la fila de encabezados requerida.',
            );

            return false;
        }

        $header = $sheet->rows[0];
        $expected = BulkImportWorkbookContract::HEADERS[$sheetName];
        $actual = [];

        foreach ($header->cells as $cell) {
            $actual[] = $cell->type === RawWorkbookCellType::String ? $cell->value : null;
        }

        if ($header->sourceRow !== 1 || $actual !== $expected) {
            $issues->add(
                BulkImportIssueCategory::StructureInvalid,
                $sheetName,
                $header->sourceRow,
                null,
                'Los encabezados no coinciden exactamente en nombre, tipo y orden.',
            );

            return false;
        }

        return true;
    }

    /**
     * @return list<FamilyWorkbookRow>
     */
    private function mapFamilies(RawWorkbookSheet $sheet, ValidationIssueCollector $issues): array
    {
        $mapped = [];
        foreach (array_slice($sheet->rows, 1) as $row) {
            if ($row->isBlank()) {
                continue;
            }

            if (!$this->hasExactColumnCount($sheet->name, $row, 2, $issues)) {
                continue;
            }

            $familyCodeText = $this->text($sheet->name, $row, 0, 'family_code', true, 9, $issues);
            $displayNameText = $this->text($sheet->name, $row, 1, 'display_name', true, 150, $issues);
            if ($familyCodeText === null || $displayNameText === null) {
                continue;
            }

            try {
                $familyCode = new FamilyCode($familyCodeText);
                $displayName = new DisplayName($displayNameText);
            } catch (Throwable) {
                $issues->add(
                    BulkImportIssueCategory::ValueInvalid,
                    $sheet->name,
                    $row->sourceRow,
                    null,
                    'La fila de familia contiene un valor inválido.',
                );
                continue;
            }

            $mapped[] = new FamilyWorkbookRow(
                $row->sourceRow,
                $familyCode,
                $displayName->value(),
            );
        }

        return $mapped;
    }

    /**
     * @return list<RepresentativeWorkbookRow>
     */
    private function mapRepresentatives(
        RawWorkbookSheet $sheet,
        ValidationIssueCollector $issues,
    ): array {
        $mapped = [];
        foreach (array_slice($sheet->rows, 1) as $row) {
            if ($row->isBlank()) {
                continue;
            }

            if (!$this->hasExactColumnCount($sheet->name, $row, 14, $issues)) {
                continue;
            }

            $before = $issues->count();
            $familyCodeText = $this->text($sheet->name, $row, 0, 'family_code', true, 9, $issues);
            $firstName = $this->text($sheet->name, $row, 1, 'first_name', true, 100, $issues);
            $middleName = $this->text($sheet->name, $row, 2, 'middle_name', false, 100, $issues);
            $firstSurname = $this->text($sheet->name, $row, 3, 'first_surname', true, 100, $issues);
            $secondSurname = $this->text($sheet->name, $row, 4, 'second_surname', false, 100, $issues);
            $birthDate = $this->date($sheet->name, $row, 5, 'birth_date', true, $issues);
            $sexCode = $this->catalogCode($sheet->name, $row, 6, 'sex_code', $issues);
            $documentTypeCode = $this->catalogCode($sheet->name, $row, 7, 'document_type_code', $issues);
            $documentNumber = $this->text($sheet->name, $row, 8, 'document_number', true, 50, $issues);
            $email = $this->text($sheet->name, $row, 9, 'email', true, 254, $issues);
            $relationshipTypeCode = $this->catalogCode(
                $sheet->name,
                $row,
                10,
                'relationship_type_code',
                $issues,
            );
            $isPrimary = $this->boolean($sheet->name, $row, 11, 'is_primary', $issues);
            $startedAt = $this->date($sheet->name, $row, 12, 'started_at', false, $issues);
            $initialPassword = $this->password($sheet->name, $row, 13, 'initial_password', $issues);

            if ($issues->count() !== $before) {
                $initialPassword?->clear();
                continue;
            }

            try {
                $familyCode = new FamilyCode((string) $familyCodeText);
                $name = new PersonalName((string) $firstName, $middleName, (string) $firstSurname, $secondSurname);
                new ContactInformation((string) $email, null, null);
            } catch (Throwable) {
                $initialPassword?->clear();
                $issues->add(
                    BulkImportIssueCategory::ValueInvalid,
                    $sheet->name,
                    $row->sourceRow,
                    null,
                    'La fila de representante contiene un valor inválido.',
                );
                continue;
            }

            $mapped[] = new RepresentativeWorkbookRow(
                $row->sourceRow,
                $familyCode,
                $name->firstName(),
                $name->middleName(),
                $name->firstSurname(),
                $name->secondSurname(),
                $birthDate,
                (string) $sexCode,
                (string) $documentTypeCode,
                (string) $documentNumber,
                (string) $email,
                (string) $relationshipTypeCode,
                (bool) $isPrimary,
                $startedAt,
                $initialPassword,
            );
        }

        return $mapped;
    }

    /**
     * @return list<StudentWorkbookRow>
     */
    private function mapStudents(RawWorkbookSheet $sheet, ValidationIssueCollector $issues): array
    {
        $mapped = [];
        foreach (array_slice($sheet->rows, 1) as $row) {
            if ($row->isBlank()) {
                continue;
            }

            if (!$this->hasExactColumnCount($sheet->name, $row, 12, $issues)) {
                continue;
            }

            $before = $issues->count();
            $familyCodeText = $this->text($sheet->name, $row, 0, 'family_code', true, 9, $issues);
            $firstName = $this->text($sheet->name, $row, 1, 'first_name', true, 100, $issues);
            $middleName = $this->text($sheet->name, $row, 2, 'middle_name', false, 100, $issues);
            $firstSurname = $this->text($sheet->name, $row, 3, 'first_surname', true, 100, $issues);
            $secondSurname = $this->text($sheet->name, $row, 4, 'second_surname', false, 100, $issues);
            $birthDate = $this->date($sheet->name, $row, 5, 'birth_date', true, $issues);
            $sexCode = $this->catalogCode($sheet->name, $row, 6, 'sex_code', $issues);
            $documentTypeCode = $this->optionalCatalogCode(
                $sheet->name,
                $row,
                7,
                'document_type_code',
                $issues,
            );
            $documentNumber = $this->text(
                $sheet->name,
                $row,
                8,
                'document_number',
                false,
                50,
                $issues,
            );
            $institutionalCodeText = $this->text(
                $sheet->name,
                $row,
                9,
                'institutional_code',
                true,
                100,
                $issues,
            );
            $admissionDate = $this->date($sheet->name, $row, 10, 'admission_date', true, $issues);
            $startedAt = $this->date($sheet->name, $row, 11, 'started_at', false, $issues);

            if (($documentTypeCode === null) !== ($documentNumber === null)) {
                $issues->add(
                    BulkImportIssueCategory::ValueInvalid,
                    $sheet->name,
                    $row->sourceRow,
                    'document_type_code',
                    'La identificación del estudiante debe contener tipo y número, o ambos vacíos.',
                );
            }

            if ($issues->count() !== $before) {
                continue;
            }

            try {
                $familyCode = new FamilyCode((string) $familyCodeText);
                $name = new PersonalName((string) $firstName, $middleName, (string) $firstSurname, $secondSurname);
                $institutionalCode = new InstitutionalCode((string) $institutionalCodeText);
                $admission = new AdmissionDate($admissionDate, $this->today);
            } catch (Throwable) {
                $issues->add(
                    BulkImportIssueCategory::ValueInvalid,
                    $sheet->name,
                    $row->sourceRow,
                    null,
                    'La fila de estudiante contiene un valor inválido.',
                );
                continue;
            }

            $mapped[] = new StudentWorkbookRow(
                $row->sourceRow,
                $familyCode,
                $name->firstName(),
                $name->middleName(),
                $name->firstSurname(),
                $name->secondSurname(),
                $birthDate,
                (string) $sexCode,
                $documentTypeCode,
                $documentNumber,
                $institutionalCode,
                $admission->value(),
                $startedAt,
            );
        }

        return $mapped;
    }

    private function hasExactColumnCount(
        string $sheet,
        RawWorkbookRow $row,
        int $expected,
        ValidationIssueCollector $issues,
    ): bool {
        if (count($row->cells) === $expected) {
            return true;
        }

        $issues->add(
            BulkImportIssueCategory::StructureInvalid,
            $sheet,
            $row->sourceRow,
            null,
            'La fila no contiene exactamente las columnas esperadas.',
        );

        return false;
    }

    private function text(
        string $sheet,
        RawWorkbookRow $row,
        int $index,
        string $field,
        bool $required,
        int $maximumLength,
        ValidationIssueCollector $issues,
    ): ?string {
        $cell = $row->cells[$index] ?? new RawWorkbookCell(RawWorkbookCellType::Empty, null);
        if ($cell->type === RawWorkbookCellType::Empty) {
            if ($required) {
                $issues->add(
                    BulkImportIssueCategory::RequiredValueMissing,
                    $sheet,
                    $row->sourceRow,
                    $field,
                    'El valor requerido está vacío.',
                );
            }

            return null;
        }

        if ($cell->type !== RawWorkbookCellType::String || !is_string($cell->value)) {
            $issues->add(
                BulkImportIssueCategory::ValueInvalid,
                $sheet,
                $row->sourceRow,
                $field,
                'El valor debe estar almacenado como texto.',
            );

            return null;
        }

        if (strlen($cell->value) > BulkImportWorkbookContract::MAXIMUM_CELL_BYTES
            || mb_strlen($cell->value, 'UTF-8') > BulkImportWorkbookContract::MAXIMUM_CELL_CHARACTERS) {
            $issues->add(
                BulkImportIssueCategory::ValueInvalid,
                $sheet,
                $row->sourceRow,
                $field,
                'El valor excede el límite técnico permitido.',
            );

            return null;
        }

        $normalized = trim($cell->value);
        if ($normalized === '') {
            if ($required) {
                $issues->add(
                    BulkImportIssueCategory::RequiredValueMissing,
                    $sheet,
                    $row->sourceRow,
                    $field,
                    'El valor requerido está vacío.',
                );
            }

            return null;
        }

        if (mb_strlen($normalized, 'UTF-8') > $maximumLength) {
            $issues->add(
                BulkImportIssueCategory::ValueInvalid,
                $sheet,
                $row->sourceRow,
                $field,
                'El valor excede la longitud máxima permitida.',
            );

            return null;
        }

        return $normalized;
    }

    private function catalogCode(
        string $sheet,
        RawWorkbookRow $row,
        int $index,
        string $field,
        ValidationIssueCollector $issues,
    ): ?string {
        $value = $this->text($sheet, $row, $index, $field, true, 50, $issues);

        return $value === null ? null : mb_strtoupper($value, 'UTF-8');
    }

    private function optionalCatalogCode(
        string $sheet,
        RawWorkbookRow $row,
        int $index,
        string $field,
        ValidationIssueCollector $issues,
    ): ?string {
        $value = $this->text($sheet, $row, $index, $field, false, 50, $issues);

        return $value === null ? null : mb_strtoupper($value, 'UTF-8');
    }

    private function date(
        string $sheet,
        RawWorkbookRow $row,
        int $index,
        string $field,
        bool $notFuture,
        ValidationIssueCollector $issues,
    ): ?DateTimeImmutable {
        $value = $this->text($sheet, $row, $index, $field, true, 10, $issues);
        if ($value === null) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $this->utc);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false
            || $date->format('Y-m-d') !== $value
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || ($notFuture && $date->format('Y-m-d') > $this->today->format('Y-m-d'))) {
            $issues->add(
                BulkImportIssueCategory::ValueInvalid,
                $sheet,
                $row->sourceRow,
                $field,
                'La fecha debe ser válida, no futura cuando corresponda y usar YYYY-MM-DD.',
            );

            return null;
        }

        return $date;
    }

    private function boolean(
        string $sheet,
        RawWorkbookRow $row,
        int $index,
        string $field,
        ValidationIssueCollector $issues,
    ): ?bool {
        $value = $this->text($sheet, $row, $index, $field, true, 2, $issues);
        if ($value === 'SI') {
            return true;
        }
        if ($value === 'NO') {
            return false;
        }
        if ($value !== null) {
            $issues->add(
                BulkImportIssueCategory::ValueInvalid,
                $sheet,
                $row->sourceRow,
                $field,
                'El valor debe ser exactamente SI o NO.',
            );
        }

        return null;
    }

    private function password(
        string $sheet,
        RawWorkbookRow $row,
        int $index,
        string $field,
        ValidationIssueCollector $issues,
    ): ?SensitivePlaintextPassword {
        $cell = $row->cells[$index] ?? new RawWorkbookCell(RawWorkbookCellType::Empty, null);
        if ($cell->type === RawWorkbookCellType::Empty) {
            return null;
        }
        if ($cell->type !== RawWorkbookCellType::String || !is_string($cell->value)) {
            $issues->add(
                BulkImportIssueCategory::ValueInvalid,
                $sheet,
                $row->sourceRow,
                $field,
                'La contraseña debe estar almacenada como texto.',
            );

            return null;
        }
        if ($cell->value === '') {
            return null;
        }
        if (strlen($cell->value) > BulkImportWorkbookContract::MAXIMUM_CELL_BYTES
            || mb_strlen($cell->value, 'UTF-8') > BulkImportWorkbookContract::MAXIMUM_CELL_CHARACTERS) {
            $issues->add(
                BulkImportIssueCategory::ValueInvalid,
                $sheet,
                $row->sourceRow,
                $field,
                'La contraseña no cumple la política vigente.',
            );

            return null;
        }

        try {
            $this->passwordPolicy->assertValid($cell->value);
        } catch (Throwable) {
            $issues->add(
                BulkImportIssueCategory::ValueInvalid,
                $sheet,
                $row->sourceRow,
                $field,
                'La contraseña no cumple la política vigente.',
            );

            return null;
        }

        return new SensitivePlaintextPassword($cell->value);
    }

    /**
     * @param list<FamilyWorkbookRow> $families
     * @param list<RepresentativeWorkbookRow> $representatives
     * @param list<StudentWorkbookRow> $students
     */
    private function validateCrossSheetReferences(
        array $families,
        array $representatives,
        array $students,
        ValidationIssueCollector $issues,
    ): void {
        $knownFamilies = [];
        foreach ($families as $family) {
            $knownFamilies[$family->familyCode->value()] = true;
        }

        foreach ($representatives as $representative) {
            if (!isset($knownFamilies[$representative->familyCode->value()])) {
                $issues->add(
                    BulkImportIssueCategory::FamilyReferenceInvalid,
                    'Representantes',
                    $representative->sourceRow,
                    'family_code',
                    'El FamilyCode no tiene una fila correspondiente en Familias.',
                );
            }
        }

        foreach ($students as $student) {
            if (!isset($knownFamilies[$student->familyCode->value()])) {
                $issues->add(
                    BulkImportIssueCategory::FamilyReferenceInvalid,
                    'Estudiantes',
                    $student->sourceRow,
                    'family_code',
                    'El FamilyCode no tiene una fila correspondiente en Familias.',
                );
            }
        }
    }

    /**
     * @param list<FamilyWorkbookRow> $families
     * @param list<RepresentativeWorkbookRow> $representatives
     * @param list<StudentWorkbookRow> $students
     */
    private function validateDuplicates(
        array $families,
        array $representatives,
        array $students,
        ValidationIssueCollector $issues,
    ): void {
        $familyCodes = [];
        foreach ($families as $family) {
            $code = $family->familyCode->value();
            if (isset($familyCodes[$code])) {
                $issues->add(
                    BulkImportIssueCategory::DuplicateInWorkbook,
                    'Familias',
                    $family->sourceRow,
                    'family_code',
                    'El FamilyCode está duplicado dentro del workbook.',
                );
            }
            $familyCodes[$code] = true;
        }

        $representativeMemberships = [];
        $representativePayloads = [];
        $representativePasswords = [];
        $primaryCounts = [];
        foreach ($representatives as $representative) {
            $identity = $representative->documentTypeCode . "\0" . $representative->documentNumber;
            $familyCode = $representative->familyCode->value();
            $membership = $familyCode . "\0" . $identity;
            if (isset($representativeMemberships[$membership])) {
                $issues->add(
                    BulkImportIssueCategory::DuplicateInWorkbook,
                    'Representantes',
                    $representative->sourceRow,
                    'document_number',
                    'La membresía del representante está duplicada dentro del workbook.',
                );
            }
            $representativeMemberships[$membership] = true;

            $payload = implode("\0", [
                $representative->firstName,
                $representative->middleName ?? '',
                $representative->firstSurname,
                $representative->secondSurname ?? '',
                $representative->birthDate->format('Y-m-d'),
                $representative->sexCode,
                $representative->email,
            ]);
            if (isset($representativePayloads[$identity]) && $representativePayloads[$identity] !== $payload) {
                $issues->add(
                    BulkImportIssueCategory::DuplicateInWorkbook,
                    'Representantes',
                    $representative->sourceRow,
                    'document_number',
                    'La misma identidad de representante tiene datos incompatibles dentro del workbook.',
                );
            }
            $representativePayloads[$identity] = $payload;

            if ($representative->initialPassword !== null) {
                if (isset($representativePasswords[$identity])
                    && !$representativePasswords[$identity]->equals($representative->initialPassword)) {
                    $issues->add(
                        BulkImportIssueCategory::DuplicateInWorkbook,
                        'Representantes',
                        $representative->sourceRow,
                        'initial_password',
                        'La misma identidad de representante tiene credenciales incompatibles dentro del workbook.',
                    );
                }
                $representativePasswords[$identity] = $representative->initialPassword;
            }

            if ($representative->isPrimary) {
                $primaryCounts[$familyCode] = ($primaryCounts[$familyCode] ?? 0) + 1;
                if ($primaryCounts[$familyCode] > 1) {
                    $issues->add(
                        BulkImportIssueCategory::PrimaryRepresentativeConflict,
                        'Representantes',
                        $representative->sourceRow,
                        'is_primary',
                        'Una familia no puede declarar múltiples representantes Primary.',
                    );
                }
            }
        }

        $institutionalCodes = [];
        $studentIdentifications = [];
        foreach ($students as $student) {
            $code = $student->institutionalCode->value();
            if (isset($institutionalCodes[$code])) {
                $issues->add(
                    BulkImportIssueCategory::DuplicateInWorkbook,
                    'Estudiantes',
                    $student->sourceRow,
                    'institutional_code',
                    'El estudiante está repetido dentro del workbook.',
                );
            }
            $institutionalCodes[$code] = $student->familyCode->value();

            if ($student->documentTypeCode !== null && $student->documentNumber !== null) {
                $identification = $student->documentTypeCode . "\0" . $student->documentNumber;
                if (isset($studentIdentifications[$identification])
                    && $studentIdentifications[$identification] !== $code) {
                    $issues->add(
                        BulkImportIssueCategory::DuplicateInWorkbook,
                        'Estudiantes',
                        $student->sourceRow,
                        'document_number',
                        'La misma identificación corresponde a estudiantes distintos dentro del workbook.',
                    );
                }
                $studentIdentifications[$identification] = $code;
            }
        }
    }
}
