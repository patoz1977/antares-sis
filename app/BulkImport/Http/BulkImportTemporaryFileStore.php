<?php

declare(strict_types=1);

namespace App\BulkImport\Http;

interface BulkImportTemporaryFileStore
{
    /** @param array<string, mixed> $upload */
    public function store(array $upload): string;

    public function delete(string $localPath): void;
}
