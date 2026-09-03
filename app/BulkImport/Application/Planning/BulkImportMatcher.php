<?php

declare(strict_types=1);

namespace App\BulkImport\Application\Planning;

use App\BulkImport\Application\Catalog\BulkImportCatalogKind;
use App\BulkImport\Application\Catalog\BulkImportCatalogResolver;
use App\BulkImport\Application\Dto\BulkImportClassification;
use App\BulkImport\Application\Dto\BulkImportIssueCategory;
use App\BulkImport\Application\Dto\BulkImportWorkbook;
use App\BulkImport\Application\Dto\FamilyWorkbookRow;
use App\BulkImport\Application\Dto\RepresentativeWorkbookRow;
use App\BulkImport\Application\Dto\StudentWorkbookRow;
use App\BulkImport\Application\Dto\ValidationIssue;
use App\BulkImport\Application\Dto\WorkbookSourceLocation;
use App\Family\Domain\Family;
use App\Family\Domain\FamilyRepository;
use App\Family\Domain\ValueObject\FamilyCode;
use App\IdentityAccess\Application\LockingUserRepository;
use App\IdentityAccess\Application\Security\RepresentativePasswordPolicy;
use App\IdentityAccess\Domain\User;
use App\IdentityAccess\Domain\UserRepository;
use App\IdentityAccess\Domain\UserStatus;
use App\IdentityAccess\Domain\ValueObject\LoginIdentifier;
use App\IdentityAccess\Domain\ValueObject\PersonId as UserPersonId;
use App\Person\Application\LockingPersonRepository;
use App\Person\Domain\Person;
use App\Person\Domain\PersonRepository;
use App\Person\Domain\PersonStatus;
use App\Person\Domain\ValueObject\Identification;
use App\Person\Domain\ValueObject\PersonId as PersonDomainId;
use App\Representative\Application\LockingRepresentativeRepository;
use App\Representative\Domain\Representative;
use App\Representative\Domain\RepresentativeRepository;
use App\Representative\Domain\RepresentativeStatus;
use App\Representative\Domain\ValueObject\PersonId as RepresentativePersonId;
use App\Student\Application\LockingStudentRepository;
use App\Student\Domain\Student;
use App\Student\Domain\StudentRepository;
use App\Student\Domain\StudentStatus;
use App\Student\Domain\ValueObject\InstitutionalCode;
use App\Student\Domain\ValueObject\PersonId as StudentPersonId;
use Throwable;

final readonly class BulkImportMatcher
{
    public function __construct(
        private BulkImportCatalogResolver $catalogs,
        private PersonRepository $persons,
        private RepresentativeRepository $representatives,
        private UserRepository $users,
        private StudentRepository $students,
        private FamilyRepository $families,
        private RepresentativePasswordPolicy $passwordPolicy,
    ) {
    }

    public function match(BulkImportWorkbook $workbook, bool $forUpdate = false): BulkImportPlan
    {
        $familyRows = $workbook->families;
        usort(
            $familyRows,
            static fn (FamilyWorkbookRow $a, FamilyWorkbookRow $b): int =>
                strcmp($a->familyCode->value(), $b->familyCode->value()),
        );

        $issues = $this->crossFamilyIssues($workbook);
        $issuesByFamily = [];
        foreach ($issues as $issue) {
            if ($issue->location->sheet === 'Students') {
                foreach ($workbook->students as $row) {
                    if ($row->sourceRow === $issue->location->row) {
                        $issuesByFamily[$row->familyCode->value()][] = $issue;
                    }
                }
            } elseif ($issue->location->sheet === 'Representatives') {
                foreach ($workbook->representatives as $row) {
                    if ($row->sourceRow === $issue->location->row) {
                        $issuesByFamily[$row->familyCode->value()][] = $issue;
                    }
                }
            }
        }

        $plans = [];
        foreach ($familyRows as $familyRow) {
            $plan = $this->matchFamily($workbook, $familyRow, $forUpdate);
            $familyIssues = $issuesByFamily[$familyRow->familyCode->value()] ?? [];
            $plans[] = $familyIssues === [] ? $plan : new FamilyImportPlan(
                $plan->row,
                BulkImportClassification::Conflict,
                $plan->familyId,
                $plan->createFamily,
                $plan->representatives,
                $plan->students,
                [...$plan->issues, ...$familyIssues],
            );
        }

        return new BulkImportPlan($plans, $issues);
    }

    public function matchFamily(
        BulkImportWorkbook $workbook,
        FamilyWorkbookRow $familyRow,
        bool $forUpdate,
    ): FamilyImportPlan {
        $familyCode = $familyRow->familyCode->value();
        $representativeRows = array_values(array_filter(
            $workbook->representatives,
            static fn (RepresentativeWorkbookRow $row): bool =>
                $row->familyCode->value() === $familyCode,
        ));
        $studentRows = array_values(array_filter(
            $workbook->students,
            static fn (StudentWorkbookRow $row): bool =>
                $row->familyCode->value() === $familyCode,
        ));
        usort(
            $representativeRows,
            static fn (RepresentativeWorkbookRow $a, RepresentativeWorkbookRow $b): int =>
                [$a->documentTypeCode, trim($a->documentNumber), $a->sourceRow]
                <=> [$b->documentTypeCode, trim($b->documentNumber), $b->sourceRow],
        );
        usort(
            $studentRows,
            static fn (StudentWorkbookRow $a, StudentWorkbookRow $b): int =>
                [$a->institutionalCode->value(), $a->sourceRow]
                <=> [$b->institutionalCode->value(), $b->sourceRow],
        );

        $issues = $this->requiredStatusIssues($familyRow, $forUpdate);
        if ($forUpdate) {
            foreach ($representativeRows as $row) {
                $this->catalogs->findActiveCatalogId(
                    BulkImportCatalogKind::DocumentType,
                    $row->documentTypeCode,
                    true,
                );
                $this->catalogs->findActiveCatalogId(BulkImportCatalogKind::Sex, $row->sexCode, true);
                $this->catalogs->findActiveCatalogId(
                    BulkImportCatalogKind::RelationshipType,
                    $row->relationshipTypeCode,
                    true,
                );
            }
            foreach ($studentRows as $row) {
                $this->catalogs->findActiveCatalogId(BulkImportCatalogKind::Sex, $row->sexCode, true);
                if ($row->documentTypeCode !== null) {
                    $this->catalogs->findActiveCatalogId(
                        BulkImportCatalogKind::DocumentType,
                        $row->documentTypeCode,
                        true,
                    );
                }
            }
        }
        $family = $forUpdate
            ? $this->families->findByCodeForUpdate($familyRow->familyCode)
            : $this->families->findByCode($familyRow->familyCode);
        $createFamily = $family === null;
        if ($family !== null) {
            if (!$family->isActive()) {
                $issues[] = $this->issue(
                    BulkImportIssueCategory::ExistingDataConflict,
                    'Families',
                    $familyRow->sourceRow,
                    'family_code',
                    'La familia existente está inactiva y no puede reutilizarse.',
                );
            } elseif ($family->displayName()->value() !== $familyRow->displayName) {
                $issues[] = $this->issue(
                    BulkImportIssueCategory::ExistingDataConflict,
                    'Families',
                    $familyRow->sourceRow,
                    'display_name',
                    'El nombre visible no coincide exactamente con la familia existente.',
                );
            }
        }

        $representativePlans = [];
        foreach ($representativeRows as $row) {
            $representativePlans[] = $this->matchRepresentative($row, $family, $forUpdate);
        }
        $studentPlans = [];
        foreach ($studentRows as $row) {
            $studentPlans[] = $this->matchStudent($row, $family, $forUpdate);
        }

        $primaryCount = count(array_filter(
            $representativeRows,
            static fn (RepresentativeWorkbookRow $row): bool => $row->isPrimary,
        ));
        if ($createFamily && $primaryCount !== 1) {
            $issues[] = $this->issue(
                BulkImportIssueCategory::PrimaryRepresentativeConflict,
                'Representatives',
                $familyRow->sourceRow,
                'is_primary',
                'Una familia nueva requiere exactamente un representante principal.',
            );
        }
        foreach ($representativePlans as $plan) {
            array_push($issues, ...$plan->issues);
        }
        foreach ($studentPlans as $plan) {
            array_push($issues, ...$plan->issues);
        }

        $classification = $issues !== []
            ? BulkImportClassification::Conflict
            : ($createFamily
                || $this->containsNew($representativePlans)
                || $this->containsNew($studentPlans)
                    ? BulkImportClassification::New
                    : BulkImportClassification::AlreadyExists);

        return new FamilyImportPlan(
            $familyRow,
            $classification,
            $family?->id()?->value(),
            $createFamily,
            $representativePlans,
            $studentPlans,
            $issues,
        );
    }

    private function matchRepresentative(
        RepresentativeWorkbookRow $row,
        ?Family $family,
        bool $forUpdate,
    ): RepresentativeImportPlan {
        $issues = [];
        $documentTypeId = $this->catalogs->findActiveCatalogId(
            BulkImportCatalogKind::DocumentType,
            $row->documentTypeCode,
            $forUpdate,
        );
        $sexId = $this->catalogs->findActiveCatalogId(
            BulkImportCatalogKind::Sex,
            $row->sexCode,
            $forUpdate,
        );
        $relationshipTypeId = $this->catalogs->findActiveCatalogId(
            BulkImportCatalogKind::RelationshipType,
            $row->relationshipTypeCode,
            $forUpdate,
        );
        foreach ([
            [$documentTypeId, 'document_type_code'],
            [$sexId, 'sex_code'],
            [$relationshipTypeId, 'relationship_type_code'],
        ] as [$id, $field]) {
            if ($id === null) {
                $issues[] = $this->issue(
                    BulkImportIssueCategory::CatalogUnknown,
                    'Representatives',
                    $row->sourceRow,
                    $field,
                    'El código de catálogo no existe o está inactivo.',
                );
            }
        }
        if ($documentTypeId === null || $sexId === null || $relationshipTypeId === null) {
            return new RepresentativeImportPlan(
                $row,
                $documentTypeId ?? 0,
                $sexId ?? 0,
                $relationshipTypeId ?? 0,
                BulkImportClassification::Conflict,
                null,
                null,
                null,
                false,
                false,
                false,
                false,
                $issues,
            );
        }

        $identification = new Identification($documentTypeId, $row->documentNumber);
        $person = $this->findPerson($identification, $forUpdate);
        $createPerson = $person === null;
        if ($person !== null) {
            if ($person->status() !== PersonStatus::Active) {
                $issues[] = $this->issue(
                    BulkImportIssueCategory::ExistingDataConflict,
                    'Representatives',
                    $row->sourceRow,
                    'document_number',
                    'La persona identificada está inactiva.',
                );
            }
            if ($person->sexId() !== $sexId) {
                $issues[] = $this->issue(
                    BulkImportIssueCategory::IdentityConflict,
                    'Representatives',
                    $row->sourceRow,
                    'sex_code',
                    'El sexo no coincide con la identidad existente.',
                );
            }
        }

        $representative = $person === null ? null : $this->findRepresentative($person, $forUpdate);
        $createRepresentative = $representative === null;
        if ($createRepresentative && $person !== null
            && $person->contactInformation()?->email() === null
        ) {
            $issues[] = $this->issue(
                BulkImportIssueCategory::ExistingDataConflict,
                'Representatives',
                $row->sourceRow,
                'document_number',
                'La persona existente no tiene el correo personal requerido para el rol.',
            );
        }
        if ($representative !== null && $representative->status() !== RepresentativeStatus::Active) {
            $issues[] = $this->issue(
                BulkImportIssueCategory::ExistingDataConflict,
                'Representatives',
                $row->sourceRow,
                'document_number',
                'El representante existente está inactivo.',
            );
        }

        $user = $person === null ? null : $this->findUser($person, $forUpdate);
        $createUser = $user === null;
        if ($user !== null) {
            $expectedLogin = new LoginIdentifier($identification->documentNumber());
            $owner = $forUpdate
                ? $this->users->findByLoginIdentifierForUpdate($expectedLogin)
                : $this->users->findByLoginIdentifier($expectedLogin);
            if ($user->status() !== UserStatus::Active) {
                $issues[] = $this->issue(
                    BulkImportIssueCategory::ExistingDataConflict,
                    'Representatives',
                    $row->sourceRow,
                    'document_number',
                    'El acceso existente no está habilitado.',
                );
            }
            if ($user->loginIdentifier()->value() !== $expectedLogin->value()
                || $owner?->personId()->value() !== $person?->id()?->value()
            ) {
                $issues[] = $this->issue(
                    BulkImportIssueCategory::IdentityConflict,
                    'Representatives',
                    $row->sourceRow,
                    'document_number',
                    'El acceso existente no coincide con la identidad del representante.',
                );
            }
        } else {
            $owner = $forUpdate
                ? $this->users->findByLoginIdentifierForUpdate(
                    new LoginIdentifier($identification->documentNumber()),
                )
                : $this->users->findByLoginIdentifier(
                    new LoginIdentifier($identification->documentNumber()),
                );
            if ($owner !== null) {
                $issues[] = $this->issue(
                    BulkImportIssueCategory::IdentityConflict,
                    'Representatives',
                    $row->sourceRow,
                    'document_number',
                    'El identificador de acceso pertenece a otra persona.',
                );
            }
            if ($row->initialPassword === null || $row->initialPassword->reveal() === '') {
                $issues[] = $this->issue(
                    BulkImportIssueCategory::RequiredValueMissing,
                    'Representatives',
                    $row->sourceRow,
                    'initial_password',
                    'La contraseña inicial es obligatoria para crear el acceso.',
                );
            } else {
                try {
                    $this->passwordPolicy->assertValid($row->initialPassword->reveal());
                } catch (Throwable) {
                    $issues[] = $this->issue(
                        BulkImportIssueCategory::ValueInvalid,
                        'Representatives',
                        $row->sourceRow,
                        'initial_password',
                        'La contraseña inicial no cumple la política vigente.',
                    );
                }
            }
        }

        $createMembership = false;
        if ($family !== null && $representative !== null) {
            $membership = $this->activeRepresentativeMembership(
                $family,
                $representative->id()?->value(),
            );
            if ($membership === null) {
                if ($row->isPrimary) {
                    $issues[] = $this->issue(
                        BulkImportIssueCategory::PrimaryRepresentativeConflict,
                        'Representatives',
                        $row->sourceRow,
                        'is_primary',
                        'No se puede reemplazar el representante principal existente.',
                    );
                } else {
                    $createMembership = true;
                }
            } elseif ($membership->relationshipTypeId()->value() !== $relationshipTypeId
                || $membership->isPrimary() !== $row->isPrimary
                || $membership->startedAt()->getTimestamp() !== $row->startedAt->getTimestamp()
            ) {
                $issues[] = $this->issue(
                    BulkImportIssueCategory::FamilyMembershipConflict,
                    'Representatives',
                    $row->sourceRow,
                    null,
                    'La membresía existente no coincide exactamente con el workbook.',
                );
            }
        } elseif ($family !== null) {
            $createMembership = !$row->isPrimary;
            if ($row->isPrimary) {
                $issues[] = $this->issue(
                    BulkImportIssueCategory::PrimaryRepresentativeConflict,
                    'Representatives',
                    $row->sourceRow,
                    'is_primary',
                    'Una familia existente conserva su representante principal actual.',
                );
            }
        } elseif (!$row->isPrimary) {
            $createMembership = true;
        }

        $classification = $issues !== []
            ? BulkImportClassification::Conflict
            : ($createPerson || $createRepresentative || $createUser || $createMembership
                ? BulkImportClassification::New
                : BulkImportClassification::AlreadyExists);

        return new RepresentativeImportPlan(
            $row,
            $documentTypeId,
            $sexId,
            $relationshipTypeId,
            $classification,
            $person?->id()?->value(),
            $representative?->id()?->value(),
            $user?->id()?->value(),
            $createPerson,
            $createRepresentative,
            $createUser,
            $createMembership,
            $issues,
        );
    }

    private function matchStudent(
        StudentWorkbookRow $row,
        ?Family $family,
        bool $forUpdate,
    ): StudentImportPlan {
        $issues = [];
        $sexId = $this->catalogs->findActiveCatalogId(
            BulkImportCatalogKind::Sex,
            $row->sexCode,
            $forUpdate,
        );
        $documentTypeId = $row->documentTypeCode === null ? null
            : $this->catalogs->findActiveCatalogId(
                BulkImportCatalogKind::DocumentType,
                $row->documentTypeCode,
                $forUpdate,
            );
        if ($sexId === null || ($row->documentTypeCode !== null && $documentTypeId === null)) {
            $issues[] = $this->issue(
                BulkImportIssueCategory::CatalogUnknown,
                'Students',
                $row->sourceRow,
                $sexId === null ? 'sex_code' : 'document_type_code',
                'El código de catálogo no existe o está inactivo.',
            );
            return new StudentImportPlan(
                $row,
                $sexId ?? 0,
                $documentTypeId,
                BulkImportClassification::Conflict,
                null,
                null,
                false,
                false,
                false,
                $issues,
            );
        }

        $student = $this->findStudentByCode($row->institutionalCode, $forUpdate);
        $person = null;
        if ($student !== null) {
            $personId = new PersonDomainId($student->personId()->value());
            $person = $forUpdate
                ? $this->persons->findByIdForUpdate($personId)
                : $this->persons->findById($personId);
            if ($student->status() !== StudentStatus::Active || $person?->status() !== PersonStatus::Active) {
                $issues[] = $this->issue(
                    BulkImportIssueCategory::ExistingDataConflict,
                    'Students',
                    $row->sourceRow,
                    'institutional_code',
                    'El estudiante o su persona están inactivos.',
                );
            }
            if ($person !== null && $person->sexId() !== $sexId) {
                $issues[] = $this->issue(
                    BulkImportIssueCategory::IdentityConflict,
                    'Students',
                    $row->sourceRow,
                    'sex_code',
                    'El sexo no coincide con la identidad existente.',
                );
            }
            if ($documentTypeId !== null && $row->documentNumber !== null) {
                $expected = new Identification($documentTypeId, $row->documentNumber);
                if ($person?->identification()?->equals($expected) !== true) {
                    $issues[] = $this->issue(
                        BulkImportIssueCategory::IdentityConflict,
                        'Students',
                        $row->sourceRow,
                        'document_number',
                        'La identificación no coincide con el estudiante existente.',
                    );
                }
            }
        } elseif ($documentTypeId !== null && $row->documentNumber !== null) {
            $person = $this->findPerson(
                new Identification($documentTypeId, $row->documentNumber),
                $forUpdate,
            );
            if ($person !== null && ($person->status() !== PersonStatus::Active || $person->sexId() !== $sexId)) {
                $issues[] = $this->issue(
                    BulkImportIssueCategory::IdentityConflict,
                    'Students',
                    $row->sourceRow,
                    'document_number',
                    'La identidad existente no es compatible con el estudiante.',
                );
            }
            if ($person !== null) {
                $otherStudent = $this->findStudentByPerson($person, $forUpdate);
                if ($otherStudent !== null) {
                    $issues[] = $this->issue(
                        BulkImportIssueCategory::IdentityConflict,
                        'Students',
                        $row->sourceRow,
                        'institutional_code',
                        'La persona ya pertenece a otro estudiante.',
                    );
                }
            }
        }

        $createPerson = $student === null && $person === null;
        $createStudent = $student === null;
        $createMembership = false;
        if ($student !== null) {
            $studentReference = new \App\Family\Domain\ValueObject\StudentId(
                $student->id()?->value() ?? 0,
            );
            $activeFamily = $forUpdate
                ? $this->families->findActiveByStudentIdForUpdate($studentReference)
                : $this->families->findActiveByStudentId($studentReference);
            if ($activeFamily !== null
                && $activeFamily->familyCode()->value() !== $row->familyCode->value()
            ) {
                $issues[] = $this->issue(
                    BulkImportIssueCategory::FamilyMembershipConflict,
                    'Students',
                    $row->sourceRow,
                    'family_code',
                    'El estudiante ya pertenece a otra familia activa.',
                );
            } elseif ($activeFamily === null) {
                $createMembership = true;
            } else {
                $membership = $this->activeStudentMembership(
                    $activeFamily,
                    $student->id()?->value(),
                );
                if ($membership === null
                    || $membership->startedAt()->getTimestamp() !== $row->startedAt->getTimestamp()
                ) {
                    $issues[] = $this->issue(
                        BulkImportIssueCategory::FamilyMembershipConflict,
                        'Students',
                        $row->sourceRow,
                        'started_at',
                        'La membresía existente no coincide exactamente con el workbook.',
                    );
                }
            }
        } else {
            $createMembership = true;
        }

        $classification = $issues !== []
            ? BulkImportClassification::Conflict
            : ($createPerson || $createStudent || $createMembership
                ? BulkImportClassification::New
                : BulkImportClassification::AlreadyExists);

        return new StudentImportPlan(
            $row,
            $sexId,
            $documentTypeId,
            $classification,
            $person?->id()?->value(),
            $student?->id()?->value(),
            $createPerson,
            $createStudent,
            $createMembership,
            $issues,
        );
    }

    /** @return list<ValidationIssue> */
    private function requiredStatusIssues(FamilyWorkbookRow $row, bool $forUpdate): array
    {
        $issues = [];
        foreach ([['GENERAL_STATUS', 'ACTIVE'], ['USER_STATUS', 'ACTIVE']] as [$type, $code]) {
            if ($this->catalogs->findActiveStatusId($type, $code, $forUpdate) === null) {
                $issues[] = $this->issue(
                    BulkImportIssueCategory::CatalogUnknown,
                    'Families',
                    $row->sourceRow,
                    null,
                    'El estado activo requerido no existe o está inactivo.',
                );
            }
        }

        return $issues;
    }

    private function findPerson(Identification $identification, bool $forUpdate): ?Person
    {
        if (!$forUpdate) {
            return $this->persons->findByIdentification($identification);
        }
        if (!$this->persons instanceof LockingPersonRepository) {
            throw new \RuntimeException('Person repository does not support identity locking.');
        }

        return $this->persons->findByIdentificationForUpdate($identification);
    }

    private function findRepresentative(Person $person, bool $forUpdate): ?Representative
    {
        $personId = new RepresentativePersonId($person->id()?->value() ?? 0);
        if (!$forUpdate) {
            return $this->representatives->findByPersonId($personId);
        }
        if (!$this->representatives instanceof LockingRepresentativeRepository) {
            throw new \RuntimeException('Representative repository does not support Person locking.');
        }

        return $this->representatives->findByPersonIdForUpdate($personId);
    }

    private function findUser(Person $person, bool $forUpdate): ?User
    {
        $personId = new UserPersonId($person->id()?->value() ?? 0);
        if (!$forUpdate) {
            return $this->users->findByPersonId($personId);
        }
        if (!$this->users instanceof LockingUserRepository) {
            throw new \RuntimeException('User repository does not support Person locking.');
        }

        return $this->users->findByPersonIdForUpdate($personId);
    }

    private function findStudentByCode(InstitutionalCode $code, bool $forUpdate): ?Student
    {
        if (!$forUpdate) {
            return $this->students->findByInstitutionalCode($code);
        }
        if (!$this->students instanceof LockingStudentRepository) {
            throw new \RuntimeException('Student repository does not support institutional-code locking.');
        }

        return $this->students->findByInstitutionalCodeForUpdate($code);
    }

    private function findStudentByPerson(Person $person, bool $forUpdate): ?Student
    {
        $personId = new StudentPersonId($person->id()?->value() ?? 0);
        if (!$forUpdate) {
            return $this->students->findByPersonId($personId);
        }
        if (!$this->students instanceof LockingStudentRepository) {
            throw new \RuntimeException('Student repository does not support Person locking.');
        }

        return $this->students->findByPersonIdForUpdate($personId);
    }

    private function activeRepresentativeMembership(Family $family, ?int $representativeId): ?object
    {
        if ($representativeId === null) {
            return null;
        }
        foreach ($family->activeRepresentatives() as $membership) {
            if ($membership->representativeId()->value() === $representativeId) {
                return $membership;
            }
        }

        return null;
    }

    private function activeStudentMembership(Family $family, ?int $studentId): ?object
    {
        if ($studentId === null) {
            return null;
        }
        foreach ($family->activeStudents() as $membership) {
            if ($membership->studentId()->value() === $studentId) {
                return $membership;
            }
        }

        return null;
    }

    /** @param list<RepresentativeImportPlan>|list<StudentImportPlan> $plans */
    private function containsNew(array $plans): bool
    {
        foreach ($plans as $plan) {
            if ($plan->classification === BulkImportClassification::New) {
                return true;
            }
        }

        return false;
    }

    /** @return list<ValidationIssue> */
    private function crossFamilyIssues(BulkImportWorkbook $workbook): array
    {
        $issues = [];
        $students = [];
        foreach ($workbook->students as $row) {
            $code = $row->institutionalCode->value();
            $students[$code][] = $row;
        }
        foreach ($students as $rows) {
            $familyCodes = array_values(array_unique(array_map(
                static fn (StudentWorkbookRow $row): string => $row->familyCode->value(),
                $rows,
            )));
            if (count($familyCodes) <= 1) {
                continue;
            }
            foreach ($rows as $row) {
                $issues[] = $this->issue(
                    BulkImportIssueCategory::FamilyMembershipConflict,
                    'Students',
                    $row->sourceRow,
                    'institutional_code',
                    'El estudiante no puede pertenecer a dos familias en el mismo workbook.',
                );
            }
        }

        $representatives = [];
        foreach ($workbook->representatives as $row) {
            $key = $row->documentTypeCode . ':' . mb_strtoupper(trim($row->documentNumber), 'UTF-8');
            $representatives[$key][] = $row;
        }
        foreach ($representatives as $rows) {
            if (count($rows) <= 1) {
                continue;
            }
            $first = $rows[0];
            $incompatible = false;
            foreach (array_slice($rows, 1) as $row) {
                if ($row->firstName !== $first->firstName
                    || $row->middleName !== $first->middleName
                    || $row->firstSurname !== $first->firstSurname
                    || $row->secondSurname !== $first->secondSurname
                    || $row->birthDate->format('Y-m-d') !== $first->birthDate->format('Y-m-d')
                    || $row->sexCode !== $first->sexCode
                    || $row->email !== $first->email
                    || (($row->initialPassword === null) !== ($first->initialPassword === null))
                    || ($row->initialPassword !== null
                        && $first->initialPassword !== null
                        && !$row->initialPassword->equals($first->initialPassword))
                ) {
                    $incompatible = true;
                    break;
                }
            }
            if (!$incompatible) {
                continue;
            }
            foreach ($rows as $row) {
                $issues[] = $this->issue(
                    BulkImportIssueCategory::IdentityConflict,
                    'Representatives',
                    $row->sourceRow,
                    'document_number',
                    'La misma identidad de representante tiene datos de creación incompatibles.',
                );
            }
        }

        return $issues;
    }

    private function issue(
        BulkImportIssueCategory $category,
        string $sheet,
        int $row,
        ?string $field,
        string $message,
    ): ValidationIssue {
        return new ValidationIssue(
            $category,
            new WorkbookSourceLocation($sheet, $row, $field),
            $message,
        );
    }
}
