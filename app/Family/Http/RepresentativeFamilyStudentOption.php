<?php

declare(strict_types=1);

namespace App\Family\Http;

final readonly class RepresentativeFamilyStudentOption
{
    public function __construct(
        public int $studentId,
        public string $displayName,
    ) {
    }
}
