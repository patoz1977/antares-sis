<?php

declare(strict_types=1);

namespace App\Student\Domain;

use App\Student\Domain\ValueObject\AdmissionDate;
use App\Student\Domain\ValueObject\InstitutionalCode;
use App\Student\Domain\ValueObject\PersonId;
use App\Student\Domain\ValueObject\StudentId;

final class Student
{
    public function __construct(
        private readonly ?StudentId $id,
        private readonly PersonId $personId,
        private InstitutionalCode $institutionalCode,
        private AdmissionDate $admissionDate,
        private StudentStatus $status,
    ) {
    }

    public function id(): ?StudentId
    {
        return $this->id;
    }

    public function personId(): PersonId
    {
        return $this->personId;
    }

    public function institutionalCode(): InstitutionalCode
    {
        return $this->institutionalCode;
    }

    public function admissionDate(): AdmissionDate
    {
        return $this->admissionDate;
    }

    public function status(): StudentStatus
    {
        return $this->status;
    }

    public function updateAcademicInformation(
        InstitutionalCode $institutionalCode,
        AdmissionDate $admissionDate,
    ): void {
        $this->institutionalCode = $institutionalCode;
        $this->admissionDate = $admissionDate;
    }

    public function activate(): void
    {
        $this->status = StudentStatus::Active;
    }

    public function deactivate(): void
    {
        $this->status = StudentStatus::Inactive;
    }

    public function isActive(): bool
    {
        return $this->status === StudentStatus::Active;
    }
}
