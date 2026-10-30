<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application;

use App\IdentityAccess\Domain\UserRepository;
use App\IdentityAccess\Domain\ValueObject\UserId;

final readonly class CanAccessAllReports
{
    public const CAPABILITY = 'REPORTS_VIEW_ALL';

    public function __construct(
        private UserRepository $users,
        private UserCapabilityLookup $capabilities,
    ) {
    }

    public function handle(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $id = new UserId($userId);
        $user = $this->users->findById($id);

        if ($user === null || $user->id() === null || $user->id()->value() !== $userId || $user->isDisabled()) {
            return false;
        }

        return $this->capabilities->hasCapability($id, self::CAPABILITY);
    }
}
