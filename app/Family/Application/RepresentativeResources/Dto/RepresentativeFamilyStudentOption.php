<?php

declare(strict_types=1);

namespace App\Family\Application\RepresentativeResources\Dto;

final readonly class RepresentativeFamilyStudentOption
{
    public function __construct(
        public int $studentId,
        public string $displayName,
    ) {
    }
}
