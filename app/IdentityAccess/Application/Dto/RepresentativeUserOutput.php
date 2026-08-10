<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\Dto;

use App\IdentityAccess\Application\Exception\InvalidPersistedUserResult;
use App\IdentityAccess\Domain\User;
use App\IdentityAccess\Domain\UserStatus;
use App\IdentityAccess\Domain\ValueObject\PersonId;
use App\IdentityAccess\Domain\ValueObject\UserId;

final readonly class RepresentativeUserOutput
{
    public function __construct(
        public int $userId,
        public int $personId,
        public string $loginIdentifier,
        public UserStatus $status,
    ) {
    }

    public static function fromUser(
        User $user,
        ?UserId $expectedUserId = null,
        ?PersonId $expectedPersonId = null,
    ): self {
        $userId = $user->id();
        if ($userId === null
            || ($expectedUserId !== null && $userId->value() !== $expectedUserId->value())
            || ($expectedPersonId !== null
                && $user->personId()->value() !== $expectedPersonId->value())
        ) {
            throw new InvalidPersistedUserResult(
                'User repository returned an invalid persisted identity.'
            );
        }

        return new self(
            $userId->value(),
            $user->personId()->value(),
            $user->loginIdentifier()->value(),
            $user->status(),
        );
    }
}
