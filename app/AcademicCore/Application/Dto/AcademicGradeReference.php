<?php

declare(strict_types=1);

namespace App\AcademicCore\Application\Dto;

final readonly class AcademicGradeReference
{
    public function __construct(
        public int $id,
        public string $code,
        public string $name,
        public string $status,
        public int $sortOrder,
    ) {
    }
}
