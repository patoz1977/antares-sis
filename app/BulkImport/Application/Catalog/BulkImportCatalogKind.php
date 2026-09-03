<?php

declare(strict_types=1);

namespace App\BulkImport\Application\Catalog;

enum BulkImportCatalogKind: string
{
    case Sex = 'SEX';
    case DocumentType = 'DOCUMENT_TYPE';
    case RelationshipType = 'RELATIONSHIP_TYPE';
}
