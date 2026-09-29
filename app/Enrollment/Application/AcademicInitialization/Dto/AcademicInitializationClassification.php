<?php

declare(strict_types=1);

namespace App\Enrollment\Application\AcademicInitialization\Dto;

enum AcademicInitializationClassification: string
{
    case CreateDraft = 'CREATE_DRAFT';
    case SetPlacement = 'SET_PLACEMENT';
    case AlreadyCorrect = 'ALREADY_CORRECT';
    case Conflict = 'CONFLICT';
}
