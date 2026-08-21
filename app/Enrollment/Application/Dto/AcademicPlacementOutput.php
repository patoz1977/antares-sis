<?php

declare(strict_types=1);

namespace App\Enrollment\Application\Dto;

final readonly class AcademicPlacementOutput
{
    public function __construct(public int $gradeId, public ?int $sectionId)
    {
    }
}
