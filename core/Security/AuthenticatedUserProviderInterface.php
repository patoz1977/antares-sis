<?php

declare(strict_types=1);

namespace Core\Security;

interface AuthenticatedUserProviderInterface
{
    public function check(): bool;
}
