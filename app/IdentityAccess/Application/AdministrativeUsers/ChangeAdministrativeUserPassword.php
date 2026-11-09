<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application\AdministrativeUsers;

use App\IdentityAccess\Application\AdministrativeUsers\Exception\AdministrativeUserOperationNotAllowed;
use App\IdentityAccess\Application\ChangeRepresentativeUserPassword;
use App\IdentityAccess\Application\Dto\ChangeRepresentativeUserPasswordInput;
use App\IdentityAccess\Application\Exception\RepresentativeUserNotFound;
use App\Representative\Application\Exception\RepresentativeNotFound;

final readonly class ChangeAdministrativeUserPassword
{
    public function __construct(
        private GetAdministrativeUser $getUser,
        private ChangeRepresentativeUserPassword $changeRepresentativePassword,
        private ChangeInstitutionalReportingUserPassword $changeReportingPassword,
    ) {
    }

    public function handle(int $userId, string $plainTextPassword): void
    {
        $user = $this->getUser->handle($userId);
        if ($user->accessType === AdministrativeUserAccessType::Representative
            && $user->representativeId !== null
        ) {
            try {
                $this->changeRepresentativePassword->handle(
                    new ChangeRepresentativeUserPasswordInput(
                        $user->representativeId,
                        $plainTextPassword,
                    )
                );
            } catch (RepresentativeNotFound|RepresentativeUserNotFound $exception) {
                throw new AdministrativeUserOperationNotAllowed(
                    'The requested Representative User is no longer administrable.',
                    previous: $exception,
                );
            }

            return;
        }
        if ($user->accessType === AdministrativeUserAccessType::InstitutionalReporting) {
            $this->changeReportingPassword->handle($user->personId, $plainTextPassword);

            return;
        }

        throw new AdministrativeUserOperationNotAllowed(
            'The requested User password change is not allowed.'
        );
    }
}
