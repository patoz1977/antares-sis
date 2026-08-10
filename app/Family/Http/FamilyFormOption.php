<?php

declare(strict_types=1);

namespace App\Family\Http;

final readonly class FamilyFormOption
{
    public function __construct(
        public int $id,
        public string $code,
        public string $name,
    ) {
    }
}
