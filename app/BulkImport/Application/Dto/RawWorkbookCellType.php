<?php

declare(strict_types=1);

namespace App\BulkImport\Application\Dto;

enum RawWorkbookCellType
{
    case Empty;
    case String;
    case Numeric;
    case Boolean;
    case Date;
    case Formula;
    case Error;
}
