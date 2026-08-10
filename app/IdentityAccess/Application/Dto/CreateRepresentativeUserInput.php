<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Dto;

use App\IdentityAccess\Domain\UserStatus;

final readonly class CreateRepresentativeUserInput
{
    public function __construct(
        public int $representativeId,
        public string $plainTextPassword,
        public UserStatus $status,
    ) {
    }
}
