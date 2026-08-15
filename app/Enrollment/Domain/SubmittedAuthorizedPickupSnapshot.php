<?php

declare(strict_types=1);

namespace App\Enrollment\Domain;

use App\Enrollment\Domain\Support\EnrollmentText;
use App\Enrollment\Domain\ValueObject\SubmittedAuthorizedPickupSnapshotId;

final readonly class SubmittedAuthorizedPickupSnapshot
{
    private string $names;

    private string $relationshipTypeCode;

    private string $relationshipTypeName;

    private string $mobilePhone;

    private ?string $phone;

    private string $documentTypeCode;

    private string $documentTypeName;

    private string $documentNumber;

    private ?string $observations;

    private function __construct(
        private ?SubmittedAuthorizedPickupSnapshotId $id,
        string $names,
        string $relationshipTypeCode,
        string $relationshipTypeName,
        string $mobilePhone,
        ?string $phone,
        string $documentTypeCode,
        string $documentTypeName,
        string $documentNumber,
        ?string $observations,
    ) {
        $this->names = EnrollmentText::required($names, 200, 'Authorized pickup names');
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
        $this->mobilePhone = EnrollmentText::required($mobilePhone, 30, 'Authorized pickup mobile phone');
        $this->phone = EnrollmentText::optional($phone, 30, 'Authorized pickup phone');
        $this->documentTypeCode = EnrollmentText::required($documentTypeCode, 100, 'Document type code');
        $this->documentTypeName = EnrollmentText::required($documentTypeName, 150, 'Document type name');
        $this->documentNumber = EnrollmentText::required($documentNumber, 50, 'Document number');
        $this->observations = EnrollmentText::optional($observations, 500, 'Authorized pickup observations');
    }

    public static function create(
        string $names,
        string $relationshipTypeCode,
        string $relationshipTypeName,
        string $mobilePhone,
        ?string $phone,
        string $documentTypeCode,
        string $documentTypeName,
        string $documentNumber,
        ?string $observations,
    ): self {
        return new self(
            null,
            $names,
            $relationshipTypeCode,
            $relationshipTypeName,
            $mobilePhone,
            $phone,
            $documentTypeCode,
            $documentTypeName,
            $documentNumber,
            $observations,
        );
    }

    public static function reconstitute(
        SubmittedAuthorizedPickupSnapshotId $id,
        string $names,
        string $relationshipTypeCode,
        string $relationshipTypeName,
        string $mobilePhone,
        ?string $phone,
        string $documentTypeCode,
        string $documentTypeName,
        string $documentNumber,
        ?string $observations,
    ): self {
        return new self(
            $id,
            $names,
            $relationshipTypeCode,
            $relationshipTypeName,
            $mobilePhone,
            $phone,
            $documentTypeCode,
            $documentTypeName,
            $documentNumber,
            $observations,
        );
    }

    public function id(): ?SubmittedAuthorizedPickupSnapshotId
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

    public function documentTypeCode(): string
    {
        return $this->documentTypeCode;
    }

    public function documentTypeName(): string
    {
        return $this->documentTypeName;
    }

    public function documentNumber(): string
    {
        return $this->documentNumber;
    }

    public function observations(): ?string
    {
        return $this->observations;
    }
}
