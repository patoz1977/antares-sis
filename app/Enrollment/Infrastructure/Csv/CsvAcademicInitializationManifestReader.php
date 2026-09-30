<?php

declare(strict_types=1);

namespace App\Enrollment\Infrastructure\Csv;

use App\Enrollment\Application\AcademicInitialization\Contract\AcademicInitializationManifestReader;
use App\Enrollment\Application\AcademicInitialization\Dto\AcademicInitializationIssue;
use App\Enrollment\Application\AcademicInitialization\Dto\AcademicInitializationManifest;
use App\Enrollment\Application\AcademicInitialization\Dto\AcademicInitializationManifestRow;
use App\Student\Domain\ValueObject\InstitutionalCode;
use Throwable;

final class CsvAcademicInitializationManifestReader implements AcademicInitializationManifestReader
{
    private const MAXIMUM_BYTES = 1024 * 1024;
    private const MAXIMUM_ROWS = 1000;
    private const HEADER = ['institutional_code', 'grade_code', 'section_code'];

    public function read(string $localPath): AcademicInitializationManifest
    {
        $size = is_file($localPath) ? filesize($localPath) : false;
        if (!is_int($size) || $size < 1 || $size > self::MAXIMUM_BYTES) {
            return $this->invalidFile('El manifest debe contener datos y no superar 1 MiB.');
        }

        $handle = @fopen($localPath, 'rb');
        if ($handle === false) {
            return $this->invalidFile('El manifest no pudo leerse de forma segura.');
        }

        try {
            $header = $this->csvRow($handle);
            if ($header === false) {
                return $this->invalidFile('El manifest no contiene encabezados.');
            }
            if (isset($header[0]) && is_string($header[0])) {
                $header[0] = preg_replace('/\A\xEF\xBB\xBF/', '', $header[0]) ?? $header[0];
            }
            if ($header !== self::HEADER) {
                return $this->invalidFile(
                    'Los encabezados deben ser exactamente institutional_code,grade_code,section_code.'
                );
            }

            $rows = [];
            $issues = [];
            $seen = [];
            $sourceRow = 1;
            while (($values = $this->csvRow($handle)) !== false) {
                ++$sourceRow;
                if ($sourceRow > self::MAXIMUM_ROWS + 1) {
                    $issues[] = $this->issue(
                        'FILE_LIMIT',
                        $sourceRow,
                        null,
                        'El manifest no puede superar 1000 filas de datos.',
                    );
                    break;
                }
                if (count($values) !== 3 || $this->blankRow($values)) {
                    $issues[] = $this->issue(
                        'ROW_INVALID',
                        $sourceRow,
                        null,
                        'Cada fila debe contener exactamente tres valores no vacíos.',
                    );
                    continue;
                }

                $normalized = [];
                foreach (self::HEADER as $index => $field) {
                    $value = $values[$index] ?? null;
                    if (!is_string($value)
                        || !mb_check_encoding($value, 'UTF-8')
                        || str_contains($value, "\0")
                    ) {
                        $issues[] = $this->issue(
                            'FIELD_INVALID',
                            $sourceRow,
                            $field,
                            'El valor debe ser texto UTF-8 válido.',
                        );
                        continue 2;
                    }
                    $value = trim($value);
                    if ($value === '' || mb_strlen($value, 'UTF-8') > 100) {
                        $issues[] = $this->issue(
                            'FIELD_INVALID',
                            $sourceRow,
                            $field,
                            'El valor es obligatorio y no puede superar 100 caracteres.',
                        );
                        continue 2;
                    }
                    if (preg_match('/\A[=+\-@]/u', $value) === 1
                        || preg_match('/[\x00-\x1F\x7F]/u', $value) === 1
                    ) {
                        $issues[] = $this->issue(
                            'UNSAFE_VALUE',
                            $sourceRow,
                            $field,
                            'El valor contiene una fórmula o caracteres de control no permitidos.',
                        );
                        continue 2;
                    }
                    $normalized[$field] = $value;
                }

                try {
                    new InstitutionalCode($normalized['institutional_code']);
                } catch (Throwable) {
                    $issues[] = $this->issue(
                        'FIELD_INVALID',
                        $sourceRow,
                        'institutional_code',
                        'El código institucional no cumple el contrato vigente.',
                    );
                    continue;
                }
                foreach (['grade_code', 'section_code'] as $field) {
                    if (preg_match('/\A[A-Z0-9][A-Z0-9_-]*\z/D', $normalized[$field]) !== 1) {
                        $issues[] = $this->issue(
                            'FIELD_INVALID',
                            $sourceRow,
                            $field,
                            'El código debe usar únicamente A-Z, 0-9, guion o guion bajo.',
                        );
                        continue 2;
                    }
                }

                $institutionalCode = $normalized['institutional_code'];
                if (isset($seen[$institutionalCode])) {
                    $issues[] = $this->issue(
                        'DUPLICATE_STUDENT',
                        $sourceRow,
                        'institutional_code',
                        'El código institucional está duplicado dentro del manifest.',
                    );
                    continue;
                }
                $seen[$institutionalCode] = true;
                $rows[] = new AcademicInitializationManifestRow(
                    $sourceRow,
                    $institutionalCode,
                    $normalized['grade_code'],
                    $normalized['section_code'],
                );
            }

            if ($rows === [] && $issues === []) {
                $issues[] = $this->issue(
                    'FILE_INVALID',
                    0,
                    null,
                    'El manifest debe contener al menos una fila de datos.',
                );
            }

            return new AcademicInitializationManifest($rows, $issues);
        } finally {
            fclose($handle);
        }
    }

    /** @param resource $handle @return list<string|null>|false */
    private function csvRow($handle): array|false
    {
        return fgetcsv($handle, 0, ',', '"', '');
    }

    /** @param list<mixed> $values */
    private function blankRow(array $values): bool
    {
        return count(array_filter(
            $values,
            static fn (mixed $value): bool => is_string($value) && trim($value) !== '',
        )) === 0;
    }

    private function invalidFile(string $message): AcademicInitializationManifest
    {
        return new AcademicInitializationManifest([], [$this->issue('FILE_INVALID', 0, null, $message)]);
    }

    private function issue(
        string $category,
        int $row,
        ?string $field,
        string $message,
    ): AcademicInitializationIssue {
        return new AcademicInitializationIssue($category, $row, $field, $message);
    }
}
