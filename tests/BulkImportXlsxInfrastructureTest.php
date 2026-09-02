<?php

declare(strict_types=1);

namespace Tests;

use App\BulkImport\Application\BulkImportWorkbookContract;
use App\BulkImport\Application\Exception\BulkImportWorkbookRejected;
use App\BulkImport\Application\ValidateBulkImportWorkbook;
use App\BulkImport\Infrastructure\Xlsx\OpenSpoutBulkImportWorkbookReader;
use App\BulkImport\Infrastructure\Xlsx\XlsxContainerPreflightInspector;
use App\IdentityAccess\Application\Security\RepresentativePasswordPolicy;
use DateTimeImmutable;
use LogicException;
use Tests\Support\TestRunner;
use ZipArchive;

function registerBulkImportXlsxInfrastructureTests(TestRunner $runner): void
{
    $runner->add('E015 static template has the exact empty three-sheet contract', function (): void {
        $reader = e015Reader();
        $path = dirname(__DIR__) . '/resources/templates/bulk-import/e015-family-import-v1.xlsx';
        $result = $reader->read($path);

        assertSameValue(true, $result->isValid());
        assertSameValue(
            ['families' => 0, 'representatives' => 0, 'students' => 0, 'total' => 0],
            $result->workbook()?->safeCounts(),
        );
        assertSameValue([], $result->issues());
    });

    $runner->add('E015 valid workbook preserves types normalization UTC source rows and passwords safely', function (): void {
        $fixtures = new BulkImportXlsxFixtureFactory();
        $sheets = $fixtures->validSheets();
        $path = $fixtures->writeWorkbook('valid-reordered.xlsx', [
            'Estudiantes' => $sheets['Estudiantes'],
            'Familias' => $sheets['Familias'],
            'Representantes' => $sheets['Representantes'],
        ]);
        $result = e015Reader()->read($path);
        $workbook = $result->workbook();
        $representative = $workbook?->representatives[0] ?? null;
        $student = $workbook?->students[0] ?? null;

        assertSameValue(true, $result->isValid());
        assertSameValue(3, $workbook?->totalRows());
        assertSameValue(2, $representative?->sourceRow);
        assertSameValue('FEMALE', $representative?->sexCode);
        assertSameValue('DNI', $representative?->documentTypeCode);
        assertSameValue('001234', $representative?->documentNumber);
        assertSameValue(null, $representative?->middleName);
        assertSameValue('2026-08-01 00:00:00', $representative?->startedAt->format('Y-m-d H:i:s'));
        assertSameValue('UTC', $representative?->startedAt->getTimezone()->getName());
        assertSameValue('ClaveSegura9', $representative?->initialPassword?->reveal());
        assertSameValue(null, $student?->documentTypeCode);
        assertSameValue(null, $student?->documentNumber);
        assertSameValue(2, $student?->sourceRow);

        $safe = json_encode($result->safeOutput(), JSON_THROW_ON_ERROR);
        $exported = var_export($result, true);
        assertSameValue(false, str_contains($safe, 'ClaveSegura9'));
        assertSameValue(false, str_contains($exported, 'ClaveSegura9'));
        assertThrows(
            static fn (): string => serialize($representative?->initialPassword),
            LogicException::class,
        );
    });

    $runner->add('E015 valid workbook accepts multiple Families and one Representative identity in two Families', function (): void {
        $fixtures = new BulkImportXlsxFixtureFactory();
        $sheets = $fixtures->validSheets();
        $sheets['Familias'][] = ['F00000002', 'Familia Dos'];
        $secondRepresentative = $sheets['Representantes'][1];
        $secondRepresentative[0] = 'F00000002';
        $sheets['Representantes'][] = $secondRepresentative;
        $secondStudent = $sheets['Estudiantes'][1];
        $secondStudent[0] = 'F00000002';
        $secondStudent[9] = 'EST-002';
        $sheets['Estudiantes'][] = $secondStudent;

        $result = e015Reader()->read($fixtures->writeWorkbook('multiple.xlsx', $sheets));

        assertSameValue(true, $result->isValid());
        assertSameValue(2, count($result->workbook()?->families ?? []));
        assertSameValue(2, count($result->workbook()?->representatives ?? []));
        assertSameValue(2, count($result->workbook()?->students ?? []));
    });

    $runner->add('E015 conditional trailing password may be an omitted blank text cell', function (): void {
        $fixtures = new BulkImportXlsxFixtureFactory();
        $sheets = $fixtures->validSheets();
        $sheets['Representantes'][1][13] = null;

        $result = e015Reader()->read($fixtures->writeWorkbook('blank-password.xlsx', $sheets));

        assertSameValue(true, $result->isValid());
        assertSameValue(null, $result->workbook()?->representatives[0]->initialPassword);
    });

    $runner->add('E015 blank rows are ignored without collapsing physical source numbering', function (): void {
        $fixtures = new BulkImportXlsxFixtureFactory();
        $sheets = $fixtures->validSheets();
        array_splice($sheets['Representantes'], 1, 0, [array_fill(0, 14, null)]);
        array_splice($sheets['Estudiantes'], 1, 0, [array_fill(0, 12, null)]);

        $result = e015Reader()->read($fixtures->writeWorkbook('blank-rows.xlsx', $sheets));

        assertSameValue(true, $result->isValid());
        assertSameValue(3, $result->workbook()?->representatives[0]->sourceRow);
        assertSameValue(3, $result->workbook()?->students[0]->sourceRow);
    });

    $runner->add('E015 rejects nonexistent empty oversized non-ZIP and corrupt files', function (): void {
        $fixtures = new BulkImportXlsxFixtureFactory();
        $reader = e015Reader();
        $paths = [
            sys_get_temp_dir() . '/e015-does-not-exist-' . bin2hex(random_bytes(4)) . '.xlsx',
            $fixtures->writeBytes('empty.xlsx', ''),
            $fixtures->writeOversizedFile('oversized.xlsx'),
            $fixtures->writeBytes('plain.xlsx', 'not a zip'),
            $fixtures->writeBytes('corrupt.xlsx', "PK\x03\x04corrupt"),
        ];

        foreach ($paths as $path) {
            e015AssertRejected(static fn () => $reader->read($path));
        }
    });

    $runner->add('E015 rejects ZIP entry count per-entry size compression ratio and total size attacks', function (): void {
        $fixtures = new BulkImportXlsxFixtureFactory();
        $reader = e015Reader();

        $tooMany = $fixtures->copyTemplate('too-many.xlsx');
        $fixtures->mutateZip($tooMany, static function (ZipArchive $zip): void {
            for ($i = 0; $i < 101; $i++) {
                $zip->addFromString('custom/item-' . $i . '.txt', 'x');
            }
        });
        e015AssertRejected(static fn () => $reader->read($tooMany));

        $tooLarge = $fixtures->copyTemplate('too-large-entry.xlsx');
        $fixtures->mutateZip($tooLarge, static function (ZipArchive $zip): void {
            $zip->addFromString('custom/large.txt', str_repeat('A', (10 * 1024 * 1024) + 1));
        });
        e015AssertRejected(static fn () => $reader->read($tooLarge));

        $ratio = $fixtures->copyTemplate('ratio.xlsx');
        $fixtures->mutateZip($ratio, static function (ZipArchive $zip): void {
            $zip->addFromString('custom/ratio.txt', str_repeat('B', 1024 * 1024));
        });
        e015AssertRejected(static fn () => $reader->read($ratio));

        $total = $fixtures->copyTemplate('total.xlsx');
        $fixtures->mutateZip($total, static function (ZipArchive $zip): void {
            for ($index = 0; $index < 3; $index++) {
                $data = e015ModeratelyCompressibleBytes(9 * 1024 * 1024);
                $zip->addFromString('custom/total-' . $index . '.txt', $data);
                unset($data);
            }
        });
        e015AssertRejected(static fn () => $reader->read($total));
    });

    $runner->add('E015 rejects ZIP traversal macros embedded objects and external relationships', function (): void {
        $fixtures = new BulkImportXlsxFixtureFactory();
        $reader = e015Reader();

        $traversal = $fixtures->copyTemplate('traversal.xlsx');
        $fixtures->mutateZip($traversal, static fn (ZipArchive $zip): bool => $zip->addFromString('../outside.txt', 'x'));
        e015AssertRejected(static fn () => $reader->read($traversal));

        $macro = $fixtures->copyTemplate('macro.xlsx');
        $fixtures->mutateZip($macro, static fn (ZipArchive $zip): bool => $zip->addFromString('xl/vbaProject.bin', 'macro'));
        e015AssertRejected(static fn () => $reader->read($macro));

        $embedded = $fixtures->copyTemplate('embedded.xlsx');
        $fixtures->mutateZip($embedded, static fn (ZipArchive $zip): bool => $zip->addFromString('xl/embeddings/object.bin', 'object'));
        e015AssertRejected(static fn () => $reader->read($embedded));

        $external = $fixtures->copyTemplate('external.xlsx');
        $fixtures->mutateEntry($external, '_rels/.rels', static fn (string $xml): string => str_replace(
            '</Relationships>',
            '<Relationship Id="external" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/externalLink" Target="https://example.test/book.xlsx" TargetMode="External"/></Relationships>',
            $xml,
        ));
        e015AssertRejected(static fn () => $reader->read($external));
    });

    $runner->add('E015 rejects missing additional case-mismatched and hidden sheets', function (): void {
        $fixtures = new BulkImportXlsxFixtureFactory();
        $reader = e015Reader();
        $sheets = $fixtures->validSheets();

        $missing = $sheets;
        unset($missing['Estudiantes']);
        e015AssertRejected(static fn () => $reader->read($fixtures->writeWorkbook('missing-sheet.xlsx', $missing)));

        $additional = $sheets;
        $additional['Auxiliar'] = [['value']];
        e015AssertRejected(static fn () => $reader->read($fixtures->writeWorkbook('additional-sheet.xlsx', $additional)));

        $wrongCase = ['familias' => $sheets['Familias'], 'Representantes' => $sheets['Representantes'], 'Estudiantes' => $sheets['Estudiantes']];
        e015AssertRejected(static fn () => $reader->read($fixtures->writeWorkbook('wrong-case.xlsx', $wrongCase)));

        e015AssertRejected(static fn () => $reader->read($fixtures->writeWorkbook(
            'hidden-sheet.xlsx',
            $sheets,
            ['Estudiantes'],
        )));
    });

    $runner->add('E015 rejects formulas merged cells hidden rows and hidden columns', function (): void {
        $fixtures = new BulkImportXlsxFixtureFactory();
        $reader = e015Reader();
        $sheets = $fixtures->validSheets();

        $formulaSheets = $sheets;
        $formulaSheets['Familias'][1][1] = '=CONCAT("Familia"," Uno")';
        e015AssertRejected(static fn () => $reader->read($fixtures->writeWorkbook('formula.xlsx', $formulaSheets)));

        $merged = $fixtures->writeWorkbook('merged.xlsx', $sheets, [], [[
            'sheet' => 0,
            'firstColumn' => 0,
            'firstRow' => 1,
            'lastColumn' => 1,
            'lastRow' => 1,
        ]]);
        e015AssertRejected(static fn () => $reader->read($merged));

        $hiddenRow = $fixtures->copyTemplate('hidden-row.xlsx');
        $fixtures->mutateEntry($hiddenRow, 'xl/worksheets/sheet1.xml', static fn (string $xml): string => str_replace(
            '<row r="1"',
            '<row r="1" hidden="1"',
            $xml,
        ));
        e015AssertRejected(static fn () => $reader->read($hiddenRow));

        $hiddenColumn = $fixtures->copyTemplate('hidden-column.xlsx');
        $fixtures->mutateEntry($hiddenColumn, 'xl/worksheets/sheet1.xml', static fn (string $xml): string => str_replace(
            '<sheetData>',
            '<cols><col min="1" max="1" hidden="1"/></cols><sheetData>',
            $xml,
        ));
        e015AssertRejected(static fn () => $reader->read($hiddenColumn));
    });

    $runner->add('E015 rejects invalid relevant XML without external entity processing', function (): void {
        $fixtures = new BulkImportXlsxFixtureFactory();
        $reader = e015Reader();

        $invalid = $fixtures->copyTemplate('invalid-xml.xlsx');
        $fixtures->mutateEntry($invalid, 'xl/workbook.xml', static fn (string $xml): string => '<broken');
        e015AssertRejected(static fn () => $reader->read($invalid));

        $doctype = $fixtures->copyTemplate('doctype.xlsx');
        $fixtures->mutateEntry($doctype, 'xl/workbook.xml', static fn (string $xml): string => str_replace(
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>',
            '<?xml version="1.0" encoding="UTF-8"?><!DOCTYPE x [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>',
            $xml,
        ));
        e015AssertRejected(static fn () => $reader->read($doctype));
    });

    $runner->add('E015 reports exact header contract violations without parsing rows', function (): void {
        $fixtures = new BulkImportXlsxFixtureFactory();
        $reader = e015Reader();
        $variants = [];
        $base = $fixtures->validSheets();

        $extra = $base;
        $extra['Familias'][0][] = 'extra';
        $extra['Familias'][1][] = 'value';
        $variants['extra'] = $extra;
        $missing = $base;
        array_pop($missing['Representantes'][0]);
        array_pop($missing['Representantes'][1]);
        $variants['missing'] = $missing;
        $reordered = $base;
        [$reordered['Estudiantes'][0][0], $reordered['Estudiantes'][0][1]] = [$reordered['Estudiantes'][0][1], $reordered['Estudiantes'][0][0]];
        $variants['reordered'] = $reordered;
        $duplicate = $base;
        $duplicate['Familias'][0][1] = 'family_code';
        $variants['duplicate'] = $duplicate;

        foreach ($variants as $name => $sheets) {
            $result = $reader->read($fixtures->writeWorkbook('header-' . $name . '.xlsx', $sheets));
            assertSameValue(false, $result->isValid());
            assertSameValue('STRUCTURE_INVALID', $result->issues()[0]->category->value);
        }
    });

    $runner->add('E015 rejects numeric identity boolean and Excel serial date cell types', function (): void {
        $fixtures = new BulkImportXlsxFixtureFactory();
        $reader = e015Reader();
        $cases = [];
        $base = $fixtures->validSheets();

        $numericDocument = $base;
        $numericDocument['Representantes'][1][8] = 1234;
        $cases[] = $numericDocument;
        $numericInstitutional = $base;
        $numericInstitutional['Estudiantes'][1][9] = 1001;
        $cases[] = $numericInstitutional;
        $numericFamily = $base;
        $numericFamily['Familias'][1][0] = 1;
        $cases[] = $numericFamily;
        $nativeBoolean = $base;
        $nativeBoolean['Representantes'][1][11] = true;
        $cases[] = $nativeBoolean;
        $excelDate = $base;
        $excelDate['Estudiantes'][1][10] = 45555;
        $cases[] = $excelDate;

        foreach ($cases as $index => $sheets) {
            $result = $reader->read($fixtures->writeWorkbook('types-' . $index . '.xlsx', $sheets));
            assertSameValue(false, $result->isValid());
            assertSameValue(true, count($result->issues()) > 0);
        }
    });

    $runner->add('E015 validates ISO dates lengths FamilyCode SI-NO email and Student identification pair', function (): void {
        $fixtures = new BulkImportXlsxFixtureFactory();
        $reader = e015Reader();
        $cases = [];
        $base = $fixtures->validSheets();

        $invalidDate = $base;
        $invalidDate['Representantes'][1][5] = '31/12/1980';
        $cases[] = $invalidDate;
        $impossibleDate = $base;
        $impossibleDate['Estudiantes'][1][10] = '2026-02-30';
        $cases[] = $impossibleDate;
        $futureDate = $base;
        $futureDate['Estudiantes'][1][5] = '2030-01-01';
        $cases[] = $futureDate;
        $oversized = $base;
        $oversized['Familias'][1][1] = str_repeat('A', 151);
        $cases[] = $oversized;
        $invalidCode = $base;
        $invalidCode['Familias'][1][0] = 'f00000001';
        $cases[] = $invalidCode;
        $invalidBoolean = $base;
        $invalidBoolean['Representantes'][1][11] = 'Si';
        $cases[] = $invalidBoolean;
        $invalidEmail = $base;
        $invalidEmail['Representantes'][1][9] = 'invalid-email';
        $cases[] = $invalidEmail;
        $unpairedType = $base;
        $unpairedType['Estudiantes'][1][7] = 'DNI';
        $cases[] = $unpairedType;
        $unpairedNumber = $base;
        $unpairedNumber['Estudiantes'][1][8] = '001';
        $cases[] = $unpairedNumber;

        foreach ($cases as $index => $sheets) {
            $result = $reader->read($fixtures->writeWorkbook('values-' . $index . '.xlsx', $sheets));
            assertSameValue(false, $result->isValid());
            assertSameValue(true, count($result->issues()) > 0);
        }
    });

    $runner->add('E015 validates cross-sheet references duplicates Students and Primary conflicts', function (): void {
        $fixtures = new BulkImportXlsxFixtureFactory();
        $reader = e015Reader();
        $cases = [];
        $base = $fixtures->validSheets();

        $missingReference = $base;
        $missingReference['Representantes'][1][0] = 'F00000002';
        $cases[] = $missingReference;
        $duplicateFamily = $base;
        $duplicateFamily['Familias'][] = $duplicateFamily['Familias'][1];
        $cases[] = $duplicateFamily;
        $duplicateRepresentative = $base;
        $duplicateRepresentative['Representantes'][] = $duplicateRepresentative['Representantes'][1];
        $cases[] = $duplicateRepresentative;
        $multiplePrimary = $base;
        $second = $multiplePrimary['Representantes'][1];
        $second[8] = '009999';
        $multiplePrimary['Representantes'][] = $second;
        $cases[] = $multiplePrimary;
        $duplicateStudent = $base;
        $duplicateStudent['Familias'][] = ['F00000002', 'Familia Dos'];
        $student = $duplicateStudent['Estudiantes'][1];
        $student[0] = 'F00000002';
        $duplicateStudent['Estudiantes'][] = $student;
        $cases[] = $duplicateStudent;
        $conflictingPassword = $base;
        $conflictingPassword['Familias'][] = ['F00000002', 'Familia Dos'];
        $representative = $conflictingPassword['Representantes'][1];
        $representative[0] = 'F00000002';
        $representative[13] = 'OtraClave99';
        $conflictingPassword['Representantes'][] = $representative;
        $cases[] = $conflictingPassword;
        $duplicateStudentIdentification = $base;
        $studentWithIdentification = $duplicateStudentIdentification['Estudiantes'][1];
        $studentWithIdentification[7] = 'DNI';
        $studentWithIdentification[8] = 'STU-IDENTITY';
        $duplicateStudentIdentification['Estudiantes'][1] = $studentWithIdentification;
        $otherStudent = $studentWithIdentification;
        $otherStudent[9] = 'EST-002';
        $duplicateStudentIdentification['Estudiantes'][] = $otherStudent;
        $cases[] = $duplicateStudentIdentification;

        foreach ($cases as $index => $sheets) {
            $result = $reader->read($fixtures->writeWorkbook('duplicates-' . $index . '.xlsx', $sheets));
            assertSameValue(false, $result->isValid());
            assertSameValue(true, count($result->issues()) > 0);
        }
    });

    $runner->add('E015 password policy errors never disclose the submitted password', function (): void {
        $fixtures = new BulkImportXlsxFixtureFactory();
        $sheets = $fixtures->validSheets();
        $sheets['Representantes'][1][13] = 'xYz!';

        $result = e015Reader()->read($fixtures->writeWorkbook('password-error.xlsx', $sheets));
        $safe = json_encode($result->safeOutput(), JSON_THROW_ON_ERROR);
        $exported = var_export($result, true);

        assertSameValue(false, $result->isValid());
        assertSameValue(false, str_contains($safe, 'xYz!'));
        assertSameValue(false, str_contains($exported, 'xYz!'));
        assertSameValue(false, str_contains($result->issues()[0]->message, 'xYz!'));
    });

    $runner->add('E015 enforces per-sheet and combined row limits while streaming', function (): void {
        $fixtures = new BulkImportXlsxFixtureFactory();
        $reader = e015Reader();

        $individual = [
            'Familias' => [BulkImportWorkbookContract::HEADERS['Familias']],
            'Representantes' => [BulkImportWorkbookContract::HEADERS['Representantes']],
            'Estudiantes' => [BulkImportWorkbookContract::HEADERS['Estudiantes']],
        ];
        for ($i = 1; $i <= 1001; $i++) {
            $individual['Familias'][] = [sprintf('F%08d', $i), 'Familia ' . $i];
        }
        $individualPath = $fixtures->writeWorkbook('individual-row-limit.xlsx', $individual);
        e015AssertRejected(static fn () => $reader->read($individualPath), 'filas');

        $combined = [
            'Familias' => [BulkImportWorkbookContract::HEADERS['Familias']],
            'Representantes' => [BulkImportWorkbookContract::HEADERS['Representantes']],
            'Estudiantes' => [BulkImportWorkbookContract::HEADERS['Estudiantes']],
        ];
        for ($i = 1; $i <= 1000; $i++) {
            $combined['Familias'][] = [sprintf('F%08d', $i), 'Familia ' . $i];
        }
        for ($i = 1; $i <= 2000; $i++) {
            $combined['Representantes'][] = [
                sprintf('F%08d', (($i - 1) % 1000) + 1), 'Nombre' . $i, '', 'Apellido' . $i, '',
                '1980-01-01', 'SEX' . ($i % 10), 'DOC', sprintf('%08d', $i), 'r' . $i . '@example.test',
                'REL', 'NO', '2026-08-01', 'Clave' . $i,
            ];
        }
        for ($i = 1; $i <= 2001; $i++) {
            $combined['Estudiantes'][] = [
                sprintf('F%08d', (($i - 1) % 1000) + 1), 'Estudiante' . $i, '', 'Apellido' . $i, '',
                '2015-01-01', 'SEX' . ($i % 10), '', '', 'EST-' . $i, '2021-09-01', '2026-08-01',
            ];
        }
        $combinedPath = $fixtures->writeWorkbook('combined-row-limit.xlsx', $combined);
        e015AssertRejected(static fn () => $reader->read($combinedPath), '5000');
    });

    $runner->add('E015 accepts equals-prefixed plain text but never a real formula cell', function (): void {
        $fixtures = new BulkImportXlsxFixtureFactory();
        $path = $fixtures->writeWorkbook('plain-equals.xlsx', $fixtures->validSheets());
        $fixtures->mutateEntry($path, 'xl/worksheets/sheet1.xml', static function (string $xml): string {
            $updated = preg_replace(
                '/<c r="B2"[^>]*>.*?<\/c>/',
                '<c r="B2" t="inlineStr"><is><t>=texto visible</t></is></c>',
                $xml,
                1,
            );

            return is_string($updated) ? $updated : $xml;
        });

        $result = e015Reader()->read($path);
        assertSameValue(true, $result->isValid());
        assertSameValue('=texto visible', $result->workbook()?->families[0]->displayName);
    });

    $runner->add('E015 bounds collected row issues without retaining row payloads', function (): void {
        $fixtures = new BulkImportXlsxFixtureFactory();
        $sheets = $fixtures->validSheets();
        $sheets['Representantes'] = [BulkImportWorkbookContract::HEADERS['Representantes']];
        for ($row = 0; $row < 600; $row++) {
            $invalid = array_fill(0, 14, null);
            $invalid[0] = 'BAD-' . $row;
            $sheets['Representantes'][] = $invalid;
        }

        $result = e015Reader()->read($fixtures->writeWorkbook('issue-limit.xlsx', $sheets));
        assertSameValue(false, $result->isValid());
        assertSameValue(BulkImportWorkbookContract::MAXIMUM_ISSUES, count($result->issues()));
        assertSameValue(
            true,
            str_contains($result->issues()[BulkImportWorkbookContract::MAXIMUM_ISSUES - 1]->message, 'límite'),
        );
    });
}

function e015Reader(): OpenSpoutBulkImportWorkbookReader
{
    return new OpenSpoutBulkImportWorkbookReader(
        new XlsxContainerPreflightInspector(),
        new ValidateBulkImportWorkbook(
            new RepresentativePasswordPolicy(),
            new DateTimeImmutable('2026-09-01 00:00:00 UTC'),
        ),
    );
}

function e015AssertRejected(callable $operation, ?string $messageContains = null): void
{
    try {
        $operation();
    } catch (BulkImportWorkbookRejected $exception) {
        if ($messageContains !== null) {
            assertSameValue(true, str_contains($exception->getMessage(), $messageContains));
        }

        return;
    }

    throw new \RuntimeException('Expected hostile XLSX rejection.');
}

function e015ModeratelyCompressibleBytes(int $targetBytes): string
{
    $result = '';
    while (strlen($result) < $targetBytes) {
        $unit = random_bytes(64);
        $result .= str_repeat($unit, 10);
    }

    return substr($result, 0, $targetBytes);
}
