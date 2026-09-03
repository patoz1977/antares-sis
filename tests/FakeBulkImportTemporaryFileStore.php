<?php

declare(strict_types=1);

namespace Tests;

use App\BulkImport\Http\BulkImportTemporaryFileStore;
use App\BulkImport\Http\BulkImportUploadRejected;

final class FakeBulkImportTemporaryFileStore implements BulkImportTemporaryFileStore
{
    /** @var list<string> */
    public array $active = [];
    public int $stores = 0;
    public int $deletes = 0;
    public bool $reject = false;

    private string $directory;

    public function __construct()
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'antares-e015-delivery-' . bin2hex(random_bytes(8));
        if (!mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Unable to create Bulk Import Delivery fixture directory.');
        }
    }

    public function __destruct()
    {
        foreach ($this->active as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        if (is_dir($this->directory)) {
            @rmdir($this->directory);
        }
    }

    public function store(array $upload): string
    {
        $this->stores++;
        if ($this->reject) {
            throw new BulkImportUploadRejected('El archivo no pudo cargarse de forma segura.');
        }
        $source = $upload['tmp_name'] ?? null;
        if (!is_string($source) || !is_file($source)) {
            throw new BulkImportUploadRejected('Seleccione un archivo XLSX.');
        }
        $path = $this->directory . DIRECTORY_SEPARATOR . bin2hex(random_bytes(12)) . '.xlsx';
        if (!copy($source, $path)) {
            throw new \RuntimeException('Unable to copy Bulk Import Delivery fixture.');
        }
        $this->active[] = $path;

        return $path;
    }

    public function delete(string $localPath): void
    {
        $this->deletes++;
        if (is_file($localPath)) {
            unlink($localPath);
        }
        $this->active = array_values(array_filter(
            $this->active,
            static fn (string $path): bool => $path !== $localPath,
        ));
    }
}
