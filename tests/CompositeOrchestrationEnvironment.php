<?php

declare(strict_types=1);

namespace Tests;

use App\Family\Application\AddStudentToFamily;
use App\Family\Application\CreateFamily;
use App\Family\Application\GetFamily;
use App\Family\Application\Orchestration\CreateRepresentativeFamily;
use App\Family\Application\Orchestration\CreateStudentInFamily;
use App\Family\Application\RelationshipTypeLookup;
use App\Family\Domain\Family;
use App\Family\Domain\FamilyRepresentative;
use App\Family\Domain\FamilyRepository;
use App\Family\Domain\FamilyStatus;
use App\Family\Domain\FamilyStudent;
use App\Family\Domain\ValueObject\DisplayName;
use App\Family\Domain\ValueObject\FamilyId;
use App\Family\Domain\ValueObject\FamilyRepresentativeId;
use App\Family\Domain\ValueObject\FamilyStudentId;
use App\Family\Domain\ValueObject\RelationshipTypeId;
use App\Family\Domain\ValueObject\RepresentativeId;
use App\Family\Domain\ValueObject\StudentId;
use App\Person\Application\CreatePerson;
use App\Representative\Application\CreateRepresentative;
use App\Student\Application\CreateStudent;
use DateTimeImmutable;
use DateTimeZone;

final class CompositeOrchestrationEnvironment
{
    public readonly DateTimeImmutable $today;

    public readonly InMemoryPersonApplicationRepository $persons;

    public readonly InMemoryRepresentativeApplicationRepository $representatives;

    public readonly InMemoryStudentApplicationRepository $students;

    public readonly InMemoryFamilyApplicationRepository $families;

    public readonly FakeRelationshipTypeLookup $relationshipTypes;

    public readonly InMemoryCompositeTransactionRunner $transactions;

    public readonly int $familyId;

    public function __construct()
    {
        $this->today = self::date('2026-08-05 23:45:00+00:00');
        $this->persons = new InMemoryPersonApplicationRepository($this->today, 101);
        $this->representatives = new InMemoryRepresentativeApplicationRepository(501);
        $this->students = new InMemoryStudentApplicationRepository(701);
        $this->families = new InMemoryFamilyApplicationRepository(901, 1001, 1101);
        $this->relationshipTypes = new FakeRelationshipTypeLookup([11]);
        $this->familyId = 401;
        $this->families->seed(self::existingFamily($this->familyId));
        $this->transactions = new InMemoryCompositeTransactionRunner([
            $this->persons,
            $this->representatives,
            $this->students,
            $this->families,
        ]);
    }

    public function representativeFlow(
        ?RelationshipTypeLookup $relationshipTypes = null,
        ?FamilyRepository $families = null,
    ): CreateRepresentativeFamily {
        return new CreateRepresentativeFamily(
            $this->transactions,
            new CreatePerson($this->persons),
            new CreateRepresentative($this->persons, $this->representatives),
            new CreateFamily(
                $families ?? $this->families,
                $this->representatives,
                $relationshipTypes ?? $this->relationshipTypes,
            ),
        );
    }

    public function studentFlow(?FamilyRepository $families = null): CreateStudentInFamily
    {
        $familyRepository = $families ?? $this->families;

        return new CreateStudentInFamily(
            $this->transactions,
            new GetFamily($familyRepository),
            new CreatePerson($this->persons),
            new CreateStudent($this->persons, $this->students),
            new AddStudentToFamily($familyRepository, $this->students),
        );
    }

    private static function existingFamily(int $familyId): Family
    {
        return Family::reconstitute(
            new FamilyId($familyId),
            new DisplayName('Existing Composite Family'),
            FamilyStatus::Active,
            [new FamilyRepresentative(
                new FamilyRepresentativeId(31),
                new RepresentativeId(32),
                new RelationshipTypeId(11),
                true,
                self::date('2025-01-01 08:00:00+00:00'),
                null,
            )],
            [new FamilyStudent(
                new FamilyStudentId(40),
                new StudentId(41),
                self::date('2025-01-02 08:00:00+00:00'),
                self::date('2025-12-31 12:00:00+00:00'),
            )],
        );
    }

    private static function date(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }
}
