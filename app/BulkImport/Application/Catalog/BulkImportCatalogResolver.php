<?php

declare(strict_types=1);

namespace App\BulkImport\Application\Catalog;

interface BulkImportCatalogResolver
{
    public function findActiveCatalogId(
        BulkImportCatalogKind $kind,
        string $code,
        bool $forUpdate = false,
    ): ?int;

    public function findActiveStatusId(
        string $statusTypeCode,
        string $statusCode,
        bool $forUpdate = false,
    ): ?int;
}
