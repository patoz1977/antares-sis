<?php

declare(strict_types=1);

namespace Tests;

use App\BulkImport\Application\ApplyBulkImport;
use App\BulkImport\Application\Dto\BulkImportWorkbook;
use App\BulkImport\Application\Planning\BulkImportMatcher;
use App\BulkImport\Application\PreviewBulkImport;
use App\Family\Application\AddRepresentativeToFamily;
use App\Family\Application\AddStudentToFamily;
use App\Family\Application\CreateFamily;
use App\Family\Application\Orchestration\StudentFamilyCoordinator;
use App\IdentityAccess\Application\CreateRepresentativeUser;
use App\IdentityAccess\Application\Orchestration\CreateRepresentativeAccess;
use App\IdentityAccess\Application\Security\RepresentativePasswordPolicy;
use App\IdentityAccess\Infrastructure\Security\NativePasswordHasher;
use App\Person\Application\CreatePerson;
use App\Person\Application\GetPerson;
use App\Representative\Application\CreateRepresentative;
use App\Student\Application\CreateStudent;
use App\Student\Application\GetStudent;
use Core\Application\TransactionRunner;

final class BulkImportApplicationEnvironment
{
    public readonly InMemoryPersonApplicationRepository $persons;
    public readonly InMemoryRepresentativeApplicationRepository $representatives;
    public readonly InMemoryRepresentativeUserRepository $users;
    public readonly InMemoryStudentApplicationRepository $students;
    public readonly InMemoryFamilyApplicationRepository $families;
    public readonly TransactionRunner $transactions;
    public readonly MutableBulkImportWorkbookReader $reader;
    public readonly PreviewBulkImport $preview;
    public readonly ApplyBulkImport $apply;

    public function __construct(
        BulkImportWorkbook $workbook,
        ?TransactionRunner $transactions = null,
    ) {
        $this->persons = new InMemoryPersonApplicationRepository(e015Phase6Today(), 101);
        $this->representatives = new InMemoryRepresentativeApplicationRepository(501);
        $this->users = new InMemoryRepresentativeUserRepository(601);
        $this->students = new InMemoryStudentApplicationRepository(701);
        $this->families = new InMemoryFamilyApplicationRepository(801, 901, 1001);
        $this->transactions = $transactions ?? new InMemoryCompositeTransactionRunner([
            $this->persons,
            $this->representatives,
            $this->users,
            $this->students,
            $this->families,
        ]);
        $this->reader = new MutableBulkImportWorkbookReader($workbook);
        $catalogs = new FakeBulkImportCatalogResolver();
        $passwordPolicy = new RepresentativePasswordPolicy();
        $matcher = new BulkImportMatcher(
            $catalogs,
            $this->persons,
            $this->representatives,
            $this->users,
            $this->students,
            $this->families,
            $passwordPolicy,
        );
        $createPerson = new CreatePerson($this->persons);
        $getPerson = new GetPerson($this->persons);
        $createRepresentative = new CreateRepresentative($this->persons, $this->representatives);
        $createUser = new CreateRepresentativeUser(
            $this->representatives,
            $this->persons,
            $this->users,
            new NativePasswordHasher(),
            $passwordPolicy,
        );
        $createAccess = new CreateRepresentativeAccess(
            $createPerson,
            $getPerson,
            $createRepresentative,
            $createUser,
        );
        $relationshipTypes = new FakeRelationshipTypeLookup([11]);
        $createFamily = new CreateFamily(
            $this->families,
            $this->representatives,
            $relationshipTypes,
            new FamilyCodeTestGenerator(),
        );
        $studentCoordinator = new StudentFamilyCoordinator(
            $createPerson,
            $getPerson,
            new CreateStudent($this->persons, $this->students),
            new GetStudent($this->students),
            new AddStudentToFamily($this->families, $this->students),
        );
        $this->preview = new PreviewBulkImport($this->reader, $matcher);
        $this->apply = new ApplyBulkImport(
            $this->reader,
            $matcher,
            $this->transactions,
            $createAccess,
            $createRepresentative,
            $createUser,
            $createFamily,
            new AddRepresentativeToFamily(
                $this->families,
                $this->representatives,
                $relationshipTypes,
            ),
            $studentCoordinator,
        );
    }
}
