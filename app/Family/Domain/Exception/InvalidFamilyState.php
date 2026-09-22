<?php

declare(strict_types=1);

namespace App\Family\Domain\Exception;

use DomainException;

final class InvalidFamilyState extends DomainException
{
    public const ASSIGNED_ADDRESS = 1001;
    public const ASSIGNED_EMERGENCY_CONTACT = 1002;
    public const ASSIGNED_AUTHORIZED_PICKUP = 1003;
}
