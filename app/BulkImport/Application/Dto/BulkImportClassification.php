<?php

declare(strict_types=1);

namespace App\BulkImport\Application\Dto;

enum BulkImportClassification: string
{
    case New = 'NEW';
    case AlreadyExists = 'ALREADY_EXISTS';
    case Conflict = 'CONFLICT';
}
