<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\AdministrativeUsers;

use App\IdentityAccess\Application\AdministrativeUsers\Exception\AdministrativeUserOperationNotAllowed;
use App\IdentityAccess\Application\DisableInstitutionalReportingUser;
use App\IdentityAccess\Application\ReactivateInstitutionalReportingUser;

final readonly class ChangeAdministrativeUserStatus
{
    public function __construct(
        private GetAdministrativeUser $getUser,
        private ChangeRepresentativeUserStatus $changeRepresentativeStatus,
        private DisableInstitutionalReportingUser $disableReportingUser,
        private ReactivateInstitutionalReportingUser $reactivateReportingUser,
    ) {
    }

    public function handle(int $userId, bool $activate): bool
    {
        $user = $this->getUser->handle($userId);

        return match ($user->accessType) {
            AdministrativeUserAccessType::Representative =>
                $this->changeRepresentativeStatus->handle($userId, $activate),
            AdministrativeUserAccessType::InstitutionalReporting => $activate
                ? $this->reactivateReportingUser->handle($user->personId)
                : $this->disableReportingUser->handle($user->personId),
            default => throw new AdministrativeUserOperationNotAllowed(
                'The requested User status change is not allowed.'
            ),
        };
    }
}
