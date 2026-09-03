<?php

declare(strict_types=1);

namespace App\BulkImport\Application;

use App\BulkImport\Application\Contract\BulkImportWorkbookReader;
use App\BulkImport\Application\Dto\ApplyBulkImportResult;
use App\BulkImport\Application\Dto\ApplyFamilyResult;
use App\BulkImport\Application\Dto\BulkImportClassification;
use App\BulkImport\Application\Dto\BulkImportIssueCategory;
use App\BulkImport\Application\Dto\ValidationIssue;
use App\BulkImport\Application\Dto\WorkbookSourceLocation;
use App\BulkImport\Application\Planning\BulkImportMatcher;
use App\BulkImport\Application\Planning\FamilyImportPlan;
use App\BulkImport\Application\Planning\RepresentativeImportPlan;
use App\BulkImport\Application\Planning\StudentImportPlan;
use App\Family\Application\AddRepresentativeToFamily;
use App\Family\Application\CreateFamily;
use App\Family\Application\Dto\AddRepresentativeToFamilyInput;
use App\Family\Application\Dto\CreateFamilyWithCodeInput;
use App\Family\Application\Orchestration\Dto\AttachExistingStudentToFamilyInput;
use App\Family\Application\Orchestration\Dto\CreateStudentForExistingPersonInput;
use App\Family\Application\Orchestration\Dto\CreateStudentInFamilyInput;
use App\Family\Application\Orchestration\StudentFamilyCoordinator;
use App\Family\Domain\FamilyStatus;
use App\IdentityAccess\Application\CreateRepresentativeUser;
use App\IdentityAccess\Application\Dto\CreateRepresentativeUserInput;
use App\IdentityAccess\Application\Orchestration\CreateRepresentativeAccess;
use App\IdentityAccess\Application\Orchestration\Dto\CreateRepresentativeAccessForPersonInput;
use App\IdentityAccess\Application\Orchestration\Dto\CreateRepresentativeAccessInput;
use App\IdentityAccess\Domain\UserStatus;
use App\Person\Application\Dto\CreatePersonInput;
use App\Person\Domain\PersonStatus;
use App\Representative\Application\CreateRepresentative;
use App\Representative\Application\Dto\CreateRepresentativeInput;
use App\Representative\Domain\RepresentativeStatus;
use App\Student\Domain\StudentStatus;
use Core\Application\TransactionRunner;
use DateTimeImmutable;
use PDOException;
use Throwable;

final readonly class ApplyBulkImport
{
    public function __construct(
        private BulkImportWorkbookReader $reader,
        private BulkImportMatcher $matcher,
        private TransactionRunner $transactions,
        private CreateRepresentativeAccess $createRepresentativeAccess,
        private CreateRepresentative $createRepresentative,
        private CreateRepresentativeUser $createRepresentativeUser,
        private CreateFamily $createFamily,
        private AddRepresentativeToFamily $addRepresentativeToFamily,
        private StudentFamilyCoordinator $studentCoordinator,
    ) {
    }

    public function handle(string $localPath, DateTimeImmutable $today): ApplyBulkImportResult
    {
        $validation = $this->reader->read($localPath);
        $workbook = $validation->workbook();
        if (!$validation->isValid() || $workbook === null) {
            return new ApplyBulkImportResult([], $validation->issues());
        }

        // Full parsing, Phase 5 validation, catalogs and initial DB matching complete
        // before the first production write.
        $initial = $this->matcher->match($workbook);
        $results = [];
        foreach ($initial->families as $initialFamily) {
            if ($initialFamily->hasConflict()) {
                $results[] = new ApplyFamilyResult(
                    $initialFamily->row->familyCode->value(),
                    BulkImportClassification::Conflict,
                    'La familia no se aplicó porque contiene conflictos.',
                    $initialFamily->issues,
                );
                $this->clearPasswords($initialFamily);
                continue;
            }

            try {
                $results[] = $this->transactions->run(function () use (
                    $workbook,
                    $initialFamily,
                    $today,
                ): ApplyFamilyResult {
                    $locked = $this->matcher->matchFamily(
                        $workbook,
                        $initialFamily->row,
                        true,
                    );
                    if ($locked->hasConflict()) {
                        return new ApplyFamilyResult(
                            $initialFamily->row->familyCode->value(),
                            BulkImportClassification::Conflict,
                            'El estado cambió durante la aplicación; se requiere una vista previa nueva.',
                            [$this->issue(
                                $initialFamily,
                                BulkImportIssueCategory::ConcurrentChange,
                                'El estado cambió durante la aplicación; se requiere una vista previa nueva.',
                            )],
                        );
                    }

                    if ($locked->classification === BulkImportClassification::AlreadyExists) {
                        return new ApplyFamilyResult(
                            $locked->row->familyCode->value(),
                            BulkImportClassification::AlreadyExists,
                            'La familia ya existía de forma equivalente; no se realizaron cambios.',
                        );
                    }

                    $this->applyFamily($locked, $today);

                    return new ApplyFamilyResult(
                        $locked->row->familyCode->value(),
                        BulkImportClassification::New,
                        'La familia se aplicó completamente.',
                    );
                });
            } catch (Throwable $exception) {
                $category = $this->isConcurrentChange($exception)
                    ? BulkImportIssueCategory::ConcurrentChange
                    : BulkImportIssueCategory::ApplyFailed;
                $results[] = new ApplyFamilyResult(
                    $initialFamily->row->familyCode->value(),
                    BulkImportClassification::Conflict,
                    $category === BulkImportIssueCategory::ConcurrentChange
                        ? 'La familia cambió concurrentemente y no se aplicó.'
                        : 'La familia no pudo aplicarse y fue revertida.',
                    [$this->issue(
                        $initialFamily,
                        $category,
                        $category === BulkImportIssueCategory::ConcurrentChange
                            ? 'Se detectó un cambio concurrente; se requiere una vista previa nueva.'
                            : 'La familia no pudo aplicarse y fue revertida.',
                    )],
                );
            } finally {
                $this->clearPasswords($initialFamily);
            }
        }

        return new ApplyBulkImportResult($results, $initial->issues);
    }

    private function applyFamily(FamilyImportPlan $plan, DateTimeImmutable $today): void
    {
        $representativeIds = [];
        foreach ($plan->representatives as $representative) {
            $representativeIds[$representative->row->sourceRow] =
                $this->ensureRepresentative($representative, $today);
        }

        $familyId = $plan->familyId;
        if ($plan->createFamily) {
            $primary = null;
            foreach ($plan->representatives as $representative) {
                if ($representative->row->isPrimary) {
                    $primary = $representative;
                    break;
                }
            }
            if ($primary === null) {
                throw new \RuntimeException('Family import plan has no primary Representative.');
            }
            $family = $this->createFamily->handleWithCode(new CreateFamilyWithCodeInput(
                $plan->row->familyCode->value(),
                $plan->row->displayName,
                FamilyStatus::Active,
                $representativeIds[$primary->row->sourceRow],
                $primary->relationshipTypeId,
                $primary->row->startedAt,
            ));
            $familyId = $family->id;
        }
        if ($familyId === null) {
            throw new \RuntimeException('Family import plan has no persisted identity.');
        }

        foreach ($plan->representatives as $representative) {
            if (!$representative->createMembership || $representative->row->isPrimary) {
                continue;
            }
            $this->addRepresentativeToFamily->handle(new AddRepresentativeToFamilyInput(
                $familyId,
                $representativeIds[$representative->row->sourceRow],
                $representative->relationshipTypeId,
                $representative->row->startedAt,
            ));
        }
        foreach ($plan->students as $student) {
            $this->ensureStudent($student, $familyId, $today);
        }
    }

    private function ensureRepresentative(
        RepresentativeImportPlan $plan,
        DateTimeImmutable $today,
    ): int {
        $password = $plan->row->initialPassword?->reveal() ?? '';
        if ($plan->createPerson) {
            return $this->createRepresentativeAccess->handle(
                new CreateRepresentativeAccessInput(
                    new CreatePersonInput(
                        $plan->row->firstName,
                        $plan->row->middleName,
                        $plan->row->firstSurname,
                        $plan->row->secondSurname,
                        $plan->documentTypeId,
                        $plan->row->documentNumber,
                        $plan->row->birthDate,
                        $plan->sexId,
                        null,
                        null,
                        $plan->row->email,
                        null,
                        null,
                        PersonStatus::Active,
                    ),
                    null,
                    null,
                    null,
                    null,
                    null,
                    RepresentativeStatus::Active,
                    $password,
                    UserStatus::Active,
                ),
                $today,
            )->representative->id;
        }
        if ($plan->personId === null) {
            throw new \RuntimeException('Representative import plan has no Person identity.');
        }
        if ($plan->createRepresentative && $plan->createUser) {
            return $this->createRepresentativeAccess->handleForExistingPerson(
                new CreateRepresentativeAccessForPersonInput(
                    $plan->personId,
                    null,
                    null,
                    null,
                    null,
                    null,
                    RepresentativeStatus::Active,
                    $password,
                    UserStatus::Active,
                ),
            )->representative->id;
        }
        $representativeId = $plan->representativeId;
        if ($plan->createRepresentative) {
            $representativeId = $this->createRepresentative->handle(new CreateRepresentativeInput(
                $plan->personId,
                null,
                null,
                null,
                null,
                null,
                RepresentativeStatus::Active,
            ))->id;
        }
        if ($representativeId === null) {
            throw new \RuntimeException('Representative import plan has no Representative identity.');
        }
        if ($plan->createUser) {
            $this->createRepresentativeUser->handle(new CreateRepresentativeUserInput(
                $representativeId,
                $password,
                UserStatus::Active,
            ));
        }

        return $representativeId;
    }

    private function ensureStudent(
        StudentImportPlan $plan,
        int $familyId,
        DateTimeImmutable $today,
    ): void {
        if ($plan->createPerson) {
            $this->studentCoordinator->createWithNewPerson(
                new CreateStudentInFamilyInput(
                    $familyId,
                    $plan->row->firstName,
                    $plan->row->middleName,
                    $plan->row->firstSurname,
                    $plan->row->secondSurname,
                    $plan->documentTypeId,
                    $plan->row->documentNumber,
                    $plan->row->birthDate,
                    $plan->sexId,
                    null,
                    null,
                    null,
                    null,
                    null,
                    PersonStatus::Active,
                    $plan->row->institutionalCode->value(),
                    $plan->row->admissionDate,
                    StudentStatus::Active,
                    $plan->row->startedAt,
                ),
                $today,
            );
            return;
        }
        if ($plan->createStudent) {
            if ($plan->personId === null) {
                throw new \RuntimeException('Student import plan has no Person identity.');
            }
            $this->studentCoordinator->createForPerson(
                new CreateStudentForExistingPersonInput(
                    $familyId,
                    $plan->personId,
                    $plan->row->institutionalCode->value(),
                    $plan->row->admissionDate,
                    StudentStatus::Active,
                    $plan->row->startedAt,
                ),
                $today,
            );
            return;
        }
        if ($plan->createMembership) {
            if ($plan->studentId === null) {
                throw new \RuntimeException('Student import plan has no Student identity.');
            }
            $this->studentCoordinator->attach(new AttachExistingStudentToFamilyInput(
                $familyId,
                $plan->studentId,
                $plan->row->startedAt,
            ));
        }
    }

    private function clearPasswords(FamilyImportPlan $plan): void
    {
        foreach ($plan->representatives as $representative) {
            $representative->row->initialPassword?->clear();
        }
    }

    private function isConcurrentChange(Throwable $exception): bool
    {
        if (!$exception instanceof PDOException) {
            return false;
        }
        $sqlState = (string) $exception->getCode();
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        return in_array($sqlState, ['23000', '40001'], true)
            || in_array($driverCode, [1205, 1213], true);
    }

    private function issue(
        FamilyImportPlan $plan,
        BulkImportIssueCategory $category,
        string $message,
    ): ValidationIssue {
        return new ValidationIssue(
            $category,
            new WorkbookSourceLocation(
                'Families',
                $plan->row->sourceRow,
                'family_code',
            ),
            $message,
        );
    }
}
