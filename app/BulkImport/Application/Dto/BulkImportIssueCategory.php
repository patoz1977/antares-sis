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
    case CatalogUnknown = 'CATALOG_UNKNOWN';
    case IdentityConflict = 'IDENTITY_CONFLICT';
    case FamilyMembershipConflict = 'FAMILY_MEMBERSHIP_CONFLICT';
    case ExistingDataConflict = 'EXISTING_DATA_CONFLICT';
    case ConcurrentChange = 'CONCURRENT_CHANGE';
    case ApplyFailed = 'APPLY_FAILED';
}
