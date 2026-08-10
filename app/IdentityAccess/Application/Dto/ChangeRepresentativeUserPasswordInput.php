<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Dto;

final readonly class ChangeRepresentativeUserPasswordInput
{
    public function __construct(
        public int $representativeId,
        public string $newPlainTextPassword,
    ) {
    }
}
