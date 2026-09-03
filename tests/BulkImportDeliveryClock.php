<?php

declare(strict_types=1);

namespace Tests;

use App\IdentityAccess\Application\Contract\Clock;
use DateTimeImmutable;

final class BulkImportDeliveryClock implements Clock
{
    public function __construct(public DateTimeImmutable $instant)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->instant;
    }
}
