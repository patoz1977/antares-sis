<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application;

use App\IdentityAccess\Application\Contract\SecurityEventLogger;
use App\IdentityAccess\Application\Contract\SessionManager;

final readonly class LogoutUser
{
    public function __construct(
        private SessionManager $session,
        private SecurityEventLogger $securityEvents,
    ) {
    }

    public function handle(): void
    {
        $this->session->destroy();
        $this->securityEvents->record('authentication.logged_out');
    }
}
