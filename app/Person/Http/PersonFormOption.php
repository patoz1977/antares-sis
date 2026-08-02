<?php

declare(strict_types=1);

namespace App\Person\Http;

final readonly class PersonFormOption
{
    public function __construct(
        public int $id,
        public string $code,
        public string $name,
    ) {
    }
}
