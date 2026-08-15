<?php

declare(strict_types=1);

namespace App\Enrollment\Domain\ValueObject;

use App\Enrollment\Domain\Support\EnrollmentText;

final readonly class BillingInformation
{
    private string $identificationNumber;

    private string $legalName;

    private string $billingAddress;

    private string $billingEmail;

    private string $phone;

    public function __construct(
        private IdentificationTypeId $identificationTypeId,
        string $identificationNumber,
        string $legalName,
        string $billingAddress,
        string $billingEmail,
        string $phone,
    ) {
        $this->identificationNumber = EnrollmentText::required(
            $identificationNumber,
            50,
            'Billing identification number',
        );
        $this->legalName = EnrollmentText::required($legalName, 200, 'Billing legal name');
        $this->billingAddress = EnrollmentText::required($billingAddress, 255, 'Billing address');
        $this->billingEmail = EnrollmentText::email($billingEmail, 'Billing email', true) ?? '';
        $this->phone = EnrollmentText::required($phone, 30, 'Billing phone');
    }

    public function identificationTypeId(): IdentificationTypeId
    {
        return $this->identificationTypeId;
    }

    public function identificationNumber(): string
    {
        return $this->identificationNumber;
    }

    public function legalName(): string
    {
        return $this->legalName;
    }

    public function billingAddress(): string
    {
        return $this->billingAddress;
    }

    public function billingEmail(): string
    {
        return $this->billingEmail;
    }

    public function phone(): string
    {
        return $this->phone;
    }

    public function equals(self $other): bool
    {
        return $this->identificationTypeId->equals($other->identificationTypeId)
            && $this->identificationNumber === $other->identificationNumber
            && $this->legalName === $other->legalName
            && $this->billingAddress === $other->billingAddress
            && $this->billingEmail === $other->billingEmail
            && $this->phone === $other->phone;
    }
}
