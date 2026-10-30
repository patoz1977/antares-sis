<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Dto;

use App\IdentityAccess\Application\Exception\InstitutionalReportingAccessConflict;
use App\IdentityAccess\Domain\User;
use App\IdentityAccess\Domain\UserStatus;
use App\IdentityAccess\Domain\ValueObject\PersonId;
use App\IdentityAccess\Domain\ValueObject\UserId;

final readonly class InstitutionalReportingUserOutput
{
    public function __construct(
        public int $userId,
        public int $personId,
        public string $loginIdentifier,
        public UserStatus $status,
    ) {
    }

    public static function fromUser(User $user, UserId $expectedUserId, PersonId $expectedPersonId): self
    {
        $userId = $user->id();
        if ($userId === null
            || $userId->value() !== $expectedUserId->value()
            || $user->personId()->value() !== $expectedPersonId->value()
        ) {
            throw new InstitutionalReportingAccessConflict('Persisted reporting User is incoherent.');
        }

        return new self(
            $userId->value(),
            $user->personId()->value(),
            $user->loginIdentifier()->value(),
            $user->status(),
        );
    }
}
