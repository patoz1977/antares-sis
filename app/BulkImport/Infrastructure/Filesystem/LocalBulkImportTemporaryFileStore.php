<?php

declare(strict_types=1);

namespace App\BulkImport\Infrastructure\Filesystem;

use App\BulkImport\Http\BulkImportTemporaryFileStore;
use App\BulkImport\Http\BulkImportUploadRejected;
use Closure;

final class LocalBulkImportTemporaryFileStore implements BulkImportTemporaryFileStore
{
    private const MAXIMUM_BYTES = 5 * 1024 * 1024;

    private Closure $moveUploadedFile;

    public function __construct(
        private readonly string $directory,
        private readonly string $publicDirectory,
        ?Closure $moveUploadedFile = null,
    ) {
        $this->moveUploadedFile = $moveUploadedFile
            ?? static fn (string $source, string $destination): bool => move_uploaded_file($source, $destination);
    }

    public function store(array $upload): string
    {
        $this->validateUpload($upload);
        $this->ensureDirectory();

        $source = (string) $upload['tmp_name'];
        $destination = $this->uniqueDestination();
        $move = $this->moveUploadedFile;
        if (!$move($source, $destination)) {
            throw new BulkImportUploadRejected('No se pudo recibir el archivo de forma segura.');
        }

        try {
            clearstatcache(true, $destination);
            $size = is_file($destination) ? filesize($destination) : false;
            if (!is_int($size) || $size < 1 || $size > self::MAXIMUM_BYTES) {
                throw new BulkImportUploadRejected('El archivo debe contener datos y no superar 5 MiB.');
            }
            @chmod($destination, 0600);

            return $destination;
        } catch (\Throwable $exception) {
            $this->delete($destination);
            throw $exception;
        }
    }

    public function delete(string $localPath): void
    {
        if (!is_file($localPath)) {
            return;
        }
        $base = realpath($this->directory);
        $candidate = realpath($localPath);
        if ($base === false || $candidate === false || !$this->isContained($candidate, $base)) {
            return;
        }

        @unlink($candidate);
    }

    /** @param array<string, mixed> $upload */
    private function validateUpload(array $upload): void
    {
        $error = $upload['error'] ?? null;
        if (!is_int($error) || $error !== UPLOAD_ERR_OK) {
            $message = match ($error) {
                UPLOAD_ERR_NO_FILE => 'Seleccione un archivo XLSX.',
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'El archivo supera el límite de 5 MiB.',
                default => 'El archivo no pudo cargarse de forma segura.',
            };
            throw new BulkImportUploadRejected($message);
        }
        $source = $upload['tmp_name'] ?? null;
        $size = $upload['size'] ?? null;
        if (!is_string($source) || $source === '' || !is_int($size) || $size < 1) {
            throw new BulkImportUploadRejected('El archivo cargado está vacío o no es válido.');
        }
        if ($size > self::MAXIMUM_BYTES) {
            throw new BulkImportUploadRejected('El archivo supera el límite de 5 MiB.');
        }
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory)
            && !mkdir($this->directory, 0700, true)
            && !is_dir($this->directory)) {
            throw new BulkImportUploadRejected('El almacenamiento temporal no está disponible.');
        }
        @chmod($this->directory, 0700);
        if (!is_writable($this->directory)) {
            throw new BulkImportUploadRejected('El almacenamiento temporal no está disponible.');
        }

        $base = realpath($this->directory);
        $public = realpath($this->publicDirectory);
        if ($base === false || ($public !== false && $this->isContained($base, $public))) {
            throw new BulkImportUploadRejected('El almacenamiento temporal no es seguro.');
        }
    }

    private function uniqueDestination(): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $path = $this->directory . DIRECTORY_SEPARATOR . bin2hex(random_bytes(32)) . '.xlsx';
            if (!file_exists($path)) {
                return $path;
            }
        }

        throw new BulkImportUploadRejected('No se pudo preparar el archivo temporal.');
    }

    private function isContained(string $candidate, string $directory): bool
    {
        $directory = rtrim(str_replace('\\', '/', $directory), '/') . '/';
        $candidate = str_replace('\\', '/', $candidate);

        return str_starts_with(strtolower($candidate . (is_dir($candidate) ? '/' : '')), strtolower($directory));
    }
}
