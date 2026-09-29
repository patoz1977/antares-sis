<?php

declare(strict_types=1);

namespace App\Enrollment\Infrastructure\Filesystem;

use App\Enrollment\Http\AcademicInitializationTemporaryFileStore;
use App\Enrollment\Http\AcademicInitializationUploadRejected;
use Closure;

final class LocalAcademicInitializationTemporaryFileStore implements
    AcademicInitializationTemporaryFileStore
{
    private const MAXIMUM_BYTES = 1024 * 1024;

    private Closure $moveUploadedFile;

    public function __construct(
        private readonly string $directory,
        private readonly string $publicDirectory,
        ?Closure $moveUploadedFile = null,
    ) {
        $this->moveUploadedFile = $moveUploadedFile
            ?? static fn (string $source, string $destination): bool =>
                move_uploaded_file($source, $destination);
    }

    public function store(array $upload): string
    {
        $this->validateUpload($upload);
        $this->ensureDirectory();

        $source = (string) $upload['tmp_name'];
        $destination = $this->uniqueDestination();
        $move = $this->moveUploadedFile;
        if (!$move($source, $destination)) {
            throw new AcademicInitializationUploadRejected(
                'No se pudo recibir el manifest de forma segura.'
            );
        }

        try {
            clearstatcache(true, $destination);
            $size = is_file($destination) ? filesize($destination) : false;
            if (!is_int($size) || $size < 1 || $size > self::MAXIMUM_BYTES) {
                throw new AcademicInitializationUploadRejected(
                    'El manifest debe contener datos y no superar 1 MiB.'
                );
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
                UPLOAD_ERR_NO_FILE => 'Seleccione un manifest CSV.',
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'El manifest supera 1 MiB.',
                default => 'El manifest no pudo cargarse de forma segura.',
            };
            throw new AcademicInitializationUploadRejected($message);
        }
        $source = $upload['tmp_name'] ?? null;
        $size = $upload['size'] ?? null;
        $name = $upload['name'] ?? null;
        if (!is_string($source) || $source === '' || !is_file($source)
            || !is_int($size) || $size < 1
            || !is_string($name) || strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'csv'
        ) {
            throw new AcademicInitializationUploadRejected(
                'Seleccione un archivo CSV válido y no vacío.'
            );
        }
        if ($size > self::MAXIMUM_BYTES) {
            throw new AcademicInitializationUploadRejected('El manifest supera 1 MiB.');
        }
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory)
            && !mkdir($this->directory, 0700, true)
            && !is_dir($this->directory)
        ) {
            throw new AcademicInitializationUploadRejected(
                'El almacenamiento temporal no está disponible.'
            );
        }
        @chmod($this->directory, 0700);
        $base = realpath($this->directory);
        $public = realpath($this->publicDirectory);
        if ($base === false
            || !is_writable($base)
            || ($public !== false && $this->isContained($base, $public))
        ) {
            throw new AcademicInitializationUploadRejected(
                'El almacenamiento temporal no es seguro.'
            );
        }
    }

    private function uniqueDestination(): string
    {
        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $path = $this->directory . DIRECTORY_SEPARATOR . bin2hex(random_bytes(32)) . '.csv';
            if (!file_exists($path)) {
                return $path;
            }
        }

        throw new AcademicInitializationUploadRejected(
            'No se pudo preparar el archivo temporal.'
        );
    }

    private function isContained(string $candidate, string $directory): bool
    {
        $directory = rtrim(str_replace('\\', '/', $directory), '/') . '/';
        $candidate = str_replace('\\', '/', $candidate);

        return str_starts_with(
            strtolower($candidate . (is_dir($candidate) ? '/' : '')),
            strtolower($directory),
        );
    }
}
