<?php

declare(strict_types=1);

namespace App\BulkImport\Application\Dto;

enum BulkImportIssueCategory: string
{
    case FileInvalid = 'FILE_INVALID';
    case StructureInvalid = 'STRUCTURE_INVALID';
    case RequiredValueMissing = 'REQUIRED_VALUE_MISSING';
    case ValueInvalid = 'VALUE_INVALID';
    case FamilyReferenceInvalid = 'FAMILY_REFERENCE_INVALID';
    case DuplicateInWorkbook = 'DUPLICATE_IN_WORKBOOK';
    case PrimaryRepresentativeConflict = 'PRIMARY_REPRESENTATIVE_CONFLICT';
}
