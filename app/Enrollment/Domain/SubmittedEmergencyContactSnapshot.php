<?php

declare(strict_types=1);

namespace App\Enrollment\Domain;

use App\Enrollment\Domain\Exception\InvalidEnrollmentState;
use App\Enrollment\Domain\Support\EnrollmentText;
use App\Enrollment\Domain\ValueObject\SubmittedEmergencyContactSnapshotId;

final readonly class SubmittedEmergencyContactSnapshot
{
    private string $names;

    private string $relationshipTypeCode;

    private string $relationshipTypeName;

    private string $mobilePhone;

    private ?string $phone;

    private ?string $email;

    private ?string $observations;

    private function __construct(
        private ?SubmittedEmergencyContactSnapshotId $id,
        string $names,
        string $relationshipTypeCode,
        string $relationshipTypeName,
        string $mobilePhone,
        ?string $phone,
        ?string $email,
        ?string $observations,
        private ?int $priority,
        private int $sortOrder,
    ) {
        if ($priority !== null && $priority <= 0) {
            throw new InvalidEnrollmentState('Emergency contact priority must be positive when supplied.');
        }
        if ($sortOrder <= 0) {
            throw new InvalidEnrollmentState('Emergency contact sort order must be positive.');
        }

        $this->names = EnrollmentText::required($names, 200, 'Emergency contact names');
        $this->relationshipTypeCode = EnrollmentText::required(
            $relationshipTypeCode,
            100,
            'Relationship type code',
        );
        $this->relationshipTypeName = EnrollmentText::required(
            $relationshipTypeName,
            150,
            'Relationship type name',
        );
        $this->mobilePhone = EnrollmentText::required($mobilePhone, 30, 'Emergency mobile phone');
        $this->phone = EnrollmentText::optional($phone, 30, 'Emergency phone');
        $this->email = EnrollmentText::email($email, 'Emergency email', false);
        $this->observations = EnrollmentText::optional($observations, 500, 'Emergency observations');
    }

    public static function create(
        string $names,
        string $relationshipTypeCode,
        string $relationshipTypeName,
        string $mobilePhone,
        ?string $phone,
        ?string $email,
        ?string $observations,
        ?int $priority,
        int $sortOrder,
    ): self {
        return new self(
            null,
            $names,
            $relationshipTypeCode,
            $relationshipTypeName,
            $mobilePhone,
            $phone,
            $email,
            $observations,
            $priority,
            $sortOrder,
        );
    }

    public static function reconstitute(
        SubmittedEmergencyContactSnapshotId $id,
        string $names,
        string $relationshipTypeCode,
        string $relationshipTypeName,
        string $mobilePhone,
        ?string $phone,
        ?string $email,
        ?string $observations,
        ?int $priority,
        int $sortOrder,
    ): self {
        return new self(
            $id,
            $names,
            $relationshipTypeCode,
            $relationshipTypeName,
            $mobilePhone,
            $phone,
            $email,
            $observations,
            $priority,
            $sortOrder,
        );
    }

    public function id(): ?SubmittedEmergencyContactSnapshotId
    {
        return $this->id;
    }

    public function names(): string
    {
        return $this->names;
    }

    public function relationshipTypeCode(): string
    {
        return $this->relationshipTypeCode;
    }

    public function relationshipTypeName(): string
    {
        return $this->relationshipTypeName;
    }

    public function mobilePhone(): string
    {
        return $this->mobilePhone;
    }

    public function phone(): ?string
    {
        return $this->phone;
    }

    public function email(): ?string
    {
        return $this->email;
    }

    public function observations(): ?string
    {
        return $this->observations;
    }

    public function priority(): ?int
    {
        return $this->priority;
    }

    public function sortOrder(): int
    {
        return $this->sortOrder;
    }
}
