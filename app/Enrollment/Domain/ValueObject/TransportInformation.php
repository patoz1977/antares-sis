<?php

declare(strict_types=1);

namespace App\Enrollment\Domain\ValueObject;

final readonly class TransportInformation
{
    public function __construct(private bool $requiresInstitutionalTransport)
    {
    }

    public function requiresInstitutionalTransport(): bool
    {
        return $this->requiresInstitutionalTransport;
    }
}
