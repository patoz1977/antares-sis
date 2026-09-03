<?php

declare(strict_types=1);

namespace App\BulkImport\Application\Delivery;

final readonly class BulkImportPreviewGrant
{
    public function __construct(public string $digest)
    {
    }
}
