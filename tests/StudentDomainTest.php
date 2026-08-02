<?php

declare(strict_types=1);

namespace Tests;

use App\Student\Domain\Exception\InvalidStudentState;
use App\Student\Domain\Student;
use App\Student\Domain\StudentStatus;
use App\Student\Domain\ValueObject\AdmissionDate;
use App\Student\Domain\ValueObject\InstitutionalCode;
use App\Student\Domain\ValueObject\PersonId;
use App\Student\Domain\ValueObject\StudentId;
use DateTimeImmutable;
use ReflectionClass;
use Tests\Support\TestRunner;
use TypeError;

function registerStudentDomainTests(TestRunner $runner): void
{
    $runner->add('StudentId requires a positive immutable identity and compares by value', function (): void {
        $identity = new StudentId(10);
        $property = (new ReflectionClass(StudentId::class))->getProperty('value');

        assertSameValue(10, $identity->value());
        assertSameValue(true, $identity->equals(new StudentId(10)));
        assertSameValue(false, $identity->equals(new StudentId(11)));
        assertSameValue(true, $property->isReadOnly());
        assertThrows(static fn (): StudentId => new StudentId(0), InvalidStudentState::class);
        assertThrows(static fn (): StudentId => new StudentId(-1), InvalidStudentState::class);
    });

    $runner->add('Student PersonId is an independent positive immutable identity', function (): void {
        $identity = new PersonId(20);
        $property = (new ReflectionClass(PersonId::class))->getProperty('value');

        assertSameValue(20, $identity->value());
        assertSameValue(true, $identity->equals(new PersonId(20)));
        assertSameValue(false, $identity->equals(new PersonId(21)));
        assertSameValue(true, $property->isReadOnly());
        assertSameValue('App\\Student\\Domain\\ValueObject', (new ReflectionClass($identity))->getNamespaceName());
        assertThrows(static fn (): PersonId => new PersonId(0), InvalidStudentState::class);
        assertThrows(static fn (): PersonId => new PersonId(-1), InvalidStudentState::class);
    });

    $runner->add('InstitutionalCode trims boundaries and preserves case and internal format', function (): void {
        $code = new InstitutionalCode('  Ab-Cd / 007  ');

        assertSameValue('Ab-Cd / 007', $code->value());
        assertSameValue(true, $code->equals(new InstitutionalCode('Ab-Cd / 007')));
        assertSameValue(false, $code->equals(new InstitutionalCode('AB-CD / 007')));
        assertSameValue(true, (new ReflectionClass(InstitutionalCode::class))->isReadOnly());
    });

    $runner->add('InstitutionalCode accepts exactly 100 characters and rejects missing or excessive values', function (): void {
        $maximumCode = str_repeat('x', 100);

        assertSameValue($maximumCode, (new InstitutionalCode($maximumCode))->value());
        assertThrows(static fn (): InstitutionalCode => new InstitutionalCode(' '), InvalidStudentState::class);
        assertThrows(
            static fn (): InstitutionalCode => new InstitutionalCode(str_repeat('x', 101)),
            InvalidStudentState::class
        );
    });

    $runner->add('AdmissionDate normalizes to midnight and compares by calendar value', function (): void {
        $date = new AdmissionDate(
            new DateTimeImmutable('2020-09-01 16:45:30'),
            new DateTimeImmutable('2026-08-01 09:00:00'),
        );

        assertSameValue('2020-09-01', $date->value()->format('Y-m-d'));
        assertSameValue('00:00:00', $date->value()->format('H:i:s'));
        assertSameValue(
            true,
            $date->equals(new AdmissionDate(
                new DateTimeImmutable('2020-09-01 01:15:00'),
                new DateTimeImmutable('2021-01-01'),
            ))
        );
        assertSameValue(
            false,
            $date->equals(new AdmissionDate(new DateTimeImmutable('2020-09-02'), new DateTimeImmutable('2021-01-01')))
        );
        assertSameValue(true, (new ReflectionClass(AdmissionDate::class))->isReadOnly());
    });

    $runner->add('AdmissionDate allows today and rejects future dates using explicit current date', function (): void {
        $today = new AdmissionDate(
            new DateTimeImmutable('2026-08-01 23:59:59'),
            new DateTimeImmutable('2026-08-01 00:00:01'),
        );

        assertSameValue('2026-08-01', $today->value()->format('Y-m-d'));
        assertThrows(
            static fn (): AdmissionDate => new AdmissionDate(
                new DateTimeImmutable('2026-08-02'),
                new DateTimeImmutable('2026-08-01'),
            ),
            InvalidStudentState::class
        );
        assertSameValue(
            '2026-08-02',
            (new AdmissionDate(
                new DateTimeImmutable('2026-08-02'),
                new DateTimeImmutable('2026-08-02'),
            ))->value()->format('Y-m-d')
        );
    });

    $runner->add('Student constructs new and reconstructed active or inactive states', function (): void {
        $persisted = studentDomainFixture();
        $new = new Student(
            null,
            new PersonId(30),
            new InstitutionalCode('NEW-30'),
            new AdmissionDate(new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-08-01')),
            StudentStatus::Inactive,
        );

        assertSameValue(1, $persisted->id()?->value());
        assertSameValue(2, $persisted->personId()->value());
        assertSameValue('STU-002', $persisted->institutionalCode()->value());
        assertSameValue('2020-09-01', $persisted->admissionDate()->value()->format('Y-m-d'));
        assertSameValue(StudentStatus::Active, $persisted->status());
        assertSameValue(true, $persisted->isActive());

        assertSameValue(null, $new->id());
        assertSameValue(30, $new->personId()->value());
        assertSameValue(StudentStatus::Inactive, $new->status());
        assertSameValue(false, $new->isActive());
    });

    $runner->add('Student requires PersonId InstitutionalCode and AdmissionDate through typed construction', function (): void {
        $personId = new PersonId(1);
        $code = new InstitutionalCode('STU-001');
        $date = new AdmissionDate(new DateTimeImmutable('2020-01-01'), new DateTimeImmutable('2026-08-01'));

        assertThrows(
            static fn (): Student => new Student(null, null, $code, $date, StudentStatus::Active),
            TypeError::class
        );
        assertThrows(
            static fn (): Student => new Student(null, $personId, null, $date, StudentStatus::Active),
            TypeError::class
        );
        assertThrows(
            static fn (): Student => new Student(null, $personId, $code, null, StudentStatus::Active),
            TypeError::class
        );
    });

    $runner->add('Student identity and Person reference cannot be reassigned', function (): void {
        $student = studentDomainFixture();
        $reflection = new ReflectionClass(Student::class);

        assertSameValue(true, $reflection->getProperty('id')->isReadOnly());
        assertSameValue(true, $reflection->getProperty('personId')->isReadOnly());
        assertSameValue(false, method_exists($student, 'setId'));
        assertSameValue(false, method_exists($student, 'setPersonId'));

        $student->updateAcademicInformation(
            new InstitutionalCode('UPDATED'),
            new AdmissionDate(new DateTimeImmutable('2021-01-01'), new DateTimeImmutable('2026-08-01')),
        );

        assertSameValue(1, $student->id()?->value());
        assertSameValue(2, $student->personId()->value());
    });

    $runner->add('Student administratively updates InstitutionalCode and AdmissionDate together', function (): void {
        $student = studentDomainFixture();
        $newCode = new InstitutionalCode('Updated-Code / 10');
        $newDate = new AdmissionDate(
            new DateTimeImmutable('2021-02-03 15:30:00'),
            new DateTimeImmutable('2026-08-01'),
        );

        $student->updateAcademicInformation($newCode, $newDate);

        assertSameValue(true, $student->institutionalCode()->equals($newCode));
        assertSameValue(true, $student->admissionDate()->equals($newDate));
        assertSameValue('00:00:00', $student->admissionDate()->value()->format('H:i:s'));
    });

    $runner->add('Student administrative update can change either value while retaining the other', function (): void {
        $student = studentDomainFixture();
        $originalDate = $student->admissionDate();

        $student->updateAcademicInformation(new InstitutionalCode('ONLY-CODE'), $originalDate);
        assertSameValue('ONLY-CODE', $student->institutionalCode()->value());
        assertSameValue(true, $student->admissionDate()->equals($originalDate));

        $currentCode = $student->institutionalCode();
        $newDate = new AdmissionDate(new DateTimeImmutable('2022-04-05'), new DateTimeImmutable('2026-08-01'));
        $student->updateAcademicInformation($currentCode, $newDate);
        assertSameValue(true, $student->institutionalCode()->equals($currentCode));
        assertSameValue(true, $student->admissionDate()->equals($newDate));
    });

    $runner->add('Student keeps previous academic information when a new Value Object is invalid', function (): void {
        $student = studentDomainFixture();
        $originalCode = $student->institutionalCode();
        $originalDate = $student->admissionDate();

        assertThrows(
            static function () use ($student): void {
                $student->updateAcademicInformation(
                    new InstitutionalCode(str_repeat('x', 101)),
                    new AdmissionDate(new DateTimeImmutable('2021-01-01'), new DateTimeImmutable('2026-08-01')),
                );
            },
            InvalidStudentState::class
        );
        assertThrows(
            static function () use ($student): void {
                $student->updateAcademicInformation(
                    new InstitutionalCode('CHANGED'),
                    new AdmissionDate(new DateTimeImmutable('2026-08-02'), new DateTimeImmutable('2026-08-01')),
                );
            },
            InvalidStudentState::class
        );

        assertSameValue(true, $student->institutionalCode()->equals($originalCode));
        assertSameValue(true, $student->admissionDate()->equals($originalDate));
        assertSameValue(StudentStatus::Active, $student->status());
    });

    $runner->add('Student activation lifecycle uses only GENERAL_STATUS codes', function (): void {
        $student = studentDomainFixture();

        $student->deactivate();
        assertSameValue(StudentStatus::Inactive, $student->status());
        assertSameValue(false, $student->isActive());

        $student->activate();
        assertSameValue(StudentStatus::Active, $student->status());
        assertSameValue(true, $student->isActive());
        assertSameValue('ACTIVE', StudentStatus::Active->value);
        assertSameValue('INACTIVE', StudentStatus::Inactive->value);
    });

    $runner->add('Student Domain stays isolated and contains only the approved Aggregate state', function (): void {
        $domainDirectory = __DIR__ . '/../app/Student/Domain';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($domainDirectory));
        $phpFiles = [];
        $source = '';

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $phpFiles[] = str_replace('\\', '/', $file->getPathname());
                $source .= (string) file_get_contents($file->getPathname());
            }
        }

        sort($phpFiles, SORT_STRING);
        $properties = array_map(
            static fn (\ReflectionProperty $property): string => $property->getName(),
            (new ReflectionClass(Student::class))->getProperties()
        );
        sort($properties, SORT_STRING);

        assertSameValue(7, count($phpFiles));
        assertSameValue(['admissionDate', 'id', 'institutionalCode', 'personId', 'status'], $properties);
        assertSameValue(false, method_exists(Student::class, 'grade'));
        assertSameValue(false, method_exists(Student::class, 'section'));

        foreach (['App\\Person', 'App\\Representative', 'Family', 'Enrollment', 'Grade', 'Section', 'PDO', 'SQL', 'Repository', 'Service', 'Controller', 'Http', 'View'] as $forbidden) {
            assertSameValue(false, str_contains($source, $forbidden));
        }
    });
}

function studentDomainFixture(): Student
{
    return new Student(
        new StudentId(1),
        new PersonId(2),
        new InstitutionalCode('STU-002'),
        new AdmissionDate(new DateTimeImmutable('2020-09-01'), new DateTimeImmutable('2026-08-01')),
        StudentStatus::Active,
    );
}
