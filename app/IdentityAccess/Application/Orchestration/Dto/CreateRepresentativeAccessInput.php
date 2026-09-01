<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Orchestration\Dto;

use App\IdentityAccess\Domain\UserStatus;
use App\Person\Application\Dto\CreatePersonInput;
use App\Representative\Domain\RepresentativeStatus;

final readonly class CreateRepresentativeAccessInput
{
    public function __construct(
        public CreatePersonInput $person,
        public ?string $occupation,
        public ?string $companyName,
        public ?string $position,
        public ?string $workPhone,
        public ?string $workEmail,
        public RepresentativeStatus $representativeStatus,
        public string $plainTextPassword,
        public UserStatus $userStatus,
    ) {
    }
}
