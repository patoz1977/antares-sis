<?php

declare(strict_types=1);

namespace Tests;

use App\BulkImport\Application\Catalog\BulkImportCatalogKind;
use App\BulkImport\Application\Catalog\BulkImportCatalogResolver;

final class FakeBulkImportCatalogResolver implements BulkImportCatalogResolver
{
    public function findActiveCatalogId(
        BulkImportCatalogKind $kind,
        string $code,
        bool $forUpdate = false,
    ): ?int {
        return match ($kind->value . ':' . trim($code)) {
            'SEX:FEMALE' => 1,
            'SEX:MALE' => 2,
            'DOCUMENT_TYPE:DNI' => 1,
            'RELATIONSHIP_TYPE:PARENT' => 11,
            default => null,
        };
    }

    public function findActiveStatusId(
        string $statusTypeCode,
        string $statusCode,
        bool $forUpdate = false,
    ): ?int {
        return match ($statusTypeCode . ':' . $statusCode) {
            'GENERAL_STATUS:ACTIVE' => 1,
            'USER_STATUS:ACTIVE' => 2,
            default => null,
        };
    }
}
