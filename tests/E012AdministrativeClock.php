<?php

declare(strict_types=1);

namespace Tests;

use App\IdentityAccess\Application\Contract\Clock;
use DateTimeImmutable;

final class E012AdministrativeClock implements Clock
{
    public int $calls = 0;

    public function __construct(private readonly string $instant)
    {
    }

    public function now(): DateTimeImmutable
    {
        $this->calls++;

        return new DateTimeImmutable($this->instant);
    }
}
