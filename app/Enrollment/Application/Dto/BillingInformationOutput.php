<?php

declare(strict_types=1);

namespace App\Enrollment\Application\Dto;

final readonly class BillingInformationOutput
{
    public function __construct(
        public int $identificationTypeId,
        public string $identificationNumber,
        public string $legalName,
        public string $billingAddress,
        public string $billingEmail,
        public string $phone,
    ) {
    }
}
