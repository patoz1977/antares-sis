<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\AdministrativeUsers;

use App\IdentityAccess\Application\AdministrativeUsers\Exception\AdministrativeUserOperationNotAllowed;

final readonly class ChangeAdministrativeUserLoginIdentifier
{
    public function __construct(
        private GetAdministrativeUser $getUser,
        private ChangeInstitutionalReportingUserLoginIdentifier $changeReportingLogin,
    ) {
    }

    public function handle(int $userId, string $loginIdentifier): bool
    {
        $user = $this->getUser->handle($userId);
        if ($user->accessType !== AdministrativeUserAccessType::InstitutionalReporting) {
            throw new AdministrativeUserOperationNotAllowed(
                'The requested login identifier change is not allowed.'
            );
        }

        return $this->changeReportingLogin->handle($user->personId, $loginIdentifier);
    }
}
