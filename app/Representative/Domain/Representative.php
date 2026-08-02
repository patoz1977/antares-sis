<?php

declare(strict_types=1);

namespace App\Representative\Domain;

use App\Representative\Domain\ValueObject\EmploymentInformation;
use App\Representative\Domain\ValueObject\PersonId;
use App\Representative\Domain\ValueObject\RepresentativeId;

final class Representative
{
    private ?EmploymentInformation $employmentInformation;

    public function __construct(
        private readonly ?RepresentativeId $id,
        private readonly PersonId $personId,
        ?EmploymentInformation $employmentInformation,
        private RepresentativeStatus $status,
    ) {
        $this->employmentInformation = self::presentEmploymentInformation($employmentInformation);
    }

    public function id(): ?RepresentativeId
    {
        return $this->id;
    }

    public function personId(): PersonId
    {
        return $this->personId;
    }

    public function employmentInformation(): ?EmploymentInformation
    {
        return $this->employmentInformation;
    }

    public function status(): RepresentativeStatus
    {
        return $this->status;
    }

    public function replaceEmploymentInformation(?EmploymentInformation $employmentInformation): void
    {
        $this->employmentInformation = self::presentEmploymentInformation($employmentInformation);
    }

    public function activate(): void
    {
        $this->status = RepresentativeStatus::Active;
    }

    public function deactivate(): void
    {
        $this->status = RepresentativeStatus::Inactive;
    }

    public function isActive(): bool
    {
        return $this->status === RepresentativeStatus::Active;
    }

    private static function presentEmploymentInformation(
        ?EmploymentInformation $employmentInformation,
    ): ?EmploymentInformation {
        if ($employmentInformation?->isEmpty() === true) {
            return null;
        }

        return $employmentInformation;
    }
}
