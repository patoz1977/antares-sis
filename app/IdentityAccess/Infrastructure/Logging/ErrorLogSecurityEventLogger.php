<?php

declare(strict_types=1);

namespace App\IdentityAccess\Infrastructure\Logging;

use App\IdentityAccess\Application\Contract\Clock;
use App\IdentityAccess\Application\Contract\SecurityEventLogger;
use JsonException;

final readonly class ErrorLogSecurityEventLogger implements SecurityEventLogger
{
    public function __construct(private Clock $clock)
    {
    }

    public function record(string $event): void
    {
        try {
            $message = json_encode(
                [
                    'category' => 'security',
                    'event' => $event,
                    'occurred_at' => $this->clock->now()->format(DATE_ATOM),
                ],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException) {
            $message = '{"category":"security","event":"logging.failure"}';
        }

        error_log($message);
    }
}
