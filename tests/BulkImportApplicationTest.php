<?php

declare(strict_types=1);

namespace Tests;

use App\BulkImport\Application\Dto\BulkImportClassification;
use App\BulkImport\Application\Dto\BulkImportWorkbook;
use App\BulkImport\Application\Dto\FamilyWorkbookRow;
use App\BulkImport\Application\Dto\RepresentativeWorkbookRow;
use App\BulkImport\Application\Dto\SensitivePlaintextPassword;
use App\BulkImport\Application\Dto\StudentWorkbookRow;
use App\Family\Domain\ValueObject\FamilyCode;
use App\Person\Domain\Person;
use App\Person\Domain\PersonStatus;
use App\Person\Domain\ValueObject\ContactInformation;
use App\Person\Domain\ValueObject\Identification;
use App\Person\Domain\ValueObject\PersonalName;
use App\Person\Domain\ValueObject\PersonId;
use App\Representative\Domain\Representative;
use App\Representative\Domain\RepresentativeStatus;
use App\Representative\Domain\ValueObject\PersonId as RepresentativePersonId;
use App\Representative\Domain\ValueObject\RepresentativeId;
use App\Student\Domain\ValueObject\InstitutionalCode;
use DateTimeImmutable;
use DateTimeZone;
use Tests\Support\TestRunner;

function registerBulkImportApplicationTests(TestRunner $runner): void
{
    $runner->add('E015 Phase 6 Preview is read-only safe password-free and classifies NEW', function (): void {
        $environment = new BulkImportApplicationEnvironment(e015Phase6Workbook());
        $before = e015Phase6SaveCounts($environment);
        $result = $environment->preview->handle('ignored.xlsx', e015Phase6Today());
        $safe = json_encode($result->safeOutput(), JSON_THROW_ON_ERROR);

        assertSameValue(1, $result->families);
        assertSameValue(3, $result->new);
        assertSameValue(0, $result->alreadyExists);
        assertSameValue(0, $result->conflicts);
        assertSameValue($before, e015Phase6SaveCounts($environment));
        assertSameValue(true, str_contains($safe, '******5678'));
        assertSameValue(false, str_contains($safe, '1712345678'));
        assertSameValue(false, str_contains($safe, 'ClaveSegura9'));
        assertSameValue(false, str_contains($safe, 'representative@example.test'));
        assertSameValue(false, str_contains($safe, '1985-01-02'));
        assertSameValue(false, property_exists($result->items[1], 'password'));
    });

    $runner->add('E015 Phase 6 Apply creates one complete Family atomically and exact reimport is idempotent', function (): void {
        $workbook = e015Phase6Workbook();
        $environment = new BulkImportApplicationEnvironment($workbook);
        $first = $environment->apply->handle('ignored.xlsx', e015Phase6Today());
        $afterFirst = e015Phase6SaveCounts($environment);
        $second = $environment->apply->handle('ignored.xlsx', e015Phase6Today());

        assertSameValue(1, count($first->families));
        assertSameValue(BulkImportClassification::New, $first->families[0]->classification);
        assertSameValue(BulkImportClassification::AlreadyExists, $second->families[0]->classification);
        assertSameValue($afterFirst, e015Phase6SaveCounts($environment));
        assertSameValue(2, $environment->persons->saveCalls());
        assertSameValue(1, $environment->representatives->saveCalls());
        assertSameValue(1, $environment->users->saveCalls());
        assertSameValue(1, $environment->students->saveCalls());
        assertSameValue(2, $environment->transactions->commitCount());
        assertSameValue(0, $environment->transactions->rollbackCount());

        $family = $environment->families->findByCode(new FamilyCode('F00000001'));
        assertSameValue('Familia Uno', $family?->displayName()->value());
        assertSameValue(1, count($family?->activeRepresentatives() ?? []));
        assertSameValue(1, count($family?->activeStudents() ?? []));
        assertSameValue(true, $family?->primaryRepresentative()->isPrimary());
        assertSameValue('', $workbook->representatives[0]->initialPassword?->reveal());
    });

    $runner->add('E015 Phase 6 Apply blocks existing Family mismatch without writes', function (): void {
        $environment = new BulkImportApplicationEnvironment(e015Phase6Workbook());
        $environment->apply->handle('ignored.xlsx', e015Phase6Today());
        $before = e015Phase6SaveCounts($environment);
        $conflicting = e015Phase6Workbook('Nombre diferente');
        $environment->reader->replace($conflicting);

        $result = $environment->apply->handle('ignored.xlsx', e015Phase6Today());

        assertSameValue(BulkImportClassification::Conflict, $result->families[0]->classification);
        assertSameValue($before, e015Phase6SaveCounts($environment));
        assertSameValue(1, count($result->families[0]->issues));
    });

    $runner->add('E015 Phase 6 shared matcher retains live Person data and rejects only Sex mismatch', function (): void {
        $environment = new BulkImportApplicationEnvironment(e015Phase6Workbook());
        $environment->apply->handle('ignored.xlsx', e015Phase6Today());
        $equivalent = e015Phase6Workbook();
        $equivalentRepresentative = $equivalent->representatives[0];
        $equivalent = new BulkImportWorkbook(
            $equivalent->families,
            [new RepresentativeWorkbookRow(
                $equivalentRepresentative->sourceRow,
                $equivalentRepresentative->familyCode,
                'Nombre nuevo ignorado',
                null,
                'Apellido nuevo ignorado',
                null,
                new DateTimeImmutable('1999-09-09 UTC'),
                $equivalentRepresentative->sexCode,
                $equivalentRepresentative->documentTypeCode,
                $equivalentRepresentative->documentNumber,
                'changed@example.test',
                $equivalentRepresentative->relationshipTypeCode,
                $equivalentRepresentative->isPrimary,
                $equivalentRepresentative->startedAt,
                null,
            )],
            $equivalent->students,
        );
        $environment->reader->replace($equivalent);
        $safe = $environment->preview->handle('ignored.xlsx', e015Phase6Today());
        assertSameValue(BulkImportClassification::AlreadyExists, $safe->items[1]->classification);

        $sexConflict = e015Phase6Workbook();
        $row = $sexConflict->representatives[0];
        $sexConflict = new BulkImportWorkbook(
            $sexConflict->families,
            [new RepresentativeWorkbookRow(
                $row->sourceRow,
                $row->familyCode,
                $row->firstName,
                $row->middleName,
                $row->firstSurname,
                $row->secondSurname,
                $row->birthDate,
                'MALE',
                $row->documentTypeCode,
                $row->documentNumber,
                $row->email,
                $row->relationshipTypeCode,
                $row->isPrimary,
                $row->startedAt,
                null,
            )],
            $sexConflict->students,
        );
        $environment->reader->replace($sexConflict);
        $conflict = $environment->preview->handle('ignored.xlsx', e015Phase6Today());
        assertSameValue(BulkImportClassification::Conflict, $conflict->items[1]->classification);
    });

    $runner->add('E015 Phase 6 Apply orders Families and reuses one Representative across two roots', function (): void {
        $second = e015Phase6Workbook(
            'Familia Dos',
            'F00000002',
            '1712345678',
            'EST-002',
            3,
        );
        $first = e015Phase6Workbook(
            'Familia Uno',
            'F00000001',
            '1712345678',
            'EST-001',
            2,
        );
        $workbook = new BulkImportWorkbook(
            [...$second->families, ...$first->families],
            [...$second->representatives, ...$first->representatives],
            [...$second->students, ...$first->students],
        );
        $environment = new BulkImportApplicationEnvironment($workbook);

        $result = $environment->apply->handle('ignored.xlsx', e015Phase6Today());

        assertSameValue(
            ['F00000001', 'F00000002'],
            array_map(static fn ($item): string => $item->familyCode, $result->families),
        );
        assertSameValue(3, $environment->persons->saveCalls());
        assertSameValue(1, $environment->representatives->saveCalls());
        assertSameValue(1, $environment->users->saveCalls());
        assertSameValue(2, $environment->students->saveCalls());
        assertSameValue(2, $environment->transactions->commitCount());
    });

    $runner->add('E015 Phase 6 Apply isolates valid Families around one conflict', function (): void {
        $seed = e015Phase6Workbook(
            'Familia B vigente',
            'F00000002',
            '1722222222',
            'EST-B',
            2,
        );
        $environment = new BulkImportApplicationEnvironment($seed);
        $environment->apply->handle('ignored.xlsx', e015Phase6Today());
        $commitsBefore = $environment->transactions->commitCount();

        $a = e015Phase6Workbook('Familia A', 'F00000001', '1711111111', 'EST-A', 2);
        $b = e015Phase6Workbook('Familia B distinta', 'F00000002', '1722222222', 'EST-B', 3);
        $c = e015Phase6Workbook('Familia C', 'F00000003', '1733333333', 'EST-C', 4);
        $environment->reader->replace(new BulkImportWorkbook(
            [...$c->families, ...$b->families, ...$a->families],
            [...$c->representatives, ...$b->representatives, ...$a->representatives],
            [...$c->students, ...$b->students, ...$a->students],
        ));

        $result = $environment->apply->handle('ignored.xlsx', e015Phase6Today());

        assertSameValue(
            [
                BulkImportClassification::New,
                BulkImportClassification::Conflict,
                BulkImportClassification::New,
            ],
            array_map(static fn ($item) => $item->classification, $result->families),
        );
        assertSameValue($commitsBefore + 2, $environment->transactions->commitCount());
        assertSameValue('Familia B vigente', $environment->families
            ->findByCode(new FamilyCode('F00000002'))?->displayName()->value());
        assertSameValue(true, $environment->families
            ->findByCode(new FamilyCode('F00000001')) !== null);
        assertSameValue(true, $environment->families
            ->findByCode(new FamilyCode('F00000003')) !== null);
    });

    $runner->add('E015 Phase 6 Apply reuses existing Person for new Representative and User', function (): void {
        $workbook = e015Phase6Workbook();
        $workbook = new BulkImportWorkbook($workbook->families, $workbook->representatives, []);
        $environment = new BulkImportApplicationEnvironment($workbook);
        e015Phase6SeedPerson($environment, 201, '1712345678');

        $result = $environment->apply->handle('ignored.xlsx', e015Phase6Today());

        assertSameValue(BulkImportClassification::New, $result->families[0]->classification);
        assertSameValue(0, $environment->persons->saveCalls());
        assertSameValue(1, $environment->representatives->saveCalls());
        assertSameValue(1, $environment->users->saveCalls());
    });

    $runner->add('E015 Phase 6 Apply creates missing User and Student for one multi-role Person', function (): void {
        $workbook = e015Phase6Workbook();
        $student = $workbook->students[0];
        $workbook = new BulkImportWorkbook(
            $workbook->families,
            $workbook->representatives,
            [new StudentWorkbookRow(
                $student->sourceRow,
                $student->familyCode,
                $student->firstName,
                $student->middleName,
                $student->firstSurname,
                $student->secondSurname,
                $student->birthDate,
                $student->sexCode,
                'DNI',
                '1712345678',
                $student->institutionalCode,
                $student->admissionDate,
                $student->startedAt,
            )],
        );
        $environment = new BulkImportApplicationEnvironment($workbook);
        e015Phase6SeedPerson($environment, 201, '1712345678');
        $environment->representatives->seed(new Representative(
            new RepresentativeId(301),
            new RepresentativePersonId(201),
            null,
            RepresentativeStatus::Active,
        ));

        $result = $environment->apply->handle('ignored.xlsx', e015Phase6Today());

        assertSameValue(BulkImportClassification::New, $result->families[0]->classification);
        assertSameValue(0, $environment->persons->saveCalls());
        assertSameValue(0, $environment->representatives->saveCalls());
        assertSameValue(1, $environment->users->saveCalls());
        assertSameValue(1, $environment->students->saveCalls());
        assertSameValue(201, $environment->students
            ->findByInstitutionalCode(new InstitutionalCode('EST-001'))?->personId()->value());
    });

    $runner->add('E015 Phase 6 Apply rolls back one failed Family without partial writes', function (): void {
        $environment = new BulkImportApplicationEnvironment(e015Phase6Workbook());
        $environment->families->returnWithoutNewStudentMembershipId();

        $result = $environment->apply->handle('ignored.xlsx', e015Phase6Today());

        assertSameValue(BulkImportClassification::Conflict, $result->families[0]->classification);
        assertSameValue(0, $environment->transactions->commitCount());
        assertSameValue(1, $environment->transactions->rollbackCount());
        assertSameValue(null, $environment->families->findByCode(new FamilyCode('F00000001')));
        assertSameValue(0, $environment->persons->saveCalls());
        assertSameValue(0, $environment->representatives->saveCalls());
        assertSameValue(0, $environment->users->saveCalls());
        assertSameValue(0, $environment->students->saveCalls());
    });

    $runner->add('E015 Phase 6 Apply maps a deadlock once without retry or disclosure', function (): void {
        $transactions = new E015ConcurrentChangeTransactionRunner();
        $workbook = e015Phase6Workbook();
        $environment = new BulkImportApplicationEnvironment($workbook, $transactions);

        $result = $environment->apply->handle('ignored.xlsx', e015Phase6Today());

        assertSameValue(BulkImportClassification::Conflict, $result->families[0]->classification);
        assertSameValue('CONCURRENT_CHANGE', $result->families[0]->issues[0]->category->value);
        assertSameValue(1, $transactions->calls());
        assertSameValue(0, array_sum(e015Phase6SaveCounts($environment)));
        assertSameValue('', $workbook->representatives[0]->initialPassword?->reveal());
        assertSameValue(false, str_contains($result->families[0]->message, '1213'));
    });
}

function e015Phase6Workbook(
    string $displayName = 'Familia Uno',
    string $familyCodeValue = 'F00000001',
    string $documentNumber = '1712345678',
    string $institutionalCode = 'EST-001',
    int $sourceRow = 2,
): BulkImportWorkbook
{
    $familyCode = new FamilyCode($familyCodeValue);

    return new BulkImportWorkbook(
        [new FamilyWorkbookRow($sourceRow, $familyCode, $displayName)],
        [new RepresentativeWorkbookRow(
            $sourceRow,
            $familyCode,
            'Ana',
            null,
            'Representante',
            null,
            new DateTimeImmutable('1985-01-02 UTC'),
            'FEMALE',
            'DNI',
            $documentNumber,
            'representative@example.test',
            'PARENT',
            true,
            new DateTimeImmutable('2026-09-01 12:13:14 UTC'),
            new SensitivePlaintextPassword('ClaveSegura9'),
        )],
        [new StudentWorkbookRow(
            $sourceRow,
            $familyCode,
            'Estudiante',
            null,
            'Uno',
            null,
            new DateTimeImmutable('2015-03-04 UTC'),
            'FEMALE',
            null,
            null,
            new InstitutionalCode($institutionalCode),
            new DateTimeImmutable('2026-09-01 UTC'),
            new DateTimeImmutable('2026-09-01 12:13:15 UTC'),
        )],
    );
}

/** @return array<string, int> */
function e015Phase6SaveCounts(BulkImportApplicationEnvironment $environment): array
{
    return [
        'persons' => $environment->persons->saveCalls(),
        'representatives' => $environment->representatives->saveCalls(),
        'users' => $environment->users->saveCalls(),
        'students' => $environment->students->saveCalls(),
        'families' => $environment->families->saveCalls(),
    ];
}

function e015Phase6Today(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-09-02 00:00:00', new DateTimeZone('UTC'));
}

function e015Phase6SeedPerson(
    BulkImportApplicationEnvironment $environment,
    int $id,
    string $documentNumber,
): void {
    $environment->persons->seed(new Person(
        new PersonId($id),
        new PersonalName('Actual', null, 'Persona', null),
        new Identification(1, $documentNumber),
        new DateTimeImmutable('1980-01-01', new DateTimeZone('UTC')),
        1,
        null,
        null,
        new ContactInformation('actual@example.test', null, null),
        PersonStatus::Active,
        e015Phase6Today(),
    ));
}
