<?php

declare(strict_types=1);

namespace App\Enrollment\Application\Dto;

final readonly class TransportInformationOutput
{
    public function __construct(public bool $requiresInstitutionalTransport)
    {
    }
}
